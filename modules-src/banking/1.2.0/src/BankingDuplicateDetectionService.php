<?php

declare(strict_types=1);

namespace ModulNest\Banking;

final class BankingDuplicateDetectionService
{
    /**
     * @param array<int, array<string, mixed>> $transactions
     * @return array{exact:array<int, array<string, mixed>>,fuzzy:array<int, array<string, mixed>>,exact_count:int,fuzzy_count:int,suggested_delete_ids:array<int, int>,protected_keep_ids:array<int, int>}
     */
    public function detect(array $transactions, string $keepMode = 'latest'): array
    {
        $keepMode = in_array($keepMode, ['latest', 'oldest'], true) ? $keepMode : 'latest';
        $rows = array_map(fn (array $row): array => $this->prepareRow($row), $transactions);

        $exactGroups = [];
        $exactDuplicateIds = [];
        $exactGroupIndex = 1;
        $buckets = [];
        foreach ($rows as $row) {
            $buckets[$this->buildDuplicateKey($row)][] = $row;
        }

        foreach ($buckets as $bucketRows) {
            if (count($bucketRows) < 2) {
                continue;
            }

            usort($bucketRows, fn (array $a, array $b): int => $this->compareRowsByKeepMode($a, $b, $keepMode));
            $keepRow = array_shift($bucketRows);
            if ($keepRow === null || $bucketRows === []) {
                continue;
            }

            $exactGroups[] = [
                'group_no' => $exactGroupIndex++,
                'type' => 'exact',
                'keep' => $keepRow,
                'delete_rows' => $bucketRows,
            ];
            $exactDuplicateIds[(int) ($keepRow['id'] ?? 0)] = true;
            foreach ($bucketRows as $duplicateRow) {
                $exactDuplicateIds[(int) ($duplicateRow['id'] ?? 0)] = true;
            }
        }

        $settlementGroups = [];
        $settlementDuplicateIds = [];
        $settlementGroupIndex = 1;
        $settlementBuckets = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0 && isset($exactDuplicateIds[$id])) {
                continue;
            }

            $settlementBuckets[$this->buildStatusTransitionKey($row)][] = $row;
        }

        foreach ($settlementBuckets as $bucketRows) {
            if (count($bucketRows) < 2) {
                continue;
            }

            $neighbors = [];
            $rowsById = [];
            foreach ($bucketRows as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $neighbors[$id] = $neighbors[$id] ?? [];
                $rowsById[$id] = $row;
            }

            $count = count($bucketRows);
            for ($i = 0; $i < $count; $i++) {
                $idI = (int) ($bucketRows[$i]['id'] ?? 0);
                for ($j = $i + 1; $j < $count; $j++) {
                    $idJ = (int) ($bucketRows[$j]['id'] ?? 0);
                    if ($idI <= 0 || $idJ <= 0 || !$this->isLikelyStatusTransitionDuplicate($bucketRows[$i], $bucketRows[$j])) {
                        continue;
                    }
                    $neighbors[$idI][$idJ] = true;
                    $neighbors[$idJ][$idI] = true;
                }
            }

            $visited = [];
            foreach (array_keys($neighbors) as $startId) {
                if (isset($visited[$startId]) || ($neighbors[$startId] ?? []) === []) {
                    continue;
                }

                $queue = [$startId];
                $componentRows = [];
                $visited[$startId] = true;
                while ($queue !== []) {
                    $current = array_shift($queue);
                    if (isset($rowsById[$current])) {
                        $componentRows[] = $rowsById[$current];
                    }
                    foreach (array_keys($neighbors[$current] ?? []) as $next) {
                        if (!isset($visited[$next])) {
                            $visited[$next] = true;
                            $queue[] = $next;
                        }
                    }
                }

                if (count($componentRows) < 2) {
                    continue;
                }

                usort($componentRows, fn (array $a, array $b): int => $this->compareRowsPreferBooked($a, $b, $keepMode));
                $keepRow = array_shift($componentRows);
                if ($keepRow === null || $componentRows === []) {
                    continue;
                }

                $settlementGroups[] = [
                    'group_no' => $settlementGroupIndex++,
                    'type' => 'settlement',
                    'keep' => $keepRow,
                    'delete_rows' => $componentRows,
                ];
                $settlementDuplicateIds[(int) ($keepRow['id'] ?? 0)] = true;
                foreach ($componentRows as $duplicateRow) {
                    $settlementDuplicateIds[(int) ($duplicateRow['id'] ?? 0)] = true;
                }
            }
        }

        $fuzzyBuckets = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0 && (isset($exactDuplicateIds[$id]) || isset($settlementDuplicateIds[$id]))) {
                continue;
            }

            $fuzzyBuckets[$this->buildFuzzyBucketKey($row)][] = $row;
        }

        $fuzzyGroups = [];
        $fuzzyGroupIndex = 1;
        foreach ($fuzzyBuckets as $bucketRows) {
            if (count($bucketRows) < 2) {
                continue;
            }

            usort($bucketRows, fn (array $a, array $b): int => $this->compareRowsByKeepMode($a, $b, $keepMode));
            $neighbors = [];
            $rowsById = [];
            foreach ($bucketRows as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $neighbors[$id] = $neighbors[$id] ?? [];
                $rowsById[$id] = $row;
            }

            $count = count($bucketRows);
            for ($i = 0; $i < $count; $i++) {
                $idI = (int) ($bucketRows[$i]['id'] ?? 0);
                for ($j = $i + 1; $j < $count; $j++) {
                    $idJ = (int) ($bucketRows[$j]['id'] ?? 0);
                    if ($idI <= 0 || $idJ <= 0 || !$this->isLikelyFuzzyDuplicate($bucketRows[$i], $bucketRows[$j])) {
                        continue;
                    }
                    $neighbors[$idI][$idJ] = true;
                    $neighbors[$idJ][$idI] = true;
                }
            }

            $visited = [];
            foreach (array_keys($neighbors) as $startId) {
                if (isset($visited[$startId]) || ($neighbors[$startId] ?? []) === []) {
                    continue;
                }

                $queue = [$startId];
                $componentRows = [];
                $visited[$startId] = true;
                while ($queue !== []) {
                    $current = array_shift($queue);
                    if (isset($rowsById[$current])) {
                        $componentRows[] = $rowsById[$current];
                    }
                    foreach (array_keys($neighbors[$current] ?? []) as $next) {
                        if (!isset($visited[$next])) {
                            $visited[$next] = true;
                            $queue[] = $next;
                        }
                    }
                }

                if (count($componentRows) < 2) {
                    continue;
                }

                usort($componentRows, fn (array $a, array $b): int => $this->compareRowsByKeepMode($a, $b, $keepMode));
                $keepRow = array_shift($componentRows);
                if ($keepRow === null || $componentRows === []) {
                    continue;
                }

                $fuzzyGroups[] = [
                    'group_no' => $fuzzyGroupIndex++,
                    'type' => 'fuzzy',
                    'keep' => $keepRow,
                    'delete_rows' => $componentRows,
                ];
            }
        }

        $protectedKeepIds = [];
        $suggestedDeleteIds = [];
        $fuzzyGroups = array_merge($settlementGroups, $fuzzyGroups);

        foreach (array_merge($exactGroups, $fuzzyGroups) as $group) {
            $keepId = (int) ($group['keep']['id'] ?? 0);
            if ($keepId > 0) {
                $protectedKeepIds[$keepId] = $keepId;
            }
            foreach ((array) ($group['delete_rows'] ?? []) as $deleteRow) {
                $deleteId = (int) ($deleteRow['id'] ?? 0);
                if ($deleteId > 0) {
                    $suggestedDeleteIds[$deleteId] = $deleteId;
                }
            }
        }

        return [
            'exact' => $exactGroups,
            'fuzzy' => $fuzzyGroups,
            'exact_count' => count($exactGroups),
            'fuzzy_count' => count($fuzzyGroups),
            'suggested_delete_ids' => array_values($suggestedDeleteIds),
            'protected_keep_ids' => array_values($protectedKeepIds),
        ];
    }

    private function prepareRow(array $row): array
    {
        $dateYmd = $this->transactionDateYmd($row);
        $timestamp = $dateYmd !== null ? strtotime($dateYmd) : null;
        if ($timestamp === false) {
            $timestamp = null;
        }

        $row['_date_ymd'] = $dateYmd;
        $row['_timestamp'] = $timestamp;

        return $row;
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

    private function transactionDateYmd(array $transaction): ?string
    {
        $date = $transaction['booking_date'] ?? $transaction['value_date'] ?? $transaction['created_at'] ?? null;
        if ($date === null || trim((string) $date) === '') {
            return null;
        }

        $timestamp = strtotime((string) $date);
        return $timestamp !== false ? date('Y-m-d', $timestamp) : null;
    }

    private function buildDuplicateKey(array $transaction): string
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

    private function buildFuzzyBucketKey(array $transaction): string
    {
        return implode('|', [
            $this->normalizeDateCompare($transaction['booking_date'] ?? null),
            $this->normalizeDateCompare($transaction['value_date'] ?? null),
            number_format((float) ($transaction['amount'] ?? 0), 2, '.', ''),
            $this->normalizeAlphaNumCompare((string) ($transaction['currency'] ?? '')),
            $this->normalizeCompareString((string) ($transaction['booking_status'] ?? '')),
        ]);
    }

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

    private function isLikelyStatusTransitionDuplicate(array $a, array $b): bool
    {
        if ($this->normalizeCompareString((string) ($a['booking_status'] ?? '')) === $this->normalizeCompareString((string) ($b['booking_status'] ?? ''))) {
            return false;
        }
        if ($this->buildStatusTransitionKey($a) !== $this->buildStatusTransitionKey($b)) {
            return false;
        }
        if (!$this->valueDatesMatchForStatusTransition($a['value_date'] ?? null, $b['value_date'] ?? null)) {
            return false;
        }

        $left = strtotime((string) ($a['booking_date'] ?? ''));
        $right = strtotime((string) ($b['booking_date'] ?? ''));
        if ($left === false || $right === false) {
            return false;
        }

        return abs($left - $right) <= 7 * 86400;
    }

    private function valueDatesMatchForStatusTransition(mixed $left, mixed $right): bool
    {
        $leftDate = $this->normalizeDateCompare($left);
        $rightDate = $this->normalizeDateCompare($right);

        return $leftDate === '' || $rightDate === '' || $leftDate === $rightDate;
    }

    private function similarityScore(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }

        $maxLen = max(strlen($a), strlen($b));
        if ($maxLen === 0) {
            return 1.0;
        }

        $distance = levenshtein($a, $b);
        if ($distance < 0) {
            return 0.0;
        }

        return max(0.0, min(1.0, 1 - ($distance / $maxLen)));
    }

    private function containsScore(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        if (str_contains($a, $b) || str_contains($b, $a)) {
            $minLen = min(strlen($a), strlen($b));
            $maxLen = max(strlen($a), strlen($b));
            return $maxLen > 0 ? ($minLen / $maxLen) : 0.0;
        }

        return 0.0;
    }

    private function fuzzyFieldScore(?string $left, ?string $right): float
    {
        $a = $this->normalizeAlphaNumCompare($left);
        $b = $this->normalizeAlphaNumCompare($right);

        return max($this->similarityScore($a, $b), $this->containsScore($a, $b));
    }

    private function isLikelyFuzzyDuplicate(array $a, array $b): bool
    {
        if (number_format((float) ($a['amount'] ?? 0), 2, '.', '') !== number_format((float) ($b['amount'] ?? 0), 2, '.', '')) {
            return false;
        }
        if ($this->normalizeDateCompare($a['booking_date'] ?? null) !== $this->normalizeDateCompare($b['booking_date'] ?? null)) {
            return false;
        }
        if ($this->normalizeDateCompare($a['value_date'] ?? null) !== $this->normalizeDateCompare($b['value_date'] ?? null)) {
            return false;
        }
        if ($this->normalizeCompareString((string) ($a['booking_status'] ?? '')) !== $this->normalizeCompareString((string) ($b['booking_status'] ?? ''))) {
            return false;
        }
        if ($this->normalizeAlphaNumCompare((string) ($a['currency'] ?? '')) !== $this->normalizeAlphaNumCompare((string) ($b['currency'] ?? ''))) {
            return false;
        }

        $ibanA = $this->normalizeAlphaNumCompare((string) ($a['counterparty_iban'] ?? ''));
        $ibanB = $this->normalizeAlphaNumCompare((string) ($b['counterparty_iban'] ?? ''));
        $ibanEqual = $ibanA !== '' && $ibanA === $ibanB;

        $counterpartyScore = $this->fuzzyFieldScore((string) ($a['counterparty_name'] ?? ''), (string) ($b['counterparty_name'] ?? ''));
        $purposeScore = $this->fuzzyFieldScore((string) ($a['purpose'] ?? ''), (string) ($b['purpose'] ?? ''));
        $textScore = $this->fuzzyFieldScore((string) ($a['booking_text'] ?? ''), (string) ($b['booking_text'] ?? ''));

        if ($ibanEqual && max($counterpartyScore, $purposeScore, $textScore) >= 0.72) {
            return true;
        }
        if ($counterpartyScore >= 0.88 && ($purposeScore >= 0.68 || $textScore >= 0.85)) {
            return true;
        }

        return $purposeScore >= 0.90 && $textScore >= 0.85;
    }

    private function compareRowsByKeepMode(array $a, array $b, string $keepMode): int
    {
        $ta = $a['_timestamp'] ?? null;
        $tb = $b['_timestamp'] ?? null;
        if ($ta !== null && $tb !== null && $ta !== $tb) {
            return $keepMode === 'oldest' ? ($ta <=> $tb) : ($tb <=> $ta);
        }

        $ia = (int) ($a['id'] ?? 0);
        $ib = (int) ($b['id'] ?? 0);

        return $keepMode === 'oldest' ? ($ia <=> $ib) : ($ib <=> $ia);
    }

    private function compareRowsPreferBooked(array $a, array $b, string $keepMode): int
    {
        $statusA = $this->normalizeCompareString((string) ($a['booking_status'] ?? ''));
        $statusB = $this->normalizeCompareString((string) ($b['booking_status'] ?? ''));
        if ($statusA !== $statusB) {
            if ($statusA === 'gebucht') {
                return -1;
            }
            if ($statusB === 'gebucht') {
                return 1;
            }
        }

        return $this->compareRowsByKeepMode($a, $b, $keepMode);
    }
}
