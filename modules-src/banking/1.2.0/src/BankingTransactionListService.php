<?php

declare(strict_types=1);

namespace ModulNest\Banking;

final class BankingTransactionListService
{
    public function __construct(
        private readonly BankingTransactionRepository $transactions,
        private readonly BankingDuplicateDetectionService $duplicates,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function listForUser(int $userId, ?string $yearInput, ?string $statusInput, ?string $bookingTextInput, ?string $keepModeInput): array
    {
        $availableYears = $this->transactions->availableYears($userId);
        $selectedYear = $this->normalizeYear($yearInput, $availableYears);
        $selectedStatus = $this->normalizeStatus($statusInput);
        $selectedBookingText = $this->normalizeBookingText($bookingTextInput);
        $selectedKeepMode = $this->normalizeKeepMode($keepModeInput);
        $filters = [
            'year' => $selectedYear,
            'status' => $selectedStatus,
            'booking_text' => $selectedBookingText,
        ];
        $bookingTextOptionFilters = [
            'year' => $selectedYear,
            'status' => $selectedStatus,
            'booking_text' => 'all',
        ];

        return [
            'has_native_tables' => $this->transactions->hasNativeTables(),
            'available_years' => $this->yearOptions($availableYears),
            'booking_text_options' => $this->transactions->availableBookingTexts($userId, $bookingTextOptionFilters),
            'filters' => $filters,
            'keep_mode' => $selectedKeepMode,
            'summary' => $this->transactions->filteredSummary($userId, $filters),
            'transactions' => $this->transactions->transactionsForList($userId, $filters),
            'duplicates' => $this->duplicates->detect($this->transactions->transactionsForDuplicateDetection($userId, $filters), $selectedKeepMode),
            'limit' => 500,
        ];
    }

    /**
     * @param array<int, int> $ids
     */
    public function deleteDuplicatesForUser(int $userId, array $ids): int
    {
        return $this->transactions->deleteTransactionsForUser($userId, $ids);
    }

    /**
     * @param array<int, int> $availableYears
     */
    private function normalizeYear(?string $value, array $availableYears): ?int
    {
        if ($value === null) {
            return (int) date('Y');
        }

        $value = trim((string) $value);
        if ($value === 'all') {
            return null;
        }

        $year = (int) $value;
        if ($year <= 0) {
            return null;
        }

        if ($year !== (int) date('Y') && $availableYears !== [] && !in_array($year, $availableYears, true)) {
            return null;
        }

        return $year;
    }

    private function normalizeStatus(?string $value): string
    {
        $status = strtolower(trim((string) $value));
        return in_array($status, ['gebucht', 'vorgemerkt'], true) ? $status : 'all';
    }

    private function normalizeBookingText(?string $value): string
    {
        $text = trim((string) $value);
        return $text === '' || $text === 'all' ? 'all' : $text;
    }

    private function normalizeKeepMode(?string $value): string
    {
        $mode = strtolower(trim((string) $value));
        return in_array($mode, ['latest', 'oldest'], true) ? $mode : 'latest';
    }

    /**
     * @param array<int, int> $availableYears
     * @return array<int, int>
     */
    private function yearOptions(array $availableYears): array
    {
        $currentYear = (int) date('Y');
        $years = array_values(array_unique(array_merge([$currentYear], $availableYears)));
        rsort($years, SORT_NUMERIC);

        return $years;
    }
}
