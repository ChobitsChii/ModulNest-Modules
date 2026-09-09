<?php

declare(strict_types=1);

namespace ModulNest\Banking;

use DateTimeImmutable;
use PDO;
use Throwable;

final class BankingDashboardService
{
    private bool $cacheTableChecked = false;
    private bool $cacheTableAvailable = false;

    public function __construct(
        private readonly BankingTransactionRepository $transactions,
        private readonly ?BankingRecurringOverviewService $recurringOverview = null,
        private readonly ?PDO $pdo = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function overviewForUser(int $userId): array
    {
        if (!$this->transactions->hasNativeTables()) {
            return $this->buildOverview($userId);
        }

        $periodKey = (new DateTimeImmutable('now'))->format('Y-m');
        $fingerprint = $this->fingerprintForUser($userId, $periodKey);
        if ($fingerprint !== null) {
            $cached = $this->readCache($userId, $periodKey, $fingerprint);
            if ($cached !== null) {
                $cached['cache'] = [
                    'hit' => true,
                    'period_key' => $periodKey,
                ];
                return $cached;
            }
        }

        $overview = $this->buildOverview($userId);
        if ($fingerprint !== null) {
            $this->writeCache($userId, $periodKey, $fingerprint, $overview);
        }
        $overview['cache'] = [
            'hit' => false,
            'period_key' => $periodKey,
        ];

        return $overview;
    }

    public function invalidateForUser(int $userId): void
    {
        if ($this->pdo === null || !$this->ensureCacheTable()) {
            return;
        }

        try {
            $statement = $this->pdo->prepare(
                "DELETE FROM banking_dashboard_cache WHERE user_id = :user_id AND cache_scope = 'overview'"
            );
            $statement->execute(['user_id' => $userId]);
        } catch (Throwable) {
            // Cache invalidation must never break the banking workflow.
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOverview(int $userId): array
    {
        $now = new DateTimeImmutable('now');
        $monthStart = $now->modify('first day of this month')->format('Y-m-d');
        $monthEnd = $now->modify('last day of this month')->format('Y-m-d');

        $counts = $this->transactions->countSummary($userId);
        $recurring = $this->recurringOverview?->dashboardSummaryForUser($userId) ?? [
            'current' => [],
            'monthly_rules' => [],
        ];

        return [
            'has_native_tables' => $this->transactions->hasNativeTables(),
            'has_data' => $counts['total'] > 0,
            'counts' => $counts,
            'totals' => $this->transactions->moneySummary($userId),
            'current_month' => [
                'label' => $now->format('m/Y'),
                'from' => $monthStart,
                'to' => $monthEnd,
                'summary' => $this->transactions->moneySummary($userId, $monthStart, $monthEnd),
            ],
            'latest_transactions' => $this->transactions->latestTransactions($userId, 8),
            'top_categories' => $this->transactions->topExpenseCategories($userId, 8),
            'recurring' => $recurring,
            'monthly_comparison' => $this->monthlyComparison($userId, is_array($recurring['monthly_rules'] ?? null) ? $recurring['monthly_rules'] : []),
        ];
    }

    private function ensureCacheTable(): bool
    {
        if ($this->pdo === null) {
            return false;
        }
        if ($this->cacheTableChecked) {
            return $this->cacheTableAvailable;
        }

        $this->cacheTableChecked = true;

        try {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
            );
            $statement->execute(['banking_dashboard_cache']);
            $this->cacheTableAvailable = (int) $statement->fetchColumn() === 1;
        } catch (Throwable) {
            $this->cacheTableAvailable = false;
        }

        return $this->cacheTableAvailable;
    }

    private function fingerprintForUser(int $userId, string $periodKey): ?string
    {
        if ($this->pdo === null) {
            return null;
        }

        try {
            $transaction = $this->aggregateFingerprint(
                'banking_transactions',
                $userId,
                'updated_at'
            );
            $rules = $this->aggregateFingerprint(
                'banking_recurring_rules',
                $userId,
                'updated_at'
            );
            $conditions = $this->aggregateFingerprint(
                'banking_recurring_rule_conditions',
                $userId,
                'created_at'
            );
        } catch (Throwable) {
            return null;
        }

        return hash('sha256', json_encode([
            'period' => $periodKey,
            'transactions' => $transaction,
            'rules' => $rules,
            'conditions' => $conditions,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{count:int,max_id:int,max_changed:string}
     */
    private function aggregateFingerprint(string $table, int $userId, string $changedColumn): array
    {
        if (!in_array($table, ['banking_transactions', 'banking_recurring_rules', 'banking_recurring_rule_conditions'], true)) {
            return ['count' => 0, 'max_id' => 0, 'max_changed' => ''];
        }
        if (!in_array($changedColumn, ['updated_at', 'created_at'], true)) {
            return ['count' => 0, 'max_id' => 0, 'max_changed' => ''];
        }

        $statement = $this->pdo?->prepare(
            'SELECT COUNT(*) AS count_rows, COALESCE(MAX(id), 0) AS max_id, COALESCE(MAX(' . $changedColumn . '), \'\') AS max_changed
             FROM ' . $table . '
             WHERE user_id = :user_id'
        );
        if ($statement === null) {
            return ['count' => 0, 'max_id' => 0, 'max_changed' => ''];
        }
        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['count' => 0, 'max_id' => 0, 'max_changed' => ''];
        }

        return [
            'count' => (int) ($row['count_rows'] ?? 0),
            'max_id' => (int) ($row['max_id'] ?? 0),
            'max_changed' => (string) ($row['max_changed'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCache(int $userId, string $periodKey, string $fingerprint): ?array
    {
        if ($this->pdo === null || !$this->ensureCacheTable()) {
            return null;
        }

        try {
            $statement = $this->pdo->prepare(
                "SELECT payload_json
                 FROM banking_dashboard_cache
                 WHERE user_id = :user_id
                   AND cache_scope = 'overview'
                   AND period_key = :period_key
                   AND data_hash = :data_hash
                 LIMIT 1"
            );
            $statement->execute([
                'user_id' => $userId,
                'period_key' => $periodKey,
                'data_hash' => $fingerprint,
            ]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return null;
            }

            $payload = json_decode((string) ($row['payload_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
            return is_array($payload) ? $payload : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $overview
     */
    private function writeCache(int $userId, string $periodKey, string $fingerprint, array $overview): void
    {
        if ($this->pdo === null || !$this->ensureCacheTable()) {
            return;
        }

        try {
            $statement = $this->pdo->prepare(
                "INSERT INTO banking_dashboard_cache (user_id, cache_scope, period_key, data_hash, payload_json)
                 VALUES (:user_id, 'overview', :period_key, :data_hash, :payload_json)
                 ON DUPLICATE KEY UPDATE
                    data_hash = VALUES(data_hash),
                    payload_json = VALUES(payload_json),
                    updated_at = CURRENT_TIMESTAMP"
            );
            $statement->execute([
                'user_id' => $userId,
                'period_key' => $periodKey,
                'data_hash' => $fingerprint,
                'payload_json' => json_encode($overview, JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable) {
            // Cache write failures should not affect the visible banking overview.
        }
    }

    /**
     * @param array<int, array<string, mixed>> $monthlyRules
     * @return array<int, array<string, mixed>>
     */
    private function monthlyComparison(int $userId, array $monthlyRules): array
    {
        $actualRows = $this->transactions->monthlySummary($userId, null);
        $actualByMonth = [];
        foreach ($actualRows as $row) {
            $actualByMonth[(string) ($row['month'] ?? '')] = $row;
        }

        $comparison = [];
        foreach ($monthlyRules as $ruleMonth) {
            $month = (string) ($ruleMonth['month'] ?? '');
            if ($month === '') {
                continue;
            }
            $actual = $actualByMonth[$month] ?? ['income' => 0.0, 'expenses' => 0.0, 'balance' => 0.0, 'count' => 0];
            $ruleNet = (float) ($ruleMonth['rule_net'] ?? 0);
            $actualNet = (float) ($actual['balance'] ?? 0);
            $comparison[] = [
                'month' => $month,
                'label' => (string) ($ruleMonth['label'] ?? $month),
                'income' => (float) ($actual['income'] ?? 0),
                'expense' => -abs((float) ($actual['expenses'] ?? 0)),
                'net' => $actualNet,
                'expected_income' => (float) ($ruleMonth['rule_income'] ?? 0),
                'expected_expense' => (float) ($ruleMonth['rule_expense'] ?? 0),
                'expected_net' => $ruleNet,
                'difference' => $actualNet - $ruleNet,
                'count' => (int) ($actual['count'] ?? 0),
            ];
        }

        return $comparison;
    }
}
