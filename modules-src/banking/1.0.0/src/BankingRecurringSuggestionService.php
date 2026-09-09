<?php

declare(strict_types=1);

namespace ModulNest\Banking;

use DateTimeImmutable;

final class BankingRecurringSuggestionService
{
    public function __construct(
        private readonly BankingTransactionRepository $transactions,
        private readonly BankingRecurringRuleRepository $rules,
    ) {
    }

    /**
     * @return array{
     *   limit:int,
     *   new:array<int, array<string, mixed>>,
     *   covered:array<int, array<string, mixed>>,
     *   filtered:array<int, array<string, mixed>>
     * }
     */
    public function suggestionsForUser(int $userId, int $limit): array
    {
        $limit = max(1, min(500, $limit));
        $transactions = $this->transactions->transactionsForRecurringMatching($userId);
        $rules = $this->rules->rulesWithConditions($userId);
        $candidates = $this->detectCandidates($transactions, $limit);

        $new = [];
        $covered = [];
        $filtered = [];
        foreach ($candidates as $candidate) {
            $matchedRule = $this->matchedRule($rules, $candidate);
            if ($matchedRule === null) {
                $new[] = $candidate;
                continue;
            }

            $candidate['matched_rule'] = [
                'id' => (int) ($matchedRule['id'] ?? 0),
                'name' => (string) ($matchedRule['name'] ?? ''),
                'rule_type' => (string) ($matchedRule['rule_type'] ?? 'regel'),
            ];
            if ((string) ($matchedRule['rule_type'] ?? 'regel') === 'filter') {
                $filtered[] = $candidate;
            } else {
                $covered[] = $candidate;
            }
        }

        return [
            'limit' => $limit,
            'new' => $new,
            'covered' => $covered,
            'filtered' => $filtered,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $transactions
     * @return array<int, array<string, mixed>>
     */
    private function detectCandidates(array $transactions, int $limit): array
    {
        $candidates = [];
        $seen = [];
        $this->collectCandidates($transactions, 'iban', $limit, $candidates, $seen);
        if (count($candidates) < $limit) {
            $this->collectCandidates($transactions, 'counterparty', $limit, $candidates, $seen);
        }

        usort($candidates, static fn (array $a, array $b): int => strcmp((string) ($b['last_seen'] ?? ''), (string) ($a['last_seen'] ?? '')));

        return array_slice($candidates, 0, $limit);
    }

    /**
     * @param array<int, array<string, mixed>> $transactions
     * @param array<int, array<string, mixed>> $candidates
     * @param array<string, bool> $seen
     */
    private function collectCandidates(array $transactions, string $mode, int $limit, array &$candidates, array &$seen): void
    {
        $groups = [];
        foreach ($transactions as $transaction) {
            $amount = $this->normalizeAmount((string) ($transaction['amount'] ?? ''));
            if ($amount === null) {
                continue;
            }

            $iban = trim((string) ($transaction['counterparty_iban'] ?? ''));
            $counterparty = trim((string) ($transaction['counterparty_name'] ?? ''));
            if ($mode === 'iban') {
                if ($iban === '') {
                    continue;
                }
                $key = 'iban|' . $iban . '|' . $counterparty . '|' . $amount;
            } else {
                if ($iban !== '' || $counterparty === '') {
                    continue;
                }
                $key = 'counterparty|' . $counterparty . '|' . $amount;
            }

            $groups[$key]['items'][] = $transaction;
            $groups[$key]['amount'] = $amount;
            $groups[$key]['iban'] = $iban;
            $groups[$key]['counterparty'] = $counterparty;
        }

        $groupRows = [];
        foreach ($groups as $group) {
            $items = is_array($group['items'] ?? null) ? $group['items'] : [];
            $months = [];
            $firstSeen = null;
            $lastSeen = null;
            foreach ($items as $item) {
                $date = $this->transactionDate($item);
                if ($date === '') {
                    continue;
                }
                $months[substr($date, 0, 7)] = true;
                $firstSeen = $firstSeen === null || strcmp($date, $firstSeen) < 0 ? $date : $firstSeen;
                $lastSeen = $lastSeen === null || strcmp($date, $lastSeen) > 0 ? $date : $lastSeen;
            }
            if (count($items) < 2 || count($months) < 2) {
                continue;
            }
            usort($items, fn (array $a, array $b): int => strcmp($this->transactionDate($b), $this->transactionDate($a)));
            $groupRows[] = [
                'items' => $items,
                'amount' => (string) ($group['amount'] ?? ''),
                'iban' => (string) ($group['iban'] ?? ''),
                'counterparty' => (string) ($group['counterparty'] ?? ''),
                'occurrences' => count($items),
                'distinct_months' => count($months),
                'first_seen' => $firstSeen,
                'last_seen' => $lastSeen,
            ];
        }

        usort($groupRows, static fn (array $a, array $b): int => strcmp((string) ($b['last_seen'] ?? ''), (string) ($a['last_seen'] ?? '')));
        foreach ($groupRows as $group) {
            if (count($candidates) >= $limit) {
                return;
            }

            $detail = is_array($group['items'][0] ?? null) ? $group['items'][0] : [];
            $iban = trim((string) ($group['iban'] ?? ''));
            $counterparty = trim((string) ($group['counterparty'] ?? ''));
            if ($counterparty === '') {
                $counterparty = trim((string) ($detail['counterparty_name'] ?? ''));
            }
            if ($iban === '') {
                $iban = trim((string) ($detail['counterparty_iban'] ?? ''));
            }

            $displayName = $counterparty !== '' ? $counterparty : ($iban !== '' ? $iban : 'Wiederkehrende Zahlung');
            $amount = (string) ($group['amount'] ?? '');
            $conditions = [
                ['field' => 'betrag', 'operator' => 'equals', 'value' => $amount],
            ];
            if ($iban !== '') {
                $conditions[] = ['field' => 'kontonummer_iban', 'operator' => 'equals', 'value' => $iban];
            }
            if ($counterparty !== '') {
                $conditions[] = ['field' => 'beguenstigter_zahlungspflichtiger', 'operator' => 'contains', 'value' => mb_substr($counterparty, 0, 120, 'UTF-8')];
            }

            $purpose = trim((string) ($detail['purpose'] ?? ''));
            if ($purpose !== '') {
                $conditions[] = ['field' => 'verwendungszweck', 'operator' => 'contains', 'value' => mb_substr($purpose, 0, 120, 'UTF-8')];
            }

            $hashKey = hash('sha256', implode('|', [$displayName, $amount, $iban, $counterparty]));
            if (isset($seen[$hashKey])) {
                continue;
            }
            $seen[$hashKey] = true;

            $candidates[] = [
                'name' => $displayName,
                'conditions' => $conditions,
                'amount' => $amount,
                'occurrences' => (int) ($group['occurrences'] ?? 0),
                'distinct_months' => (int) ($group['distinct_months'] ?? 0),
                'first_seen' => (string) ($group['first_seen'] ?? ''),
                'last_seen' => (string) ($group['last_seen'] ?? ''),
                'sample' => [
                    'verwendungszweck' => (string) ($detail['purpose'] ?? ''),
                    'buchungstext' => (string) ($detail['booking_text'] ?? ''),
                    'betrag' => (string) ($detail['amount'] ?? ''),
                    'beguenstigter_zahlungspflichtiger' => $counterparty,
                    'kontonummer_iban' => $iban,
                ],
            ];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     * @param array<string, mixed> $candidate
     */
    private function matchedRule(array $rules, array $candidate): ?array
    {
        foreach ($rules as $rule) {
            if ($this->ruleMatchesCandidate($rule, $candidate)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $candidate
     */
    private function ruleMatchesCandidate(array $rule, array $candidate): bool
    {
        if (!$this->ruleAppliesToDate($rule, (string) ($candidate['last_seen'] ?? ''))) {
            return false;
        }

        foreach ((array) ($rule['conditions'] ?? []) as $condition) {
            $field = (string) ($condition['field'] ?? '');
            $operator = (string) ($condition['operator'] ?? '');
            $value = (string) ($condition['value'] ?? '');
            $candidateValue = $this->candidateValue($candidate, $field);
            if ($candidateValue === null) {
                return false;
            }

            if ($this->mapField($field) === 'amount') {
                if ($this->normalizeAmount((string) $candidateValue) !== $this->normalizeAmount($value)) {
                    return false;
                }
                continue;
            }

            $actual = mb_strtolower(trim((string) $candidateValue), 'UTF-8');
            $expected = mb_strtolower(trim($value), 'UTF-8');
            if ($operator === 'equals' && $actual !== $expected) {
                return false;
            }
            if ($operator === 'contains' && !str_contains($actual, $expected)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function candidateValue(array $candidate, string $field): ?string
    {
        $mapped = $this->mapField($field);
        if ($mapped === 'amount') {
            return (string) ($candidate['amount'] ?? '');
        }

        $sample = is_array($candidate['sample'] ?? null) ? $candidate['sample'] : [];
        $legacyKey = match ($mapped) {
            'booking_text' => 'buchungstext',
            'purpose' => 'verwendungszweck',
            'counterparty_name' => 'beguenstigter_zahlungspflichtiger',
            'counterparty_iban' => 'kontonummer_iban',
            default => $field,
        };

        if (isset($sample[$legacyKey]) && (string) $sample[$legacyKey] !== '') {
            return (string) $sample[$legacyKey];
        }

        foreach ((array) ($candidate['conditions'] ?? []) as $condition) {
            if ($this->mapField((string) ($condition['field'] ?? '')) === $mapped) {
                return (string) ($condition['value'] ?? '');
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function ruleAppliesToDate(array $rule, string $date): bool
    {
        $date = substr($date, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return true;
        }
        $from = (string) ($rule['active_from'] ?? '');
        $to = (string) ($rule['active_to'] ?? '');
        if ($from !== '' && strcmp($date, $from) < 0) {
            return false;
        }
        if ($to !== '' && strcmp($date, $to) > 0) {
            return false;
        }

        return true;
    }

    private function mapField(string $field): string
    {
        return match (mb_strtolower(trim($field), 'UTF-8')) {
            'buchungstext' => 'booking_text',
            'verwendungszweck' => 'purpose',
            'beguenstigter_zahlungspflichtiger', 'begünstigter_zahlungspflichtiger' => 'counterparty_name',
            'kontonummer_iban' => 'counterparty_iban',
            'betrag' => 'amount',
            default => $field,
        };
    }

    /**
     * @param array<string, mixed> $transaction
     */
    private function transactionDate(array $transaction): string
    {
        foreach (['booking_date', 'value_date', 'created_at'] as $field) {
            $date = substr((string) ($transaction[$field] ?? ''), 0, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return $date;
            }
        }

        return '';
    }

    private function normalizeAmount(string $value): ?string
    {
        $value = trim(str_replace(["\u{00A0}", ' '], '', $value));
        if ($value === '') {
            return null;
        }
        $value = str_replace(',', '.', $value);
        $value = preg_replace('/[^0-9.\-]/', '', $value) ?? '';
        if ($value === '' || in_array($value, ['-', '.', '-.', '.-', '--'], true)) {
            return null;
        }

        $negative = false;
        if (str_starts_with($value, '-')) {
            $negative = true;
            $value = substr($value, 1);
        }
        $parts = explode('.', $value);
        if (count($parts) > 2) {
            $decimal = array_pop($parts);
            $value = implode('', $parts) . '.' . $decimal;
        }
        if (!is_numeric($value)) {
            return null;
        }

        $number = (float) $value;
        if ($negative) {
            $number *= -1;
        }

        return number_format($number, 2, '.', '');
    }
}
