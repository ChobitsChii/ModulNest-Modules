<?php

declare(strict_types=1);

namespace ModulNest\Banking;

use DateInterval;
use DateTimeImmutable;

final class BankingRecurringOverviewService
{
    public function __construct(
        private readonly BankingRecurringRuleRepository $rules,
        private readonly BankingTransactionRepository $transactions,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function overviewForUser(int $userId, ?string $periodInput): array
    {
        $period = $this->normalizePeriod($periodInput);
        $rules = $this->rules->rulesWithConditions($userId, 'regel');
        if ($rules === []) {
            $rules = $this->rules->rulesWithConditions($userId);
        }
        $transactions = $this->transactions->transactionsForRecurringMatching($userId);

        $items = [];
        foreach ($this->groupRules($rules) as $groupKey => $groupRules) {
            $groupMeta = $this->groupMeta($groupRules);
            if (!$this->ruleActiveInRange($groupMeta, $period['start'], $period['end'])) {
                continue;
            }

            $matches = $this->matchesForRules($groupRules, $transactions, $period);
            $latestMatch = $this->latestMatchForRules($groupRules, $transactions);
            $expected = $this->isExpected($groupRules, $period, $transactions);
            $status = $expected ? ($matches === [] ? 'offen' : 'gebucht') : 'nicht fällig';
            $expectedAmount = $this->expectedAmount($groupRules, $transactions);

            $firstRule = $groupRules[0] ?? [];
            $items[] = [
                'group_key' => $groupKey,
                'label' => $this->groupLabel($firstRule),
                'status' => $status,
                'rules' => $groupRules,
                'rule_count' => count($groupRules),
                'matches' => $matches,
                'match_count' => count($matches),
                'latest_booking_date' => $latestMatch['booking_date'] ?? '',
                'latest_amount' => $latestMatch['amount'] ?? null,
                'interval_type' => (string) ($groupMeta['interval_type'] ?? ($firstRule['interval_type'] ?? '')),
                'active_from' => (string) ($groupMeta['active_from'] ?? ''),
                'active_to' => (string) ($groupMeta['active_to'] ?? ''),
                'period_mode' => (string) ($groupMeta['period_mode'] ?? ($firstRule['period_mode'] ?? '')),
                'due_day' => $groupMeta['due_day'] ?? ($firstRule['due_day'] ?? null),
                'expected_amount' => $expectedAmount,
                'open_amount' => $status === 'offen' ? $expectedAmount : 0.0,
            ];
        }

        usort($items, static function (array $a, array $b): int {
            $statusOrder = ['offen' => 0, 'gebucht' => 1, 'nicht fällig' => 2];
            $statusCompare = ($statusOrder[$a['status'] ?? ''] ?? 9) <=> ($statusOrder[$b['status'] ?? ''] ?? 9);
            if ($statusCompare !== 0) {
                return $statusCompare;
            }

            $intervalOrder = ['monatlich' => 0, 'vierteljährlich' => 1, 'vierteljaehrlich' => 1, 'jährlich' => 2, 'jaehrlich' => 2];
            $intervalCompare = ($intervalOrder[mb_strtolower((string) ($a['interval_type'] ?? ''), 'UTF-8')] ?? 9)
                <=> ($intervalOrder[mb_strtolower((string) ($b['interval_type'] ?? ''), 'UTF-8')] ?? 9);
            if ($intervalCompare !== 0) {
                return $intervalCompare;
            }

            return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
        });

        return [
            'has_native_tables' => $this->rules->hasNativeTables() && $this->transactions->hasNativeTables(),
            'selected_period' => $period,
            'period_options' => $this->periodOptions($userId),
            'items' => $items,
            'summary' => $this->summary($items),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboardSummaryForUser(int $userId): array
    {
        $rules = $this->rules->rulesWithConditions($userId, 'regel');
        if ($rules === []) {
            $rules = $this->rules->rulesWithConditions($userId);
        }
        $transactions = $this->transactions->transactionsForRecurringMatching($userId);
        $groups = $this->groupRules($rules);
        $currentMonth = (new DateTimeImmutable('first day of this month'))->format('Y-m');
        $currentPeriod = $this->normalizePeriod($currentMonth);

        $current = [
            'month' => $currentMonth,
            'label' => (new DateTimeImmutable($currentMonth . '-01'))->format('m.Y'),
            'expected_total' => 0.0,
            'expected_income' => 0.0,
            'expected_expense' => 0.0,
            'expected_net' => 0.0,
            'active_rules' => count($groups),
            'open_total' => 0.0,
            'open_income' => 0.0,
            'open_expense' => 0.0,
            'open_count' => 0,
            'booked_total' => 0.0,
            'booked_income' => 0.0,
            'booked_expense' => 0.0,
            'booked_count' => 0,
            'not_due_count' => 0,
        ];

            foreach ($groups as $groupRules) {
                $groupMeta = $this->groupMeta($groupRules);
                if (!$this->ruleActiveInRange($groupMeta, $currentPeriod['start'], $currentPeriod['end'])) {
                    continue;
                }
                $matches = $this->matchesForRules($groupRules, $transactions, $currentPeriod);
                $expected = $this->isExpected($groupRules, $currentPeriod, $transactions);
            if (!$expected) {
                $current['not_due_count']++;
                continue;
            }

            $expectedAmount = $this->expectedAmount($groupRules, $transactions);
            $this->addSignedAmount($current, 'expected', $expectedAmount);

            if ($matches !== []) {
                $current['booked_count']++;
                foreach ($matches as $match) {
                    $this->addSignedAmount($current, 'booked', (float) ($match['amount'] ?? 0));
                }
                continue;
            }

            if (abs($expectedAmount) < 0.00001) {
                continue;
            }
            $current['open_count']++;
            $this->addSignedAmount($current, 'open', $expectedAmount);
        }

        $monthKeys = [];
        $cursor = new DateTimeImmutable('first day of this month -11 months');
        for ($i = 0; $i < 12; $i++) {
            $monthKeys[] = $cursor->format('Y-m');
            $cursor = $cursor->add(new DateInterval('P1M'));
        }

        $monthlyRules = [];
        foreach ($monthKeys as $monthKey) {
            $period = $this->normalizePeriod($monthKey);
            $monthlyRules[$monthKey] = [
                'month' => $monthKey,
                'label' => (new DateTimeImmutable($monthKey . '-01'))->format('m.Y'),
                'rule_income' => 0.0,
                'rule_expense' => 0.0,
                'rule_net' => 0.0,
            ];

            foreach ($groups as $groupRules) {
                $groupMeta = $this->groupMeta($groupRules);
                if (!$this->ruleActiveInRange($groupMeta, $period['start'], $period['end'])) {
                    continue;
                }
                if (!$this->isExpected($groupRules, $period, $transactions)) {
                    continue;
                }
                $matches = $this->matchesForRules($groupRules, $transactions, $period);
                if ($matches !== []) {
                    foreach ($matches as $match) {
                        $this->addMonthlyRuleAmount($monthlyRules[$monthKey], (float) ($match['amount'] ?? 0));
                    }
                    continue;
                }
                $this->addMonthlyRuleAmount($monthlyRules[$monthKey], $this->expectedAmount($groupRules, $transactions));
            }
        }

        return [
            'current' => $current,
            'monthly_rules' => array_reverse(array_values($monthlyRules)),
        ];
    }

    /**
     * @return array{type:string,value:string,label:string,start:string,end:string}
     */
    private function normalizePeriod(?string $periodInput): array
    {
        $input = trim((string) $periodInput);
        if (preg_match('/^\d{4}-\d{2}$/', $input) === 1) {
            $start = new DateTimeImmutable($input . '-01');
            $end = $start->modify('last day of this month');

            return [
                'type' => 'month',
                'value' => $start->format('Y-m'),
                'label' => $start->format('m/Y'),
                'start' => $start->format('Y-m-d'),
                'end' => $end->format('Y-m-d'),
            ];
        }

        if (preg_match('/^\d{4}$/', $input) === 1) {
            return [
                'type' => 'year',
                'value' => $input,
                'label' => $input,
                'start' => $input . '-01-01',
                'end' => $input . '-12-31',
            ];
        }

        $current = new DateTimeImmutable('first day of this month');

        return [
            'type' => 'month',
            'value' => $current->format('Y-m'),
            'label' => $current->format('m/Y'),
            'start' => $current->format('Y-m-d'),
            'end' => $current->modify('last day of this month')->format('Y-m-d'),
        ];
    }

    /**
     * @return array{months:array<int, array{value:string,label:string}>,years:array<int, array{value:string,label:string}>}
     */
    private function periodOptions(int $userId): array
    {
        $months = array_fill_keys($this->transactions->availableYearMonths($userId), true);
        $current = new DateTimeImmutable('first day of this month');
        $months[$current->format('Y-m')] = true;
        $months[$current->add(new DateInterval('P1M'))->format('Y-m')] = true;
        $monthValues = array_keys($months);
        rsort($monthValues);

        $years = array_fill_keys(array_map('strval', $this->transactions->availableYears($userId)), true);
        $years[$current->format('Y')] = true;
        $years[$current->add(new DateInterval('P1M'))->format('Y')] = true;
        $yearValues = array_keys($years);
        rsort($yearValues);

        return [
            'months' => array_map(static fn (string $month): array => [
                'value' => $month,
                'label' => (new DateTimeImmutable($month . '-01'))->format('m/Y'),
            ], $monthValues),
            'years' => array_map(static fn (string $year): array => [
                'value' => $year,
                'label' => $year,
            ], $yearValues),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function groupRules(array $rules): array
    {
        $groups = [];
        foreach ($rules as $rule) {
            if (!((bool) ($rule['is_active'] ?? false))) {
                continue;
            }
            $label = $this->groupLabel($rule);
            $key = mb_strtolower($label !== '' ? $label : 'regel-' . (string) ($rule['id'] ?? '0'), 'UTF-8');
            $groups[$key][] = $rule;
        }

        return $groups;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function groupLabel(array $rule): string
    {
        $group = trim((string) ($rule['group_label'] ?? ''));
        if ($group !== '') {
            return $group;
        }

        return trim((string) ($rule['name'] ?? 'Wiederkehrende Regel'));
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     * @param array<int, array<string, mixed>> $transactions
     * @param array{type:string,value:string,start:string,end:string} $period
     * @return array<int, array<string, mixed>>
     */
    private function matchesForRules(array $rules, array $transactions, array $period): array
    {
        $matches = [];
        foreach ($transactions as $transaction) {
            foreach ($rules as $rule) {
                if (!$this->transactionMatchesRule($transaction, $rule)) {
                    continue;
                }
                $effectiveDate = $this->effectiveDate((string) ($transaction['booking_date'] ?? ''), $rule);
                if ($effectiveDate === null || $effectiveDate < $period['start'] || $effectiveDate > $period['end']) {
                    continue;
                }
                $matches[(int) ($transaction['id'] ?? 0)] = array_merge($transaction, [
                    'effective_date' => $effectiveDate,
                    'matched_rule' => (string) ($rule['name'] ?? ''),
                ]);
                break;
            }
        }

        $values = array_values($matches);
        usort($values, static fn (array $a, array $b): int => strcmp((string) ($b['booking_date'] ?? ''), (string) ($a['booking_date'] ?? '')));

        return $values;
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     * @param array<int, array<string, mixed>> $transactions
     * @return array<string, mixed>
     */
    private function latestMatchForRules(array $rules, array $transactions): array
    {
        $matches = [];
        foreach ($transactions as $transaction) {
            foreach ($rules as $rule) {
                if (!$this->transactionMatchesRule($transaction, $rule)) {
                    continue;
                }
                $matches[(int) ($transaction['id'] ?? 0)] = $transaction;
                break;
            }
        }

        $values = array_values($matches);
        usort($values, static fn (array $a, array $b): int => strcmp((string) ($b['booking_date'] ?? ''), (string) ($a['booking_date'] ?? '')));

        return is_array($values[0] ?? null) ? $values[0] : [];
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     * @param array{type:string,start:string,end:string} $period
     * @param array<int, array<string, mixed>> $transactions
     */
    private function isExpected(array $rules, array $period, array $transactions): bool
    {
        $periodStart = new DateTimeImmutable($period['start']);
        $periodEnd = new DateTimeImmutable($period['end']);
        $matchMonths = $this->matchMonthsForRules($rules, $transactions);
        if ($matchMonths === []) {
            return false;
        }
        sort($matchMonths);
        $baseDate = new DateTimeImmutable($matchMonths[0] . '-01');
        $groupMeta = $this->groupMeta($rules);
        $interval = (string) ($groupMeta['interval_type'] ?? 'monatlich');

        $cursor = new DateTimeImmutable($periodStart->format('Y-m-01'));
        $endMonth = new DateTimeImmutable($periodEnd->format('Y-m-01'));
        while ($cursor <= $endMonth) {
            if ($this->ruleActiveInRange($groupMeta, $cursor->format('Y-m-01'), $cursor->modify('last day of this month')->format('Y-m-d'))
                && $this->monthMatchesInterval($baseDate, $cursor, $interval)) {
                return true;
            }
            $cursor = $cursor->add(new DateInterval('P1M'));
        }

        return false;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function ruleActiveInRange(array $rule, string $start, string $end): bool
    {
        $activeFrom = trim((string) ($rule['active_from'] ?? ''));
        $activeTo = trim((string) ($rule['active_to'] ?? ''));

        if ($activeFrom !== '' && $activeFrom > $end) {
            return false;
        }
        if ($activeTo !== '' && $activeTo < $start) {
            return false;
        }

        return true;
    }

    private function monthMatchesInterval(DateTimeImmutable $base, DateTimeImmutable $month, string $interval): bool
    {
        $months = ((int) $month->format('Y') - (int) $base->format('Y')) * 12 + ((int) $month->format('n') - (int) $base->format('n'));
        if ($months < 0) {
            return false;
        }

        $step = match (mb_strtolower($interval, 'UTF-8')) {
            'vierteljährlich', 'vierteljaehrlich', 'quartal', 'quarterly' => 3,
            'jährlich', 'jaehrlich', 'yearly' => 12,
            default => 1,
        };

        return $months % $step === 0;
    }

    /**
     * @param array<string, mixed> $transaction
     * @param array<string, mixed> $rule
     */
    private function transactionMatchesRule(array $transaction, array $rule): bool
    {
        $conditions = is_array($rule['conditions'] ?? null) ? $rule['conditions'] : [];
        if ($conditions === []) {
            return false;
        }

        foreach ($conditions as $condition) {
            $field = $this->mapConditionField((string) ($condition['field'] ?? ''));
            $operator = mb_strtolower((string) ($condition['operator'] ?? ''), 'UTF-8');
            $expected = (string) ($condition['value'] ?? '');
            $actual = (string) ($transaction[$field] ?? '');

            if ($field === 'amount') {
                $actualAmount = $this->normalizeAmount($actual);
                $expectedAmount = $this->normalizeAmount($expected);
                if ($actualAmount === null || $expectedAmount === null || $actualAmount !== $expectedAmount) {
                    return false;
                }
                continue;
            }

            if ($operator === 'equals') {
                if (mb_strtolower(trim($actual), 'UTF-8') !== mb_strtolower(trim($expected), 'UTF-8')) {
                    return false;
                }
                continue;
            }

            if (!str_contains(mb_strtolower($actual, 'UTF-8'), mb_strtolower($expected, 'UTF-8'))) {
                return false;
            }
        }

        return true;
    }

    private function mapConditionField(string $field): string
    {
        return match (mb_strtolower(trim($field), 'UTF-8')) {
            'buchungstext' => 'booking_text',
            'verwendungszweck' => 'purpose',
            'beguenstigter_zahlungspflichtiger', 'begünstigter_zahlungspflichtiger' => 'counterparty_name',
            'kontonummer_iban' => 'counterparty_iban',
            'bic', 'swift' => 'counterparty_bic',
            'betrag' => 'amount',
            'waehrung', 'währung' => 'currency',
            'info' => 'raw_info',
            'kategorie' => 'legacy_category_name',
            'status' => 'booking_status',
            'auftragskonto' => 'account_identifier',
            default => $field,
        };
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function effectiveDate(string $bookingDate, array $rule): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bookingDate)) {
            return null;
        }

        $date = new DateTimeImmutable($bookingDate);
        $periodMode = mb_strtolower((string) ($rule['period_mode'] ?? ''), 'UTF-8');
        if ($periodMode === 'folgemonat' || $periodMode === 'next_month') {
            $date = $date->modify('first day of next month');
        }

        $dueDay = $rule['due_day'] ?? null;
        if (is_int($dueDay) && $dueDay >= 1 && $dueDay <= 31) {
            $lastDay = (int) $date->format('t');
            $date = $date->setDate((int) $date->format('Y'), (int) $date->format('m'), min($dueDay, $lastDay));
        }

        return $date->format('Y-m-d');
    }

    private function normalizeAmount(string $value): ?string
    {
        $value = trim(str_replace([' ', "\xc2\xa0"], '', $value));
        if ($value === '') {
            return null;
        }
        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
        }
        if (!is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     * @param array<int, array<string, mixed>> $transactions
     */
    private function expectedAmount(array $rules, array $transactions): float
    {
        foreach ($rules as $rule) {
            foreach ((array) ($rule['conditions'] ?? []) as $condition) {
                if ($this->mapConditionField((string) ($condition['field'] ?? '')) !== 'amount') {
                    continue;
                }
                $amount = $this->normalizeAmount((string) ($condition['value'] ?? ''));
                if ($amount !== null) {
                    return (float) $amount;
                }
            }
        }

        foreach ($transactions as $transaction) {
            foreach ($rules as $rule) {
                if ($this->transactionMatchesRule($transaction, $rule)) {
                    return (float) ($transaction['amount'] ?? 0);
                }
            }
        }

        return 0.0;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array{open:int,booked:int,not_due:int,open_amount:float,total:int}
     */
    private function summary(array $items): array
    {
        $summary = ['open' => 0, 'booked' => 0, 'not_due' => 0, 'open_amount' => 0.0, 'open_income' => 0.0, 'open_expense' => 0.0, 'total' => count($items)];
        foreach ($items as $item) {
            $status = (string) ($item['status'] ?? '');
            if ($status === 'offen') {
                $summary['open']++;
                $amount = (float) ($item['open_amount'] ?? 0);
                $summary['open_amount'] += $amount;
                if ($amount >= 0) {
                    $summary['open_income'] += $amount;
                } else {
                    $summary['open_expense'] += $amount;
                }
            } elseif ($status === 'gebucht') {
                $summary['booked']++;
            } else {
                $summary['not_due']++;
            }
        }

        return $summary;
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     * @return array<string, mixed>
     */
    private function groupMeta(array $rules): array
    {
        $first = $rules[0] ?? [];
        $activeFrom = null;
        $activeTo = null;
        $hasOpenStart = false;
        $hasOpenEnd = false;
        foreach ($rules as $rule) {
            $from = trim((string) ($rule['active_from'] ?? ''));
            $to = trim((string) ($rule['active_to'] ?? ''));
            if ($from === '') {
                $hasOpenStart = true;
            } elseif ($activeFrom === null || strcmp($from, $activeFrom) < 0) {
                $activeFrom = $from;
            }
            if ($to === '') {
                $hasOpenEnd = true;
            } elseif ($activeTo === null || strcmp($to, $activeTo) > 0) {
                $activeTo = $to;
            }
        }

        return [
            'interval_type' => (string) ($first['interval_type'] ?? 'monatlich'),
            'active_from' => $hasOpenStart ? '' : (string) ($activeFrom ?? ''),
            'active_to' => $hasOpenEnd ? '' : (string) ($activeTo ?? ''),
            'period_mode' => (string) ($first['period_mode'] ?? 'buchungsmonat'),
            'due_day' => $first['due_day'] ?? null,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     * @param array<int, array<string, mixed>> $transactions
     * @return array<int, string>
     */
    private function matchMonthsForRules(array $rules, array $transactions): array
    {
        $months = [];
        foreach ($transactions as $transaction) {
            foreach ($rules as $rule) {
                if (!$this->transactionMatchesRule($transaction, $rule)) {
                    continue;
                }
                $effectiveDate = $this->effectiveDate((string) ($transaction['booking_date'] ?? ''), $rule);
                if ($effectiveDate !== null) {
                    $months[substr($effectiveDate, 0, 7)] = true;
                }
                break;
            }
        }

        return array_keys($months);
    }

    /**
     * @param array<string, mixed> $target
     */
    private function addSignedAmount(array &$target, string $prefix, float $amount): void
    {
        $target[$prefix . '_total'] += $amount;
        if ($amount >= 0) {
            $target[$prefix . '_income'] += $amount;
        } else {
            $target[$prefix . '_expense'] += $amount;
        }
        if ($prefix === 'expected') {
            $target['expected_net'] += $amount;
        }
    }

    /**
     * @param array<string, mixed> $month
     */
    private function addMonthlyRuleAmount(array &$month, float $amount): void
    {
        if ($amount >= 0) {
            $month['rule_income'] += $amount;
        } else {
            $month['rule_expense'] += $amount;
        }
        $month['rule_net'] += $amount;
    }
}
