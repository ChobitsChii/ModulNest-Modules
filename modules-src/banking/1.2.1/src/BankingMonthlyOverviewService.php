<?php

declare(strict_types=1);

namespace ModulNest\Banking;

use DateTimeImmutable;

final class BankingMonthlyOverviewService
{
    public function __construct(
        private readonly BankingTransactionRepository $transactions,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function overviewForUser(int $userId, ?string $yearInput): array
    {
        $availableYears = $this->transactions->availableYears($userId);
        $selectedYear = $this->normalizeYear($yearInput, $availableYears);
        $months = $this->transactions->monthlySummary($userId, $selectedYear);

        return [
            'has_native_tables' => $this->transactions->hasNativeTables(),
            'available_years' => $availableYears,
            'selected_year' => $selectedYear,
            'months' => array_map([$this, 'decorateMonth'], $months),
            'totals' => $this->totals($months),
        ];
    }

    /**
     * @param array<int, int> $availableYears
     */
    private function normalizeYear(?string $value, array $availableYears): ?int
    {
        $value = trim((string) $value);
        if ($value === '' || $value === 'all') {
            return null;
        }
        $year = (int) $value;
        if ($year <= 0) {
            return null;
        }
        if ($availableYears !== [] && !in_array($year, $availableYears, true)) {
            return null;
        }

        return $year;
    }

    /**
     * @param array{month:string,income:float,expenses:float,balance:float,count:int} $month
     * @return array<string, mixed>
     */
    private function decorateMonth(array $month): array
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $month['month'] . '-01');
        $month['label'] = $date instanceof DateTimeImmutable ? $this->germanMonthLabel($date) : $month['month'];

        return $month;
    }

    private function germanMonthLabel(DateTimeImmutable $date): string
    {
        $months = [
            1 => 'Januar',
            2 => 'Februar',
            3 => 'März',
            4 => 'April',
            5 => 'Mai',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'August',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Dezember',
        ];

        return ($months[(int) $date->format('n')] ?? $date->format('m')) . ' ' . $date->format('Y');
    }

    /**
     * @param array<int, array{month:string,income:float,expenses:float,balance:float,count:int}> $months
     * @return array{income:float,expenses:float,balance:float,count:int}
     */
    private function totals(array $months): array
    {
        $totals = ['income' => 0.0, 'expenses' => 0.0, 'balance' => 0.0, 'count' => 0];
        foreach ($months as $month) {
            $totals['income'] += (float) $month['income'];
            $totals['expenses'] += (float) $month['expenses'];
            $totals['balance'] += (float) $month['balance'];
            $totals['count'] += (int) $month['count'];
        }

        return $totals;
    }
}
