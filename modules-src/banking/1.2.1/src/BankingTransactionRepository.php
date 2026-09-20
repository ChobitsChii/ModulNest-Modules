<?php

declare(strict_types=1);

namespace ModulNest\Banking;

use PDO;

final class BankingTransactionRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function hasNativeTables(): bool
    {
        return $this->tableExists('banking_transactions');
    }

    /**
     * @return array<int, int>
     */
    public function availableYears(int $userId): array
    {
        if (!$this->hasNativeTables()) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT DISTINCT YEAR(booking_date) AS year_value
             FROM banking_transactions
             WHERE user_id = :user_id
             ORDER BY year_value DESC'
        );
        $statement->execute(['user_id' => $userId]);

        return array_values(array_filter(
            array_map(static fn (array $row): int => (int) ($row['year_value'] ?? 0), $statement->fetchAll(PDO::FETCH_ASSOC) ?: []),
            static fn (int $year): bool => $year > 0
        ));
    }

    /**
     * @param array{year:int|null,status:string,booking_text?:string} $filters
     * @return array<int, array{text:string,count:int}>
     */
    public function availableBookingTexts(int $userId, array $filters): array
    {
        if (!$this->hasNativeTables()) {
            return [];
        }

        [$where, $params] = $this->transactionFilterWhere($userId, [
            'year' => $filters['year'] ?? null,
            'status' => (string) ($filters['status'] ?? 'all'),
            'booking_text' => 'all',
        ]);
        $where[] = "COALESCE(NULLIF(TRIM(booking_text), ''), '') <> ''";

        $statement = $this->pdo->prepare(
            'SELECT booking_text AS text_value, COUNT(*) AS count_rows
             FROM banking_transactions
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY booking_text
             HAVING count_rows > 0
             ORDER BY booking_text ASC'
        );
        $statement->execute($params);

        return array_map(
            static fn (array $row): array => [
                'text' => (string) ($row['text_value'] ?? ''),
                'count' => (int) ($row['count_rows'] ?? 0),
            ],
            $statement->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @return array<int, string>
     */
    public function availableYearMonths(int $userId): array
    {
        if (!$this->hasNativeTables()) {
            return [];
        }

        $statement = $this->pdo->prepare(
            "SELECT DISTINCT DATE_FORMAT(booking_date, '%Y-%m') AS month_value
             FROM banking_transactions
             WHERE user_id = :user_id
             ORDER BY month_value DESC"
        );
        $statement->execute(['user_id' => $userId]);

        return array_values(array_filter(
            array_map(static fn (array $row): string => (string) ($row['month_value'] ?? ''), $statement->fetchAll(PDO::FETCH_ASSOC) ?: []),
            static fn (string $month): bool => preg_match('/^\d{4}-\d{2}$/', $month) === 1
        ));
    }

    /**
     * @return array{total:int,booked:int,pending:int}
     */
    public function countSummary(int $userId): array
    {
        if (!$this->hasNativeTables()) {
            return ['total' => 0, 'booked' => 0, 'pending' => 0];
        }

        $statement = $this->pdo->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN booking_status = 'gebucht' THEN 1 ELSE 0 END) AS booked,
                SUM(CASE WHEN booking_status = 'vorgemerkt' THEN 1 ELSE 0 END) AS pending
             FROM banking_transactions
             WHERE user_id = :user_id"
        );
        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'booked' => (int) ($row['booked'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
        ];
    }

    /**
     * @return array{income:float,expenses:float,balance:float}
     */
    public function moneySummary(int $userId, ?string $fromDate = null, ?string $toDate = null): array
    {
        if (!$this->hasNativeTables()) {
            return ['income' => 0.0, 'expenses' => 0.0, 'balance' => 0.0];
        }

        $where = ['user_id = :user_id'];
        $params = ['user_id' => $userId];
        if ($fromDate !== null) {
            $where[] = 'booking_date >= :from_date';
            $params['from_date'] = $fromDate;
        }
        if ($toDate !== null) {
            $where[] = 'booking_date <= :to_date';
            $params['to_date'] = $toDate;
        }

        $statement = $this->pdo->prepare(
            'SELECT
                COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) AS income,
                COALESCE(SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END), 0) AS expenses,
                COALESCE(SUM(amount), 0) AS balance
             FROM banking_transactions
             WHERE ' . implode(' AND ', $where)
        );
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'income' => (float) ($row['income'] ?? 0),
            'expenses' => (float) ($row['expenses'] ?? 0),
            'balance' => (float) ($row['balance'] ?? 0),
        ];
    }

    /**
     * @param array{year:int|null,status:string,booking_text?:string} $filters
     * @return array{income:float,expenses:float,balance:float,count:int}
     */
    public function filteredSummary(int $userId, array $filters): array
    {
        if (!$this->hasNativeTables()) {
            return ['income' => 0.0, 'expenses' => 0.0, 'balance' => 0.0, 'count' => 0];
        }

        [$where, $params] = $this->transactionFilterWhere($userId, $filters);
        $statement = $this->pdo->prepare(
            'SELECT
                COUNT(*) AS count_rows,
                COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) AS income,
                COALESCE(SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END), 0) AS expenses,
                COALESCE(SUM(amount), 0) AS balance
             FROM banking_transactions
             WHERE ' . implode(' AND ', $where)
        );
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'income' => (float) ($row['income'] ?? 0),
            'expenses' => (float) ($row['expenses'] ?? 0),
            'balance' => (float) ($row['balance'] ?? 0),
            'count' => (int) ($row['count_rows'] ?? 0),
        ];
    }

    /**
     * @param array{year:int|null,status:string,booking_text?:string} $filters
     * @return array<int, array<string, mixed>>
     */
    public function transactionsForList(int $userId, array $filters, int $limit = 500): array
    {
        if (!$this->hasNativeTables()) {
            return [];
        }

        $limit = max(1, min(1000, $limit));
        [$where, $params] = $this->transactionFilterWhere($userId, $filters);
        $statement = $this->pdo->prepare(
            'SELECT
                id,
                booking_date,
                value_date,
                booking_text,
                purpose,
                counterparty_name,
                legacy_category_name,
                amount,
                currency,
                booking_status
             FROM banking_transactions
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY
                CASE WHEN booking_status = \'vorgemerkt\' THEN 0 ELSE 1 END ASC,
                value_date DESC,
                booking_date DESC,
                id DESC
             LIMIT ' . $limit
        );
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array{year:int|null,status:string,booking_text?:string} $filters
     * @return array<int, array<string, mixed>>
     */
    public function transactionsForDuplicateDetection(int $userId, array $filters): array
    {
        if (!$this->hasNativeTables()) {
            return [];
        }

        [$where, $params] = $this->transactionFilterWhere($userId, $filters);
        $statement = $this->pdo->prepare(
            'SELECT
                t.id,
                a.account_identifier,
                t.booking_date,
                t.value_date,
                t.booking_text,
                t.purpose,
                t.counterparty_name,
                t.counterparty_iban,
                t.counterparty_bic,
                t.amount,
                t.currency,
                t.raw_info,
                t.legacy_category_name,
                t.booking_status,
                t.created_at
             FROM banking_transactions t
             LEFT JOIN banking_accounts a
               ON a.id = t.account_id
              AND a.user_id = t.user_id
             WHERE ' . implode(' AND ', array_map(
                static fn (string $condition): string => str_starts_with($condition, 'booking_') || str_starts_with($condition, 'user_id') ? 't.' . $condition : $condition,
                $where
            )) . '
             ORDER BY t.booking_date ASC, t.value_date ASC, t.id ASC'
        );
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<int, int> $ids
     */
    public function deleteTransactionsForUser(int $userId, array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === [] || !$this->hasNativeTables()) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(
            'DELETE FROM banking_transactions
             WHERE user_id = ?
               AND id IN (' . $placeholders . ')'
        );
        $statement->execute(array_merge([$userId], $ids));

        return $statement->rowCount();
    }

    /**
     * @return array<int, array{month:string,income:float,expenses:float,balance:float,count:int}>
     */
    public function monthlySummary(int $userId, ?int $year): array
    {
        if (!$this->hasNativeTables()) {
            return [];
        }

        $where = ['user_id = :user_id'];
        $params = ['user_id' => $userId];
        if ($year !== null && $year > 0) {
            $where[] = 'booking_date >= :from_date';
            $where[] = 'booking_date <= :to_date';
            $params['from_date'] = sprintf('%04d-01-01', $year);
            $params['to_date'] = sprintf('%04d-12-31', $year);
        }

        $statement = $this->pdo->prepare(
            "SELECT
                DATE_FORMAT(booking_date, '%Y-%m') AS month_value,
                COUNT(*) AS count_rows,
                COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) AS income,
                COALESCE(SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END), 0) AS expenses,
                COALESCE(SUM(amount), 0) AS balance
             FROM banking_transactions
             WHERE " . implode(' AND ', $where) . "
             GROUP BY month_value
             ORDER BY month_value DESC"
        );
        $statement->execute($params);

        return array_map(
            static fn (array $row): array => [
                'month' => (string) ($row['month_value'] ?? ''),
                'income' => (float) ($row['income'] ?? 0),
                'expenses' => (float) ($row['expenses'] ?? 0),
                'balance' => (float) ($row['balance'] ?? 0),
                'count' => (int) ($row['count_rows'] ?? 0),
            ],
            $statement->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function transactionsForRecurringMatching(int $userId): array
    {
        if (!$this->hasNativeTables()) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT
                t.id,
                t.booking_date,
                t.value_date,
                t.booking_text,
                t.purpose,
                t.counterparty_name,
                t.counterparty_iban,
                t.counterparty_bic,
                t.amount,
                t.currency,
                t.raw_info,
                t.legacy_category_name,
                t.booking_status,
                t.created_at,
                a.account_identifier
             FROM banking_transactions t
             LEFT JOIN banking_accounts a
               ON a.id = t.account_id
              AND a.user_id = t.user_id
             WHERE t.user_id = :user_id
             ORDER BY t.booking_date DESC, t.id DESC'
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function latestTransactions(int $userId, int $limit = 8): array
    {
        if (!$this->hasNativeTables()) {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $statement = $this->pdo->prepare(
            'SELECT
                t.id,
                t.booking_date,
                t.booking_text,
                t.purpose,
                t.counterparty_name,
                t.amount,
                t.currency,
                t.booking_status,
                COALESCE(c.name, t.legacy_category_name) AS category_name
             FROM banking_transactions t
             LEFT JOIN banking_categories c
               ON c.id = t.category_id
              AND c.user_id = t.user_id
             WHERE t.user_id = :user_id
             ORDER BY t.booking_date DESC, t.id DESC
             LIMIT ' . $limit
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<int, array{name:string,count_rows:int,total:float}>
     */
    public function topExpenseCategories(int $userId, int $limit = 8): array
    {
        if (!$this->hasNativeTables()) {
            return [];
        }

        $limit = max(1, min(30, $limit));
        $statement = $this->pdo->prepare(
            'SELECT
                COALESCE(NULLIF(TRIM(c.name), \'\'), NULLIF(TRIM(t.legacy_category_name), \'\'), \'Ohne Kategorie\') AS name,
                COUNT(*) AS count_rows,
                ABS(COALESCE(SUM(t.amount), 0)) AS total
             FROM banking_transactions t
             LEFT JOIN banking_categories c
               ON c.id = t.category_id
              AND c.user_id = t.user_id
             WHERE t.user_id = :user_id
               AND t.amount < 0
             GROUP BY COALESCE(NULLIF(TRIM(c.name), \'\'), NULLIF(TRIM(t.legacy_category_name), \'\'), \'Ohne Kategorie\')
             ORDER BY total DESC, count_rows DESC
             LIMIT ' . $limit
        );
        $statement->execute(['user_id' => $userId]);

        return array_map(
            static fn (array $row): array => [
                'name' => (string) ($row['name'] ?? 'Ohne Kategorie'),
                'count_rows' => (int) ($row['count_rows'] ?? 0),
                'total' => (float) ($row['total'] ?? 0),
            ],
            $statement->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table'
        );
        $statement->execute(['table' => $table]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * @param array{year:int|null,status:string,booking_text?:string} $filters
     * @return array{0:array<int, string>,1:array<string, int|string>}
     */
    private function transactionFilterWhere(int $userId, array $filters): array
    {
        $where = ['user_id = :user_id'];
        $params = ['user_id' => $userId];

        $year = $filters['year'] ?? null;
        if (is_int($year) && $year > 0) {
            $where[] = 'booking_date >= :year_from';
            $where[] = 'booking_date <= :year_to';
            $params['year_from'] = sprintf('%04d-01-01', $year);
            $params['year_to'] = sprintf('%04d-12-31', $year);
        }

        $status = (string) ($filters['status'] ?? 'all');
        if (in_array($status, ['gebucht', 'vorgemerkt'], true)) {
            $where[] = 'booking_status = :status';
            $params['status'] = $status;
        }

        $bookingText = trim((string) ($filters['booking_text'] ?? 'all'));
        if ($bookingText !== '' && $bookingText !== 'all') {
            $where[] = 'booking_text = :booking_text';
            $params['booking_text'] = $bookingText;
        }

        return [$where, $params];
    }
}
