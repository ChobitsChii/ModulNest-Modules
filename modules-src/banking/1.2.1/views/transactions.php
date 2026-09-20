<?php
declare(strict_types=1);

$transactionList = is_array($transaction_list ?? null) ? $transaction_list : [];
$hasNativeTables = (bool) ($transactionList['has_native_tables'] ?? false);
$availableYears = is_array($transactionList['available_years'] ?? null) ? $transactionList['available_years'] : [];
$bookingTextOptions = is_array($transactionList['booking_text_options'] ?? null) ? $transactionList['booking_text_options'] : [];
$filters = is_array($transactionList['filters'] ?? null) ? $transactionList['filters'] : [];
$summary = is_array($transactionList['summary'] ?? null) ? $transactionList['summary'] : [];
$transactions = is_array($transactionList['transactions'] ?? null) ? $transactionList['transactions'] : [];
$duplicates = is_array($transactionList['duplicates'] ?? null) ? $transactionList['duplicates'] : [];
$limit = (int) ($transactionList['limit'] ?? 500);
$selectedYear = $filters['year'] ?? null;
$selectedStatus = (string) ($filters['status'] ?? 'all');
$selectedBookingText = (string) ($filters['booking_text'] ?? 'all');
$selectedKeepMode = (string) ($transactionList['keep_mode'] ?? 'latest');
$csrfToken = (string) ($csrf_token ?? '');
$message = (string) ($message ?? '');
$error = (string) ($error ?? '');
$openDuplicates = (bool) ($open_duplicates ?? false);
$currentYear = (int) date('Y');

$esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$money = static function (mixed $value, string $currency = 'EUR'): string {
    $amount = (float) $value;
    $prefix = $amount < 0 ? '-' : '';
    return $prefix . number_format(abs($amount), 2, ',', '.') . ' ' . ($currency === 'EUR' ? '€' : $currency);
};
$signedMoneyClass = static fn (mixed $value): string => (float) $value < 0 ? 'text-danger' : 'text-success';
$date = static function (mixed $value): string {
    $text = (string) $value;
    $time = strtotime($text);
    return $time !== false ? date('d.m.Y', $time) : $text;
};
$shorten = static function (mixed $value, int $length = 120): string {
    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') > $length ? mb_substr($text, 0, $length - 1, 'UTF-8') . '…' : $text;
    }
    return strlen($text) > $length ? substr($text, 0, $length - 1) . '...' : $text;
};
$duplicateDate = static function (array $transaction) use ($date): string {
    return $date($transaction['_date_ymd'] ?? $transaction['booking_date'] ?? $transaction['value_date'] ?? '');
};
$duplicateActionQuery = static function () use ($selectedYear, $selectedStatus, $selectedBookingText, $selectedKeepMode): string {
    $query = [];
    if ($selectedYear === null) {
        $query['year'] = 'all';
    } elseif (is_int($selectedYear)) {
        $query['year'] = (string) $selectedYear;
    }
    if ($selectedStatus !== 'all') {
        $query['status'] = $selectedStatus;
    }
    if ($selectedBookingText !== 'all') {
        $query['booking_text'] = $selectedBookingText;
    }
    if ($selectedKeepMode !== 'latest') {
        $query['keep_mode'] = $selectedKeepMode;
    }
    $query['dupes'] = 'open';

    return http_build_query($query);
};
$duplicateAction = '/banking/transactions/duplicates/delete';
$duplicateActionQueryString = $duplicateActionQuery();
if ($duplicateActionQueryString !== '') {
    $duplicateAction .= '?' . $duplicateActionQueryString;
}
?>

<?php require dirname(__DIR__) . '/partials/module-nav.php'; ?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
    <div>
        <h1 class="h3 m-0">Alle Umsätze</h1>
        <p class="text-body-secondary small mb-0">
            Zeitraum: <strong><?= $selectedYear === null ? 'Alle Jahre' : (int) $selectedYear ?></strong>
            <?php if ($selectedBookingText !== 'all'): ?>
                · Buchungstext: <strong><?= $esc($selectedBookingText) ?></strong>
            <?php endif; ?>
            <?php if ($selectedStatus !== 'all'): ?>
                · Status: <strong><?= $esc(ucfirst($selectedStatus)) ?></strong>
            <?php endif; ?>
        </p>
    </div>
</div>

<?php if (!$hasNativeTables): ?>
    <div class="alert alert-info">
        Die native Tabelle <code>banking_transactions</code> ist noch nicht vorhanden.
    </div>
<?php else: ?>
    <?php if ($message !== ''): ?>
        <div class="alert alert-success"><?= $esc($message) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= $esc($error) ?></div>
    <?php endif; ?>

    <section class="card shadow-sm border-0 app-card mb-4">
        <div class="card-body">
            <form id="banking-transaction-filters" method="get" action="/banking/transactions" class="row g-3 align-items-end">
                <div class="col-12 col-md-4 col-xl-3">
                    <label for="banking-year" class="form-label">Jahr</label>
                    <select id="banking-year" name="year" class="form-select js-banking-auto-submit">
                        <option value="all" <?= $selectedYear === null ? 'selected' : '' ?>>Alle Jahre</option>
                        <?php foreach ($availableYears as $year): ?>
                            <option value="<?= (int) $year ?>" <?= (int) $selectedYear === (int) $year ? 'selected' : '' ?>>
                                <?= (int) $year ?><?= (int) $year === $currentYear ? ' (aktuell)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4 col-xl-5">
                    <label for="banking-booking-text" class="form-label">Buchungstext</label>
                    <select id="banking-booking-text" name="booking_text" class="form-select js-banking-auto-submit">
                        <optgroup label="Buchungstext">
                            <option value="all" <?= $selectedBookingText === 'all' ? 'selected' : '' ?>>Alle Buchungstexte</option>
                            <?php foreach ($bookingTextOptions as $option): ?>
                                <?php
                                $bookingText = (string) ($option['text'] ?? '');
                                if ($bookingText === '') {
                                    continue;
                                }
                                $count = (int) ($option['count'] ?? 0);
                                ?>
                                <option value="<?= $esc($bookingText) ?>" <?= $selectedBookingText === $bookingText ? 'selected' : '' ?>>
                                    <?= $esc($shorten($bookingText, 80)) ?> (<?= number_format($count, 0, ',', '.') ?>)
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>
                <div class="col-12 col-md-4 col-xl-3">
                    <label for="banking-status" class="form-label">Status</label>
                    <select id="banking-status" name="status" class="form-select js-banking-auto-submit">
                        <optgroup label="Status">
                            <option value="all" <?= $selectedStatus === 'all' ? 'selected' : '' ?>>Alle</option>
                            <option value="gebucht" <?= $selectedStatus === 'gebucht' ? 'selected' : '' ?>>Gebucht</option>
                            <option value="vorgemerkt" <?= $selectedStatus === 'vorgemerkt' ? 'selected' : '' ?>>Vorgemerkt</option>
                        </optgroup>
                    </select>
                </div>
                <div class="col-12 col-xl-1 d-flex gap-2">
                    <noscript><button type="submit" class="btn btn-primary">Filtern</button></noscript>
                    <?php if ($selectedStatus !== 'all' || $selectedBookingText !== 'all' || $selectedYear !== $currentYear): ?>
                        <a href="/banking/transactions" class="btn btn-secondary">Zurücksetzen</a>
                    <?php endif; ?>
                </div>
                <input type="hidden" name="keep_mode" value="<?= $esc($selectedKeepMode) ?>">
            </form>
        </div>
    </section>

    <?php
    $exactDuplicateGroups = is_array($duplicates['exact'] ?? null) ? $duplicates['exact'] : [];
    $fuzzyDuplicateGroups = is_array($duplicates['fuzzy'] ?? null) ? $duplicates['fuzzy'] : [];
    $protectedKeepIds = is_array($duplicates['protected_keep_ids'] ?? null) ? $duplicates['protected_keep_ids'] : [];
    $duplicateTotal = count($exactDuplicateGroups) + count($fuzzyDuplicateGroups);
    ?>
    <section class="card shadow-sm border-0 app-card mb-4 banking-duplicate-card" id="banking-duplicate-cleanup">
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <h2 class="h5 mb-1">Duplikatprüfung</h2>
                    <p class="text-body-secondary small mb-0">Exakte und ähnliche Umsätze für die aktuelle Filterauswahl.</p>
                </div>
                <button
                    class="btn btn-outline-secondary btn-sm banking-duplicate-collapse-toggle <?= $openDuplicates ? '' : 'collapsed' ?>"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#banking-duplicate-cleanup-body"
                    aria-expanded="<?= $openDuplicates ? 'true' : 'false' ?>"
                    aria-controls="banking-duplicate-cleanup-body"
                >
                    <span class="banking-duplicate-toggle-label-show">Duplikate anzeigen</span>
                    <span class="banking-duplicate-toggle-label-hide">Duplikate ausblenden</span>
                    <span class="badge text-bg-secondary ms-2"><?= number_format(count($exactDuplicateGroups), 0, ',', '.') ?> exakt</span>
                    <span class="badge text-bg-warning ms-1"><?= number_format(count($fuzzyDuplicateGroups), 0, ',', '.') ?> ähnlich</span>
                </button>
            </div>
            <div
                id="banking-duplicate-cleanup-body"
                class="accordion-collapse collapse <?= $openDuplicates ? 'show' : '' ?>"
            >
                <div class="banking-duplicate-content mt-3 pt-3">
                    <?php if ($duplicateTotal === 0): ?>
                        <p class="text-body-secondary mb-0">Keine exakten oder ähnlichen Duplikate für die aktuelle Filterauswahl gefunden.</p>
                    <?php else: ?>
                        <form method="post" action="<?= $esc($duplicateAction) ?>" id="banking-duplicate-delete-form">
                            <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                            <input type="hidden" name="year" value="<?= $selectedYear === null ? 'all' : (int) $selectedYear ?>">
                            <input type="hidden" name="status" value="<?= $esc($selectedStatus) ?>">
                            <input type="hidden" name="booking_text" value="<?= $esc($selectedBookingText) ?>">
                            <input type="hidden" name="keep_mode" value="<?= $esc($selectedKeepMode) ?>">
                            <?php foreach ($protectedKeepIds as $keepId): ?>
                                <input type="hidden" name="protected_keep_ids[]" value="<?= (int) $keepId ?>">
                            <?php endforeach; ?>

                            <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                                <label class="small text-body-secondary mb-0" for="banking-keep-mode">Behalten:</label>
                                <select class="form-select form-select-sm w-auto" id="banking-keep-mode">
                                    <option value="latest" <?= $selectedKeepMode === 'latest' ? 'selected' : '' ?>>Neueste Umsätze</option>
                                    <option value="oldest" <?= $selectedKeepMode === 'oldest' ? 'selected' : '' ?>>Älteste Umsätze</option>
                                </select>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="banking-duplicate-toggle" data-state="none">Keine auswählen</button>
                                <span class="text-body-secondary small"><span id="banking-duplicate-selected-count">0</span> Einträge ausgewählt</span>
                                <button type="submit" class="btn btn-danger btn-sm">Ausgewählte Duplikate löschen</button>
                            </div>

                            <?php foreach ([['title' => 'Exakte Duplikate', 'groups' => $exactDuplicateGroups, 'class' => 'text-body-secondary', 'group_class' => 'banking-duplicate-group-row'], ['title' => 'Ähnliche Duplikate', 'groups' => $fuzzyDuplicateGroups, 'class' => 'text-warning', 'group_class' => 'banking-duplicate-group-row banking-duplicate-group-row-warning']] as $duplicateSection): ?>
                                <?php $groups = is_array($duplicateSection['groups']) ? $duplicateSection['groups'] : []; ?>
                                <?php if ($groups === []) continue; ?>
                                <h3 class="h6 text-uppercase <?= $esc($duplicateSection['class']) ?> mt-3"><?= $esc($duplicateSection['title']) ?></h3>
                                <div class="table-responsive mb-4">
                                    <table class="table table-hover table-sm align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Aktion</th>
                                                <th>Datum</th>
                                                <th>Buchungstext</th>
                                                <th>Verwendungszweck</th>
                                                <th>Gegenpartei</th>
                                                <th class="text-end">Betrag</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($groups as $group): ?>
                                                <?php
                                                $keep = is_array($group['keep'] ?? null) ? $group['keep'] : [];
                                                $keepAmount = (float) ($keep['amount'] ?? 0);
                                                ?>
                                                <tr class="<?= $esc($duplicateSection['group_class']) ?>">
                                                    <td colspan="7">
                                                        <strong>Gruppe <?= (int) ($group['group_no'] ?? 0) ?></strong>
                                                        · behalten bleibt ID <?= (int) ($keep['id'] ?? 0) ?>
                                                    </td>
                                                </tr>
                                                <tr class="banking-duplicate-keep-row">
                                                    <td>
                                                        <span class="badge bg-success">Behalten</span>
                                                        <span class="small ms-2 text-body-secondary">ID <?= (int) ($keep['id'] ?? 0) ?></span>
                                                    </td>
                                                    <td><?= $esc($duplicateDate($keep)) ?></td>
                                                    <td><?= $esc($shorten($keep['booking_text'] ?? '', 80)) ?></td>
                                                    <td><?= $esc($shorten($keep['purpose'] ?? '', 120)) ?></td>
                                                    <td><?= $esc($shorten($keep['counterparty_name'] ?? '', 80)) ?></td>
                                                    <td class="text-end <?= $signedMoneyClass($keepAmount) ?>"><?= $money($keepAmount, (string) ($keep['currency'] ?? 'EUR')) ?></td>
                                                    <td><?= $esc(ucfirst((string) ($keep['booking_status'] ?? ''))) ?></td>
                                                </tr>
                                                <?php foreach ((array) ($group['delete_rows'] ?? []) as $duplicateRow): ?>
                                                    <?php
                                                    $duplicate = is_array($duplicateRow) ? $duplicateRow : [];
                                                    $amount = (float) ($duplicate['amount'] ?? 0);
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <div class="form-check m-0">
                                                                <input class="form-check-input banking-duplicate-checkbox" type="checkbox" name="delete_ids[]" value="<?= (int) ($duplicate['id'] ?? 0) ?>" checked>
                                                                <label class="form-check-label small">
                                                                    <span class="badge bg-danger">Löschen</span>
                                                                    <span class="ms-2 text-body-secondary">ID <?= (int) ($duplicate['id'] ?? 0) ?></span>
                                                                </label>
                                                            </div>
                                                        </td>
                                                        <td><?= $esc($duplicateDate($duplicate)) ?></td>
                                                        <td><?= $esc($shorten($duplicate['booking_text'] ?? '', 80)) ?></td>
                                                        <td><?= $esc($shorten($duplicate['purpose'] ?? '', 120)) ?></td>
                                                        <td><?= $esc($shorten($duplicate['counterparty_name'] ?? '', 80)) ?></td>
                                                        <td class="text-end <?= $signedMoneyClass($amount) ?>"><?= $money($amount, (string) ($duplicate['currency'] ?? 'EUR')) ?></td>
                                                        <td><?= $esc(ucfirst((string) ($duplicate['booking_status'] ?? ''))) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endforeach; ?>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <div class="row g-3 mb-4">
        <div class="col-12 col-md-6 col-xl-3">
            <section class="card shadow-sm border-0 app-card h-100">
                <div class="card-body">
                    <div class="small text-body-secondary">Treffer</div>
                    <div class="fs-4 fw-semibold"><?= (int) ($summary['count'] ?? 0) ?></div>
                </div>
            </section>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <section class="card shadow-sm border-0 app-card h-100">
                <div class="card-body">
                    <div class="small text-body-secondary">Einnahmen</div>
                    <div class="fs-5 fw-semibold text-success"><?= $money($summary['income'] ?? 0) ?></div>
                </div>
            </section>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <section class="card shadow-sm border-0 app-card h-100">
                <div class="card-body">
                    <div class="small text-body-secondary">Ausgaben</div>
                    <div class="fs-5 fw-semibold text-danger"><?= $money($summary['expenses'] ?? 0) ?></div>
                </div>
            </section>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <section class="card shadow-sm border-0 app-card h-100">
                <div class="card-body">
                    <div class="small text-body-secondary">Saldo</div>
                    <div class="fs-5 fw-semibold <?= $signedMoneyClass($summary['balance'] ?? 0) ?>"><?= $money($summary['balance'] ?? 0) ?></div>
                </div>
            </section>
        </div>
    </div>

    <section class="card shadow-sm border-0 app-card">
        <div class="card-body p-0">
            <?php if ($transactions === []): ?>
                <div class="text-center py-4 text-body-secondary">Keine Umsätze vorhanden.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Buchungstag</th>
                                <th>Valuta</th>
                                <th>Buchungstext</th>
                                <th>Verwendungszweck</th>
                                <th>Begünstigter</th>
                                <th class="text-end">Betrag</th>
                                <th>Währung</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $transaction): ?>
                                <?php $amount = (float) ($transaction['amount'] ?? 0); ?>
                                <tr>
                                    <td class="text-nowrap"><?= $esc($date($transaction['booking_date'] ?? '')) ?></td>
                                    <td class="text-nowrap"><?= $esc($date($transaction['value_date'] ?? '')) ?></td>
                                    <td><?= $esc($shorten($transaction['booking_text'] ?? '', 80)) ?></td>
                                    <td>
                                        <?php $purpose = $shorten($transaction['purpose'] ?? '', 120); ?>
                                        <?php if ($purpose === ''): ?>
                                            <span class="text-body-secondary">-</span>
                                        <?php else: ?>
                                            <span title="<?= $esc($transaction['purpose'] ?? '') ?>"><?= $esc($purpose) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $esc($shorten($transaction['counterparty_name'] ?? '', 80)) ?></td>
                                    <td class="text-end text-nowrap <?= $signedMoneyClass($amount) ?>">
                                        <?= $money($amount, (string) ($transaction['currency'] ?? 'EUR')) ?>
                                    </td>
                                    <td><?= $esc($transaction['currency'] ?? 'EUR') ?></td>
                                    <td><span class="badge bg-<?= $selectedStatus === 'vorgemerkt' || ($transaction['booking_status'] ?? '') === 'vorgemerkt' ? 'warning text-dark' : 'success' ?>"><?= $esc(ucfirst((string) ($transaction['booking_status'] ?? ''))) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="5" class="text-end">Gesamtsumme</th>
                                <th class="text-end <?= $signedMoneyClass($summary['balance'] ?? 0) ?>"><?= $money($summary['balance'] ?? 0) ?></th>
                                <th colspan="2"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('banking-transaction-filters');
    if (!form) {
        return;
    }

    form.querySelectorAll('.js-banking-auto-submit').forEach(function (field) {
        field.addEventListener('change', function () {
            form.requestSubmit();
        });
    });

    const keepMode = document.getElementById('banking-keep-mode');
    if (keepMode) {
        keepMode.addEventListener('change', function () {
            const url = new URL(window.location.href);
            url.searchParams.set('keep_mode', this.value);
            url.searchParams.set('dupes', 'open');
            window.location.href = url.toString();
        });
    }

    const duplicateForm = document.getElementById('banking-duplicate-delete-form');
    const checkboxes = Array.from(document.querySelectorAll('.banking-duplicate-checkbox'));
    const toggle = document.getElementById('banking-duplicate-toggle');
    const selectedCount = document.getElementById('banking-duplicate-selected-count');
    const updateSelectedCount = function () {
        if (!selectedCount || !toggle) {
            return;
        }
        const count = checkboxes.filter(function (checkbox) { return checkbox.checked; }).length;
        selectedCount.textContent = String(count);
        const allChecked = count === checkboxes.length;
        toggle.textContent = allChecked ? 'Keine auswählen' : 'Alles auswählen';
        toggle.dataset.state = allChecked ? 'none' : 'all';
    };
    if (toggle) {
        toggle.addEventListener('click', function () {
            const shouldCheck = toggle.dataset.state === 'all';
            checkboxes.forEach(function (checkbox) {
                checkbox.checked = shouldCheck;
            });
            updateSelectedCount();
        });
    }
    checkboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', updateSelectedCount);
    });
    if (duplicateForm) {
        duplicateForm.addEventListener('submit', function (event) {
            const count = checkboxes.filter(function (checkbox) { return checkbox.checked; }).length;
            if (count === 0 || !window.confirm(count + ' ausgewählte Duplikate wirklich löschen?')) {
                event.preventDefault();
            }
        });
    }
    updateSelectedCount();
});
</script>
