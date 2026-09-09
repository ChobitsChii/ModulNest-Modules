<?php

declare(strict_types=1);

namespace ModulNest\Banking;

use PDO;
use RuntimeException;
use Throwable;

final class BankingCsvImportService
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function maxUploadSizeBytes(): ?int
    {
        $limits = array_values(array_filter([
            $this->iniBytes((string) ini_get('upload_max_filesize')),
            $this->iniBytes((string) ini_get('post_max_size')),
        ], static fn (?int $value): bool => $value !== null && $value > 0));

        return $limits === [] ? null : min($limits);
    }

    public function maxUploadSizeLabel(): string
    {
        $bytes = $this->maxUploadSizeBytes();
        if ($bytes === null) {
            return 'PHP-Upload-Limit';
        }

        if ($bytes >= 1048576) {
            return rtrim(rtrim(number_format($bytes / 1048576, 1, ',', '.'), '0'), ',') . ' MB';
        }

        return number_format((int) ceil($bytes / 1024), 0, ',', '.') . ' KB';
    }

    /**
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    public function importForUser(int $userId, array $file): array
    {
        $validation = $this->validateFile($file);
        if ($validation !== null) {
            return $this->result(false, errors: [$validation]);
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        $originalName = basename((string) ($file['name'] ?? 'import.csv'));
        $rows = $this->readCsvRows($tmpName);
        if (!$rows['success']) {
            return $this->result(false, errors: $rows['errors']);
        }

        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $batchId = null;

        try {
            $this->pdo->beginTransaction();
            $batchId = $this->createBatch($userId, $originalName, hash_file('sha256', $tmpName) ?: null);

            foreach ($rows['rows'] as $index => $row) {
                try {
                    $normalized = $this->normalizeRow($row);
                    if ($normalized === null) {
                        $skipped++;
                        continue;
                    }

                    $accountId = $this->ensureAccount($userId, (string) $normalized['account_identifier'], (string) $normalized['currency']);
                    $categoryId = $this->ensureCategory($userId, (string) $normalized['legacy_category_name']);
                    $normalized['account_id'] = $accountId;
                    $normalized['category_id'] = $categoryId;
                    $normalized['import_batch_id'] = $batchId;

                    $existing = $this->findByHash($userId, (string) $normalized['transaction_hash']);
                    if ($existing !== null) {
                        $normalizedForUpdate = $this->mergeExistingImportValues($existing, $normalized);
                        if ($this->transactionNeedsUpdate($existing, $normalizedForUpdate) && $this->updateTransaction((int) $existing['id'], $userId, $normalizedForUpdate)) {
                            $updated++;
                        } else {
                            $skipped++;
                        }
                        continue;
                    }

                    $existing = $this->findExistingImportDuplicate($userId, $normalized);
                    if ($existing !== null) {
                        $normalizedForUpdate = $this->mergeExistingImportValues($existing, $normalized);
                        if ($this->transactionNeedsUpdate($existing, $normalizedForUpdate) && $this->updateTransaction((int) $existing['id'], $userId, $normalizedForUpdate)) {
                            $updated++;
                        } else {
                            $skipped++;
                        }
                        continue;
                    }

                    $settlementMatch = $this->findStatusTransitionDuplicate($userId, $normalized);
                    if ($settlementMatch !== null) {
                        if (($settlementMatch['booking_status'] ?? '') === 'gebucht' && $normalized['booking_status'] === 'vorgemerkt') {
                            $skipped++;
                            continue;
                        }

                        $normalizedForUpdate = $this->mergeExistingImportValues($settlementMatch, $normalized);
                        if ($this->transactionNeedsUpdate($settlementMatch, $normalizedForUpdate) && $this->updateTransaction((int) $settlementMatch['id'], $userId, $normalizedForUpdate)) {
                            $updated++;
                        } else {
                            $skipped++;
                        }
                        continue;
                    }

                    if ($normalized['booking_status'] === 'gebucht') {
                        $pending = $this->findPendingReplacement($userId, $normalized);
                        if ($pending !== null) {
                            $this->updateTransaction((int) $pending['id'], $userId, $this->mergeExistingImportValues($pending, $normalized));
                            $updated++;
                            continue;
                        }
                    }

                    $this->insertTransaction($userId, $normalized);
                    $imported++;
                } catch (Throwable $exception) {
                    $errors[] = 'Zeile ' . ((int) $index + 2) . ': ' . $exception->getMessage();
                }
            }

            $this->finishBatch($batchId, 'completed', $imported, $updated, $skipped, count($errors), $errors);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            return $this->result(false, errors: ['Import abgebrochen: ' . $exception->getMessage()]);
        }

        return $this->result(count($errors) === 0, $batchId, $imported, $updated, $skipped, $errors);
    }

    /**
     * @param array<string, mixed> $file
     */
    private function validateFile(array $file): ?string
    {
        if ($file === [] || !isset($file['tmp_name'])) {
            return 'Keine CSV-Datei übertragen.';
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_OK);
        if ($error !== UPLOAD_ERR_OK) {
            return 'Upload fehlgeschlagen (Code ' . $error . ').';
        }

        $size = (int) ($file['size'] ?? 0);
        $maxSize = $this->maxUploadSizeBytes();
        if ($size <= 0) {
            return 'Die CSV-Datei muss lesbar sein.';
        }
        if ($maxSize !== null && $size > $maxSize) {
            return 'Die CSV-Datei ist größer als das aktuelle Uploadlimit (' . $this->maxUploadSizeLabel() . ').';
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName) || !is_readable($tmpName)) {
            return 'Die hochgeladene CSV-Datei ist nicht lesbar.';
        }

        $extension = mb_strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION), 'UTF-8');
        if ($extension !== 'csv') {
            return 'Bitte eine Datei mit der Endung .csv hochladen.';
        }

        return null;
    }

    private function iniBytes(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $unit = strtolower(substr($value, -1));
        $number = trim($unit !== '' && ctype_alpha($unit) ? substr($value, 0, -1) : $value);
        if (!is_numeric($number)) {
            return null;
        }

        $bytes = (float) $number;
        return match ($unit) {
            'g' => (int) ($bytes * 1024 * 1024 * 1024),
            'm' => (int) ($bytes * 1024 * 1024),
            'k' => (int) ($bytes * 1024),
            default => (int) $bytes,
        };
    }

    /**
     * @return array{success:bool,rows:array<int, array<string, string>>,errors:array<int, string>}
     */
    private function readCsvRows(string $tmpName): array
    {
        $handle = fopen($tmpName, 'rb');
        if (!is_resource($handle)) {
            return ['success' => false, 'rows' => [], 'errors' => ['CSV-Datei konnte nicht geöffnet werden.']];
        }

        $header = fgetcsv($handle, 0, ';', '"');
        if (!is_array($header)) {
            fclose($handle);
            return ['success' => false, 'rows' => [], 'errors' => ['CSV-Kopfzeile fehlt.']];
        }

        $header = array_map(fn (mixed $value): string => $this->normalizeHeader($this->toUtf8((string) $value)), $header);
        $required = [
            'auftragskonto',
            'buchungstag',
            'valutadatum',
            'buchungstext',
            'verwendungszweck',
            'beguenstigter/zahlungspflichtiger',
            'kontonummer/iban',
            'bic (swift-code)',
            'betrag',
            'waehrung',
            'info',
        ];
        $missing = array_values(array_diff($required, $header));
        if ($missing !== []) {
            fclose($handle);
            return ['success' => false, 'rows' => [], 'errors' => ['CSV-Spalten fehlen: ' . implode(', ', $missing)]];
        }

        $rows = [];
        while (($line = fgetcsv($handle, 0, ';', '"')) !== false) {
            $line = array_map(fn (mixed $value): string => $this->toUtf8((string) $value), $line);
            $assoc = [];
            foreach ($header as $index => $name) {
                $assoc[$name] = trim($line[$index] ?? '');
            }
            if (implode('', $assoc) === '') {
                continue;
            }
            $rows[] = $assoc;
        }
        fclose($handle);

        return ['success' => true, 'rows' => $rows, 'errors' => []];
    }

    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
        $header = mb_strtolower(trim($header), 'UTF-8');
        $header = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $header);
        $header = preg_replace('/\s*\/\s*/', '/', $header) ?: $header;

        return preg_replace('/\s+/', ' ', $header) ?: $header;
    }

    private function toUtf8(string $value): string
    {
        if (function_exists('mb_check_encoding') && mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
        }

        return iconv('ISO-8859-1', 'UTF-8//IGNORE', $value) ?: $value;
    }

    /**
     * @param array<string, string> $row
     * @return null|array<string, mixed>
     */
    private function normalizeRow(array $row): ?array
    {
        $bookingDate = $this->parseDate($row['buchungstag'] ?? '');
        $valueDate = $this->parseDate($row['valutadatum'] ?? '');
        $amount = $this->parseAmount($row['betrag'] ?? '');
        if ($bookingDate === null || $amount === null) {
            throw new RuntimeException('Buchungstag oder Betrag ist ungültig.');
        }

        $account = trim($row['auftragskonto'] ?? '');
        if ($account === '') {
            $account = 'CSV-Import';
        }
        $currency = strtoupper(trim($row['waehrung'] ?? 'EUR'));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'EUR';
        }

        $bookingText = trim($row['buchungstext'] ?? '');
        $purpose = trim($row['verwendungszweck'] ?? '');
        $counterparty = trim($row['beguenstigter/zahlungspflichtiger'] ?? '');
        $iban = trim($row['kontonummer/iban'] ?? '');
        $bic = trim($row['bic (swift-code)'] ?? '');
        $info = trim($row['info'] ?? '');
        $category = trim($row['kategorie'] ?? '');
        $status = str_contains(mb_strtolower($info, 'UTF-8'), 'vorgemerkt') ? 'vorgemerkt' : 'gebucht';

        $normalized = [
            'account_identifier' => $account,
            'booking_date' => $bookingDate,
            'value_date' => $valueDate,
            'booking_text' => $bookingText,
            'purpose' => $purpose,
            'counterparty_name' => $counterparty,
            'counterparty_iban' => $iban,
            'counterparty_bic' => $bic,
            'amount' => $amount,
            'currency' => $currency,
            'raw_info' => $info,
            'legacy_category_name' => $category,
            'booking_status' => $status,
        ];
        $normalized['transaction_hash'] = hash('sha256', $this->buildImportDuplicateKey($normalized));

        return $normalized;
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})$/', $value, $matches) === 1) {
            $year = (int) $matches[3];
            if ($year < 100) {
                $year += $year >= 70 ? 1900 : 2000;
            }

            return sprintf('%04d-%02d-%02d', $year, (int) $matches[2], (int) $matches[1]);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value;
        }

        return null;
    }

    private function parseAmount(string $value): ?float
    {
        $normalized = trim(str_replace([' ', "\xc2\xa0"], '', $value));
        if ($normalized === '') {
            return null;
        }
        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif (str_contains($normalized, ',')) {
            $normalized = str_replace(',', '.', $normalized);
        }
        if (!is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, 2);
    }

    private function createBatch(int $userId, string $filename, ?string $sha256): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO banking_import_batches
                (user_id, source_type, original_filename, file_sha256, status, started_at)
             VALUES
                (:user_id, 'csv', :filename, :sha256, 'running', NOW())"
        );
        $statement->execute([
            'user_id' => $userId,
            'filename' => mb_substr($filename, 0, 255, 'UTF-8'),
            'sha256' => $sha256,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<int, string> $errors
     */
    private function finishBatch(int $batchId, string $status, int $imported, int $updated, int $skipped, int $errorCount, array $errors): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE banking_import_batches
             SET status = :status,
                 imported_count = :imported,
                 updated_count = :updated,
                 skipped_count = :skipped,
                 error_count = :error_count,
                 error_summary = :error_summary,
                 finished_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            'status' => $status,
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'error_count' => $errorCount,
            'error_summary' => $errors === [] ? null : implode("\n", array_slice($errors, 0, 20)),
            'id' => $batchId,
        ]);
    }

    private function ensureAccount(int $userId, string $identifier, string $currency): int
    {
        $statement = $this->pdo->prepare('SELECT id FROM banking_accounts WHERE user_id = :user_id AND account_identifier = :identifier');
        $statement->execute(['user_id' => $userId, 'identifier' => $identifier]);
        $id = $statement->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO banking_accounts (user_id, legacy_account_key, account_identifier, display_name, currency)
             VALUES (:user_id, :legacy_account_key, :identifier, :display_name, :currency)'
        );
        $statement->execute([
            'user_id' => $userId,
            'legacy_account_key' => $identifier,
            'identifier' => $identifier,
            'display_name' => mb_substr($identifier, 0, 120, 'UTF-8'),
            'currency' => $currency,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function ensureCategory(int $userId, string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $normalized = mb_strtolower($name, 'UTF-8');
        $statement = $this->pdo->prepare('SELECT id FROM banking_categories WHERE user_id = :user_id AND normalized_name = :normalized');
        $statement->execute(['user_id' => $userId, 'normalized' => $normalized]);
        $id = $statement->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO banking_categories (user_id, name, normalized_name)
             VALUES (:user_id, :name, :normalized)'
        );
        $statement->execute([
            'user_id' => $userId,
            'name' => mb_substr($name, 0, 120, 'UTF-8'),
            'normalized' => mb_substr($normalized, 0, 120, 'UTF-8'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return null|array<string, mixed>
     */
    private function findByHash(int $userId, string $hash): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM banking_transactions WHERE user_id = :user_id AND transaction_hash = :hash LIMIT 1');
        $statement->execute(['user_id' => $userId, 'hash' => $hash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Find semantic duplicates that were imported with an older or otherwise different hash.
     *
     * Sparkassen exports overlap by design. The historical hash alone is not stable enough
     * across all export variants, so the import additionally uses the same normalized core
     * fields as the exact duplicate detection.
     *
     * @param array<string, mixed> $row
     * @return null|array<string, mixed>
     */
    private function findExistingImportDuplicate(int $userId, array $row): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                t.*,
                a.account_identifier
             FROM banking_transactions t
             LEFT JOIN banking_accounts a
               ON a.id = t.account_id
              AND a.user_id = t.user_id
             WHERE t.user_id = :user_id
               AND t.booking_date = :booking_date
               AND t.amount = :amount
               AND t.currency = :currency
             ORDER BY t.id ASC'
        );
        $statement->execute([
            'user_id' => $userId,
            'booking_date' => $row['booking_date'],
            'amount' => $row['amount'],
            'currency' => $row['currency'],
        ]);

        $expectedKey = $this->buildImportDuplicateKey($row);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $candidate) {
            if ($this->buildImportDuplicateKey($candidate) === $expectedKey) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return null|array<string, mixed>
     */
    private function findStatusTransitionDuplicate(int $userId, array $row): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                t.*,
                a.account_identifier
             FROM banking_transactions t
             LEFT JOIN banking_accounts a
               ON a.id = t.account_id
              AND a.user_id = t.user_id
             WHERE t.user_id = :user_id
               AND t.amount = :amount
               AND t.currency = :currency
               AND t.booking_status <> :booking_status
               AND ABS(DATEDIFF(t.booking_date, :booking_date)) <= 7
             ORDER BY
                CASE WHEN t.booking_status = \'gebucht\' THEN 0 ELSE 1 END ASC,
                ABS(DATEDIFF(t.booking_date, :booking_date)) ASC,
                t.id DESC'
        );
        $statement->execute([
            'user_id' => $userId,
            'amount' => $row['amount'],
            'currency' => $row['currency'],
            'booking_status' => $row['booking_status'],
            'booking_date' => $row['booking_date'],
        ]);

        $expectedKey = $this->buildStatusTransitionKey($row);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $candidate) {
            if ($this->buildStatusTransitionKey($candidate) !== $expectedKey) {
                continue;
            }
            if (!$this->datesMatchForStatusTransition($candidate['value_date'] ?? null, $row['value_date'] ?? null)) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return null|array<string, mixed>
     */
    private function findPendingReplacement(int $userId, array $row): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT t.*
             FROM banking_transactions t
             LEFT JOIN banking_accounts a
               ON a.id = t.account_id
              AND a.user_id = t.user_id
             WHERE t.user_id = :user_id
               AND t.booking_status = 'vorgemerkt'
               AND t.amount = :amount
               AND t.currency = :currency
               AND ABS(DATEDIFF(t.booking_date, :booking_date)) <= 40
               AND (
                    NULLIF(t.counterparty_iban, '') = NULLIF(:iban, '')
                 OR NULLIF(a.account_identifier, '') = NULLIF(:account_identifier, '')
                 OR NULLIF(t.counterparty_name, '') = NULLIF(:counterparty_name, '')
               )
             ORDER BY ABS(DATEDIFF(t.booking_date, :booking_date)) ASC, t.id DESC
             LIMIT 1"
        );
        $statement->execute([
            'user_id' => $userId,
            'amount' => $row['amount'],
            'currency' => $row['currency'],
            'booking_date' => $row['booking_date'],
            'iban' => $row['counterparty_iban'],
            'account_identifier' => $row['account_identifier'],
            'counterparty_name' => $row['counterparty_name'],
        ]);
        $match = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($match) ? $match : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insertTransaction(int $userId, array $row): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO banking_transactions
                (user_id, account_id, category_id, import_batch_id, booking_date, value_date, booking_text, purpose,
                 counterparty_name, counterparty_iban, counterparty_bic, amount, currency, raw_info,
                 legacy_category_name, transaction_hash, booking_status)
             VALUES
                (:user_id, :account_id, :category_id, :import_batch_id, :booking_date, :value_date, :booking_text, :purpose,
                 :counterparty_name, :counterparty_iban, :counterparty_bic, :amount, :currency, :raw_info,
                 :legacy_category_name, :transaction_hash, :booking_status)'
        );
        $statement->execute($this->transactionParams($userId, $row));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function updateTransaction(int $id, int $userId, array $row): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE banking_transactions
             SET account_id = :account_id,
                 category_id = :category_id,
                 import_batch_id = :import_batch_id,
                 booking_date = :booking_date,
                 value_date = :value_date,
                 booking_text = :booking_text,
                 purpose = :purpose,
                 counterparty_name = :counterparty_name,
                 counterparty_iban = :counterparty_iban,
                 counterparty_bic = :counterparty_bic,
                 amount = :amount,
                 currency = :currency,
                 raw_info = :raw_info,
                 legacy_category_name = :legacy_category_name,
                 transaction_hash = :transaction_hash,
                 booking_status = :booking_status
             WHERE id = :id
               AND user_id = :user_id'
        );
        $params = $this->transactionParams($userId, $row);
        $params['id'] = $id;
        $statement->execute($params);

        return $statement->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mergeExistingImportValues(array $existing, array $row): array
    {
        $merged = $row;
        if (trim((string) ($row['legacy_category_name'] ?? '')) === '' && trim((string) ($existing['legacy_category_name'] ?? '')) !== '') {
            $merged['legacy_category_name'] = $existing['legacy_category_name'];
            $merged['category_id'] = $existing['category_id'] ?? null;
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $row
     */
    private function transactionNeedsUpdate(array $existing, array $row): bool
    {
        $columns = [
            'account_id',
            'category_id',
            'booking_date',
            'value_date',
            'booking_text',
            'purpose',
            'counterparty_name',
            'counterparty_iban',
            'counterparty_bic',
            'amount',
            'currency',
            'raw_info',
            'legacy_category_name',
            'transaction_hash',
            'booking_status',
        ];

        foreach ($columns as $column) {
            if ($this->normalizeComparable($existing[$column] ?? null) !== $this->normalizeComparable($row[$column] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeComparable(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_float($value) || is_int($value) || (is_string($value) && is_numeric($value))) {
            return number_format((float) $value, 2, '.', '');
        }

        return trim((string) $value);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function transactionParams(int $userId, array $row): array
    {
        return [
            'user_id' => $userId,
            'account_id' => $row['account_id'],
            'category_id' => $row['category_id'],
            'import_batch_id' => $row['import_batch_id'],
            'booking_date' => $row['booking_date'],
            'value_date' => $row['value_date'],
            'booking_text' => $row['booking_text'],
            'purpose' => $row['purpose'],
            'counterparty_name' => $row['counterparty_name'],
            'counterparty_iban' => $row['counterparty_iban'],
            'counterparty_bic' => $row['counterparty_bic'],
            'amount' => $row['amount'],
            'currency' => $row['currency'],
            'raw_info' => $row['raw_info'],
            'legacy_category_name' => $row['legacy_category_name'],
            'transaction_hash' => $row['transaction_hash'],
            'booking_status' => $row['booking_status'],
        ];
    }

    /**
     * @param array<string, mixed> $transaction
     */
    private function buildImportDuplicateKey(array $transaction): string
    {
        return implode('|', [
            $this->normalizeAlphaNumCompare((string) ($transaction['account_identifier'] ?? '')),
            $this->normalizeDateCompare($transaction['booking_date'] ?? null),
            $this->normalizeDateCompare($transaction['value_date'] ?? null),
            $this->normalizeAlphaNumCompare((string) ($transaction['booking_text'] ?? '')),
            $this->normalizeAlphaNumCompare((string) ($transaction['purpose'] ?? '')),
            $this->normalizeAlphaNumCompare((string) ($transaction['counterparty_name'] ?? '')),
            $this->normalizeAlphaNumCompare((string) ($transaction['counterparty_iban'] ?? '')),
            $this->normalizeAlphaNumCompare((string) ($transaction['counterparty_bic'] ?? '')),
            number_format((float) ($transaction['amount'] ?? 0), 2, '.', ''),
            $this->normalizeAlphaNumCompare((string) ($transaction['currency'] ?? '')),
        ]);
    }

    /**
     * @param array<string, mixed> $transaction
     */
    private function buildStatusTransitionKey(array $transaction): string
    {
        return implode('|', [
            $this->normalizeAlphaNumCompare((string) ($transaction['account_identifier'] ?? '')),
            $this->normalizeAlphaNumCompare((string) ($transaction['booking_text'] ?? '')),
            $this->normalizeAlphaNumCompare((string) ($transaction['purpose'] ?? '')),
            $this->normalizeAlphaNumCompare((string) ($transaction['counterparty_name'] ?? '')),
            $this->normalizeAlphaNumCompare((string) ($transaction['counterparty_iban'] ?? '')),
            $this->normalizeAlphaNumCompare((string) ($transaction['counterparty_bic'] ?? '')),
            number_format((float) ($transaction['amount'] ?? 0), 2, '.', ''),
            $this->normalizeAlphaNumCompare((string) ($transaction['currency'] ?? '')),
        ]);
    }

    private function datesMatchForStatusTransition(mixed $left, mixed $right): bool
    {
        $leftDate = $this->normalizeDateCompare($left);
        $rightDate = $this->normalizeDateCompare($right);

        return $leftDate === '' || $rightDate === '' || $leftDate === $rightDate;
    }

    private function normalizeCompareString(?string $value): string
    {
        $normalized = mb_strtolower(trim((string) $value), 'UTF-8');
        $normalized = strtr($normalized, [
            'ä' => 'ae',
            'ö' => 'oe',
            'ü' => 'ue',
            'ß' => 'ss',
        ]);

        return preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
    }

    private function normalizeAlphaNumCompare(?string $value): string
    {
        $normalized = $this->normalizeCompareString($value);
        return preg_replace('/[^a-z0-9]/u', '', $normalized) ?? $normalized;
    }

    private function normalizeDateCompare(mixed $date): string
    {
        $text = trim((string) $date);
        if ($text === '') {
            return '';
        }

        $timestamp = strtotime($text);
        return $timestamp !== false ? date('Y-m-d', $timestamp) : '';
    }

    /**
     * @param array<int, string> $errors
     * @return array<string, mixed>
     */
    private function result(bool $success, ?int $batchId = null, int $imported = 0, int $updated = 0, int $skipped = 0, array $errors = []): array
    {
        return [
            'success' => $success,
            'batch_id' => $batchId,
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'error_count' => count($errors),
            'errors' => $errors,
        ];
    }
}
