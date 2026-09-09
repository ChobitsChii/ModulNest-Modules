<?php

declare(strict_types=1);

namespace ModulNest\Banking;

use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Session;
use Modulon\Core\View;
use Modulon\Modules\Auth\AuthService;

final class BankingController
{
    public function __construct(
        private readonly BankingRepository $banking,
        private readonly BankingDashboardService $dashboard,
        private readonly BankingTransactionListService $transactionList,
        private readonly BankingMonthlyOverviewService $monthlyOverview,
        private readonly BankingRecurringRuleRepository $recurringRules,
        private readonly BankingRecurringOverviewService $recurringOverview,
        private readonly BankingRecurringSuggestionService $recurringSuggestions,
        private readonly BankingCsvImportService $csvImport,
        private readonly BankingMigrationStatusService $migrationStatus,
        private readonly BankingSubnavigationProvider $navigation,
        private readonly Session $session,
        private readonly ?AuthService $auth = null,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->auth?->currentUser();
        if (!is_array($user)) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);

        return new Response(View::render('banking/index', [
            'title' => 'Banking',
            'current_path' => $request->path(),
            'migration_status' => $this->migrationStatus->status(),
            'legacy_status' => $this->migrationStatus->legacyStatus(),
            'planned_tables' => $this->migrationStatus->plannedTables(),
            'native_snapshot' => $this->banking->nativeSnapshotForUser($userId),
            'overview' => $this->dashboard->overviewForUser($userId),
            'module_nav_items' => $this->navigation->items($request->path()),
            'module_nav_label' => 'Banking-Navigation',
            'message' => $this->session->pullFlash('banking_info'),
            'error' => $this->session->pullFlash('banking_error'),
        ]));
    }

    public function subRoute(Request $request): Response
    {
        $user = $this->auth?->currentUser();
        if (!is_array($user)) {
            return Response::redirect('/login');
        }

        $path = trim($request->path(), '/');
        if ($path === 'banking/transactions') {
            return $this->transactions($request, (int) ($user['id'] ?? 0));
        }
        if ($path === 'banking/overview') {
            return $this->monthlyOverview($request, (int) ($user['id'] ?? 0));
        }
        if ($path === 'banking/recurring') {
            return $this->recurring($request, (int) ($user['id'] ?? 0));
        }
        if ($path === 'banking/recurring/overview') {
            return $this->recurringOverview($request, (int) ($user['id'] ?? 0));
        }
        if ($path === 'banking/import') {
            return $this->import($request);
        }

        return new Response(View::render('errors/404', $this->viewData($request, ['title' => '404 Not Found'])), 404);
    }

    public function importPost(Request $request): Response
    {
        $user = $this->auth?->currentUser();
        if (!is_array($user)) {
            return Response::redirect('/login');
        }

        $file = is_array($_FILES['csv_file'] ?? null) ? $_FILES['csv_file'] : [];
        $result = $this->csvImport->importForUser((int) ($user['id'] ?? 0), $file);
        if (($result['imported'] ?? 0) > 0 || ($result['updated'] ?? 0) > 0) {
            $this->dashboard->invalidateForUser((int) ($user['id'] ?? 0));
        }
        $this->session->set('banking_import_result', $result);

        return Response::redirect('/banking/import');
    }

    public function deleteDuplicateTransactions(Request $request): Response
    {
        $user = $this->auth?->currentUser();
        if (!is_array($user)) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);
        $redirectQuery = $this->transactionRedirectQuery($request, ['dupes' => 'open']);
        $redirectUrl = '/banking/transactions' . ($redirectQuery !== '' ? '?' . $redirectQuery : '');

        $idsRaw = $request->inputRaw('delete_ids', []);
        $ids = [];
        if (is_array($idsRaw)) {
            foreach ($idsRaw as $id) {
                if (ctype_digit((string) $id)) {
                    $ids[] = (int) $id;
                }
            }
        }
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        $protectedRaw = $request->inputRaw('protected_keep_ids', []);
        $protected = [];
        if (is_array($protectedRaw)) {
            foreach ($protectedRaw as $id) {
                if (ctype_digit((string) $id)) {
                    $protected[(int) $id] = true;
                }
            }
        }
        $ids = array_values(array_filter($ids, static fn (int $id): bool => !isset($protected[$id])));

        if ($ids === []) {
            $this->session->flash('banking_info', 'Keine Duplikate zum Löschen ausgewählt.');
            return Response::redirect($redirectUrl);
        }

        $deleted = $this->transactionList->deleteDuplicatesForUser($userId, $ids);
        if ($deleted > 0) {
            $this->dashboard->invalidateForUser($userId);
        }
        $this->session->flash('banking_info', $deleted . ' Duplikat-Einträge wurden gelöscht.');

        return Response::redirect($redirectUrl);
    }

    public function recurringPost(Request $request): Response
    {
        $user = $this->auth?->currentUser();
        if (!is_array($user)) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);
        $action = (string) $request->input('action', 'save');
        $limit = max(1, min(500, (int) $request->input('limit', '100')));
        if ($action === 'detect') {
            return $this->renderRecurring(
                $request,
                $userId,
                $this->defaultRecurringFormData(),
                [],
                $this->recurringSuggestions->suggestionsForUser($userId, $limit),
                $limit
            );
        }
        if ($action === 'adopt') {
            [$formData, $errors] = $this->recurringFormData($request);
            if ($errors !== []) {
                return $this->renderRecurring($request, $userId, $formData, $errors, $this->recurringSuggestions->suggestionsForUser($userId, $limit), $limit);
            }
            $formData['id'] = null;
            $formData['rule_type'] = 'regel';
            return $this->renderRecurring($request, $userId, $formData, [], $this->recurringSuggestions->suggestionsForUser($userId, $limit), $limit);
        }
        if ($action === 'filter') {
            [$formData, $errors] = $this->recurringFormData($request);
            $formData['id'] = null;
            $formData['rule_type'] = 'filter';
            if ($errors !== []) {
                return $this->renderRecurring($request, $userId, $formData, $errors, $this->recurringSuggestions->suggestionsForUser($userId, $limit), $limit);
            }
            $this->recurringRules->saveRuleForUser($userId, $formData);
            $this->dashboard->invalidateForUser($userId);
            $this->session->flash('banking_info', 'Filter wurde gespeichert.');

            return $this->renderRecurring(
                $request,
                $userId,
                $this->defaultRecurringFormData(),
                [],
                $this->recurringSuggestions->suggestionsForUser($userId, $limit),
                $limit
            );
        }
        if ($action === 'delete') {
            $ruleId = (int) $request->input('rule_id', '0');
            $deleted = $this->recurringRules->deleteRuleForUser($userId, $ruleId);
            if ($deleted > 0) {
                $this->dashboard->invalidateForUser($userId);
            }
            $this->session->flash(
                $deleted > 0 ? 'banking_info' : 'banking_error',
                $deleted > 0 ? 'Regel wurde gelöscht.' : 'Regel wurde nicht gefunden oder gehört nicht zu deinem Benutzer.'
            );

            return Response::redirect('/banking/recurring');
        }

        [$formData, $errors] = $this->recurringFormData($request);
        if ($errors !== []) {
            return $this->renderRecurring($request, $userId, $formData, $errors);
        }

        $ruleId = $this->recurringRules->saveRuleForUser($userId, $formData);
        if ($ruleId <= 0) {
            $formData['id'] = (int) ($formData['id'] ?? 0);
            return $this->renderRecurring($request, $userId, $formData, ['Regel wurde nicht gefunden oder konnte nicht gespeichert werden.']);
        }

        $this->session->flash(
            'banking_info',
            $formData['rule_type'] === 'filter'
                ? ((int) ($formData['id'] ?? 0) > 0 ? 'Filter wurde aktualisiert.' : 'Filter wurde gespeichert.')
                : ((int) ($formData['id'] ?? 0) > 0 ? 'Regel wurde aktualisiert.' : 'Regel wurde gespeichert.')
        );
        $this->dashboard->invalidateForUser($userId);

        return Response::redirect('/banking/recurring');
    }

    private function transactions(Request $request, int $userId): Response
    {
        return new Response(View::render('banking/transactions', [
            'title' => 'Banking - Umsätze',
            'current_path' => $request->path(),
            'transaction_list' => $this->transactionList->listForUser(
                $userId,
                $request->query('year'),
                $request->query('status', 'all'),
                $request->query('booking_text', 'all'),
                $request->query('keep_mode', 'latest')
            ),
            'message' => $this->session->pullFlash('banking_info'),
            'error' => $this->session->pullFlash('banking_error'),
            'open_duplicates' => $request->query('dupes') === 'open',
            'module_nav_items' => $this->navigation->items($request->path()),
            'module_nav_label' => 'Banking-Navigation',
        ]));
    }

    private function monthlyOverview(Request $request, int $userId): Response
    {
        return new Response(View::render('banking/overview', [
            'title' => 'Banking - Monatsübersicht',
            'current_path' => $request->path(),
            'monthly_overview' => $this->monthlyOverview->overviewForUser($userId, $request->query('year', 'all')),
            'module_nav_items' => $this->navigation->items($request->path()),
            'module_nav_label' => 'Banking-Navigation',
        ]));
    }

    private function recurring(Request $request, int $userId): Response
    {
        $formData = $this->defaultRecurringFormData();
        $editId = (int) ($request->query('edit', '0') ?? '0');
        if ($editId > 0) {
            $rule = $this->recurringRules->ruleWithConditions($userId, $editId);
            if (is_array($rule)) {
                $formData = $this->recurringRuleToFormData($rule);
            }
        }

        return $this->renderRecurring($request, $userId, $formData);
    }

    /**
     * @param array<string, mixed> $formData
     * @param array<int, string> $errors
     */
    private function renderRecurring(Request $request, int $userId, array $formData, array $errors = [], ?array $suggestions = null, int $suggestionLimit = 100): Response
    {
        return new Response(View::render('banking/recurring', [
            'title' => 'Banking - Wiederkehrend',
            'current_path' => $request->path(),
            'has_native_tables' => $this->recurringRules->hasNativeTables(),
            'rules' => $this->recurringRules->rulesWithConditions($userId),
            'form_data' => $formData,
            'suggestions' => $suggestions,
            'suggestion_limit' => $suggestionLimit,
            'message' => $this->session->pullFlash('banking_info'),
            'error' => $this->session->pullFlash('banking_error'),
            'errors' => $errors,
            'module_nav_items' => $this->navigation->items($request->path()),
            'module_nav_label' => 'Banking-Navigation',
        ]));
    }

    private function recurringOverview(Request $request, int $userId): Response
    {
        return new Response(View::render('banking/recurring-overview', [
            'title' => 'Banking - Fälligkeitsstatus',
            'current_path' => $request->path(),
            'recurring_overview' => $this->recurringOverview->overviewForUser($userId, $request->query('period')),
            'module_nav_items' => $this->navigation->items($request->path()),
            'module_nav_label' => 'Banking-Navigation',
        ]));
    }

    private function import(Request $request): Response
    {
        $result = $this->session->get('banking_import_result');
        $this->session->remove('banking_import_result');

        return new Response(View::render('banking/import', [
            'title' => 'Banking - Import',
            'current_path' => $request->path(),
            'result' => $result,
            'max_upload_label' => $this->csvImport->maxUploadSizeLabel(),
            'module_nav_items' => $this->navigation->items($request->path()),
            'module_nav_label' => 'Banking-Navigation',
        ]));
    }

    /**
     * @return array{
     *   id:int|null,
     *   name:string,
     *   group_label:string,
     *   active_from:string,
     *   active_to:string,
     *   period_mode:string,
     *   due_day:string,
     *   interval_type:string,
     *   rule_type:string,
     *   conditions:array<int, array{field:string,operator:string,value:string}>
     * }
     */
    private function defaultRecurringFormData(): array
    {
        return [
            'id' => null,
            'name' => '',
            'group_label' => '',
            'active_from' => '',
            'active_to' => '',
            'period_mode' => 'buchungsmonat',
            'due_day' => '',
            'interval_type' => 'monatlich',
            'rule_type' => 'regel',
            'conditions' => [
                ['field' => 'verwendungszweck', 'operator' => 'contains', 'value' => ''],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $rule
     * @return array<string, mixed>
     */
    private function recurringRuleToFormData(array $rule): array
    {
        $conditions = [];
        foreach ((array) ($rule['conditions'] ?? []) as $condition) {
            $conditions[] = [
                'field' => (string) ($condition['field'] ?? 'verwendungszweck'),
                'operator' => (string) ($condition['operator'] ?? 'contains'),
                'value' => (string) ($condition['value'] ?? ''),
            ];
        }

        return [
            'id' => (int) ($rule['id'] ?? 0),
            'name' => (string) ($rule['name'] ?? ''),
            'group_label' => (string) ($rule['group_label'] ?? ''),
            'active_from' => (string) ($rule['active_from'] ?? ''),
            'active_to' => (string) ($rule['active_to'] ?? ''),
            'period_mode' => (string) ($rule['period_mode'] ?: 'buchungsmonat'),
            'due_day' => $rule['due_day'] !== null ? (string) $rule['due_day'] : '',
            'interval_type' => (string) ($rule['interval_type'] ?: 'monatlich'),
            'rule_type' => (string) ($rule['rule_type'] ?: 'regel'),
            'conditions' => $conditions !== [] ? $conditions : $this->defaultRecurringFormData()['conditions'],
        ];
    }

    /**
     * @return array{0:array<string, mixed>,1:array<int, string>}
     */
    private function recurringFormData(Request $request): array
    {
        $formData = [
            'id' => (int) ($request->input('rule_id', '0') ?? '0') ?: null,
            'name' => trim((string) $request->input('name', '')),
            'group_label' => trim((string) $request->input('group_label', '')),
            'active_from' => trim((string) $request->input('active_from', '')),
            'active_to' => trim((string) $request->input('active_to', '')),
            'period_mode' => (string) $request->input('period_mode', 'buchungsmonat'),
            'due_day' => trim((string) $request->input('due_day', '')),
            'interval_type' => (string) $request->input('interval', 'monatlich'),
            'rule_type' => (string) $request->input('rule_type', 'regel'),
            'conditions' => $this->recurringConditionsFromRequest($request),
        ];

        $errors = [];
        if ($formData['name'] === '') {
            $errors[] = 'Bitte einen Namen für die Regel angeben.';
        }
        if (!in_array($formData['interval_type'], ['monatlich', 'vierteljährlich', 'jährlich'], true)) {
            $errors[] = 'Bitte ein gültiges Intervall auswählen.';
            $formData['interval_type'] = 'monatlich';
        }
        if (!in_array($formData['rule_type'], ['regel', 'filter'], true)) {
            $errors[] = 'Bitte einen gültigen Regeltyp wählen.';
            $formData['rule_type'] = 'regel';
        }
        if (!in_array($formData['period_mode'], ['buchungsmonat', 'folgemonat'], true)) {
            $errors[] = 'Bitte eine gültige Monatszuordnung wählen.';
            $formData['period_mode'] = 'buchungsmonat';
        }

        foreach (['active_from' => 'Aktiv von', 'active_to' => 'Aktiv bis'] as $key => $label) {
            if ($formData[$key] !== '' && !$this->isValidDate((string) $formData[$key])) {
                $errors[] = 'Ungültiges Datum bei "' . $label . '".';
            }
        }
        if ($formData['active_from'] !== '' && $formData['active_to'] !== '' && strcmp((string) $formData['active_from'], (string) $formData['active_to']) > 0) {
            $errors[] = '"Aktiv von" darf nicht nach "Aktiv bis" liegen.';
        }

        if ($formData['due_day'] !== '') {
            $dueDay = (int) $formData['due_day'];
            if ((string) $dueDay !== (string) $formData['due_day'] || $dueDay < 1 || $dueDay > 31) {
                $errors[] = 'Der Fälligkeitstag muss zwischen 1 und 31 liegen.';
            }
        }

        if ($formData['conditions'] === []) {
            $errors[] = 'Mindestens eine Bedingung wird benötigt.';
            $formData['conditions'] = $this->defaultRecurringFormData()['conditions'];
        }

        $saveData = $formData;
        $saveData['group_label'] = $formData['group_label'] !== '' ? $formData['group_label'] : null;
        $saveData['active_from'] = $formData['active_from'] !== '' ? $formData['active_from'] : null;
        $saveData['active_to'] = $formData['active_to'] !== '' ? $formData['active_to'] : null;
        $saveData['due_day'] = $formData['due_day'] !== '' ? (int) $formData['due_day'] : null;

        return [$saveData, $errors];
    }

    /**
     * @return array<int, array{field:string,operator:string,value:string}>
     */
    private function recurringConditionsFromRequest(Request $request): array
    {
        $allowedFields = ['buchungstext', 'auftragskonto', 'verwendungszweck', 'beguenstigter_zahlungspflichtiger', 'kontonummer_iban', 'betrag'];
        $fields = $request->inputRaw('condition_field', []);
        $operators = $request->inputRaw('condition_operator', []);
        $values = $request->inputRaw('condition_value', []);
        if (!is_array($fields) || !is_array($operators) || !is_array($values)) {
            return [];
        }

        $conditions = [];
        foreach ($fields as $index => $fieldValue) {
            $field = (string) $fieldValue;
            $operator = (string) ($operators[$index] ?? '');
            $value = trim((string) ($values[$index] ?? ''));
            if ($field === '' || $operator === '' || $value === '') {
                continue;
            }
            if (!in_array($field, $allowedFields, true)) {
                continue;
            }
            if (!in_array($operator, ['contains', 'equals'], true)) {
                continue;
            }
            if ($field === 'betrag') {
                $operator = 'equals';
                $normalized = $this->normalizeRecurringAmount($value);
                if ($normalized === null) {
                    continue;
                }
                $value = $normalized;
            }
            $conditions[] = ['field' => $field, 'operator' => $operator, 'value' => $value];
        }

        return $conditions;
    }

    private function isValidDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    private function normalizeRecurringAmount(string $value): ?string
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
     * @param array<string, string> $extra
     */
    private function transactionRedirectQuery(Request $request, array $extra = []): string
    {
        $query = [];
        foreach (['year', 'status', 'booking_text', 'keep_mode'] as $key) {
            $value = $request->input($key);
            if ($value !== null && $value !== '' && $value !== 'all' && !($key === 'keep_mode' && $value === 'latest')) {
                $query[$key] = $value;
            } elseif ($key === 'year' && $value === 'all') {
                $query[$key] = 'all';
            }
        }

        return http_build_query(array_merge($query, $extra));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function viewData(Request $request, array $data): array
    {
        return array_merge([
            'current_path' => $request->path(),
        ], $data);
    }
}
