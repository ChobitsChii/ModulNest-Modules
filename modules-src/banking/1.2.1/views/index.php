<?php
declare(strict_types=1);

$overview = is_array($overview ?? null) ? $overview : [];
$message = (string) ($message ?? '');
$error = (string) ($error ?? '');
$hasNativeTables = (bool) ($overview['has_native_tables'] ?? false);
$hasData = (bool) ($overview['has_data'] ?? false);
$counts = is_array($overview['counts'] ?? null) ? $overview['counts'] : [];
$totals = is_array($overview['totals'] ?? null) ? $overview['totals'] : [];
$currentMonth = is_array($overview['current_month'] ?? null) ? $overview['current_month'] : [];
$currentMonthSummary = is_array($currentMonth['summary'] ?? null) ? $currentMonth['summary'] : [];
$latestTransactions = is_array($overview['latest_transactions'] ?? null) ? $overview['latest_transactions'] : [];
$topCategories = is_array($overview['top_categories'] ?? null) ? $overview['top_categories'] : [];
$recurring = is_array($overview['recurring'] ?? null) ? $overview['recurring'] : [];
$recurringCurrent = is_array($recurring['current'] ?? null) ? $recurring['current'] : [];
$monthlyComparison = is_array($overview['monthly_comparison'] ?? null) ? $overview['monthly_comparison'] : [];

$money = static function (mixed $value, string $currency = 'EUR'): string {
    $amount = (float) $value;
    $suffix = strtoupper($currency) === 'EUR' ? '€' : $currency;
    return number_format($amount, 2, ',', '.') . '&nbsp;' . htmlspecialchars($suffix, ENT_QUOTES, 'UTF-8');
};

$integer = static fn (mixed $value): string => number_format((int) $value, 0, ',', '.');
$signedMoneyClass = static fn (mixed $value): string => (float) $value < 0 ? 'text-danger' : 'text-success';
$esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$date = static function (mixed $value): string {
    $time = strtotime((string) $value);
    return $time !== false ? date('d.m.Y', $time) : (string) $value;
};
?>

<?php require dirname(__DIR__) . '/partials/module-nav.php'; ?>

<div class="banking-dashboard">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Willkommen zurück!</h1>
            <p class="text-body-secondary mb-0">Schneller Überblick über deine Finanzdaten und Vorgänge.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-primary" href="/banking/import">Neue CSV importieren</a>
            <a class="btn btn-outline-primary" href="/banking/transactions">Alle Umsätze anzeigen</a>
        </div>
    </div>

<?php if ($message !== ''): ?>
    <div class="alert alert-success"><?= $esc($message) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= $esc($error) ?></div>
<?php endif; ?>

<?php if (!$hasNativeTables): ?>
    <div class="alert alert-info">
        Die nativen Banking-Tabellen sind noch nicht vorhanden. Bitte zuerst Schema und Migration vorbereiten.
    </div>
<?php elseif (!$hasData): ?>
    <div class="alert alert-info">
        Für deinen Benutzer wurden noch keine Banking-Daten importiert. Die Übersicht bleibt deshalb leer.
    </div>
<?php else: ?>
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
            <section class="card shadow-sm border-0 app-card h-100">
                <div class="card-body">
                    <p class="text-body-secondary text-uppercase small mb-1">Umsätze gesamt</p>
                    <h2 class="h4 mb-0"><?= $integer($counts['total'] ?? 0) ?></h2>
                </div>
            </section>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <section class="card shadow-sm border-0 app-card h-100">
                <div class="card-body">
                    <p class="text-body-secondary text-uppercase small mb-1">Gebuchte Umsätze</p>
                    <h2 class="h4 text-success mb-0"><?= $integer($counts['booked'] ?? 0) ?></h2>
                </div>
            </section>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <section class="card shadow-sm border-0 app-card h-100">
                <div class="card-body">
                    <p class="text-body-secondary text-uppercase small mb-1">Vorgemerkt</p>
                    <h2 class="h4 text-warning mb-0"><?= $integer($counts['pending'] ?? 0) ?></h2>
                </div>
            </section>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <section class="card shadow-sm border-0 app-card h-100">
                <div class="card-body">
                    <p class="text-body-secondary text-uppercase small mb-1">Saldo gesamt</p>
                    <h2 class="h4 <?= $signedMoneyClass($totals['balance'] ?? 0) ?> mb-0"><?= $money($totals['balance'] ?? 0) ?></h2>
                    <div class="text-body-secondary small">
                        <div>Einnahmen <span class="text-success fw-semibold"><?= $money($totals['income'] ?? 0) ?></span></div>
                        <div>Ausgaben <span class="text-danger fw-semibold"><?= $money($totals['expenses'] ?? 0) ?></span></div>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-md-4">
            <section class="card shadow-sm border-0 app-card h-100">
                <div class="card-body">
                    <p class="text-body-secondary text-uppercase small mb-1">Erwartete Einnahmen (Monat)</p>
                    <h2 class="h4 text-success mb-0"><?= $money($recurringCurrent['expected_income'] ?? 0) ?></h2>
                </div>
            </section>
        </div>
        <div class="col-12 col-md-4">
            <section class="card shadow-sm border-0 app-card h-100">
                <div class="card-body">
                    <p class="text-body-secondary text-uppercase small mb-1">Erwartete Ausgaben (Monat)</p>
                    <h2 class="h4 text-danger mb-0"><?= $money($recurringCurrent['expected_expense'] ?? 0) ?></h2>
                </div>
            </section>
        </div>
        <div class="col-12 col-md-4">
            <section class="card shadow-sm border-0 app-card h-100">
                <div class="card-body">
                    <p class="text-body-secondary text-uppercase small mb-1">Erwarteter Saldo (Monat)</p>
                    <h2 class="h4 <?= $signedMoneyClass($recurringCurrent['expected_net'] ?? 0) ?> mb-0"><?= $money($recurringCurrent['expected_net'] ?? 0) ?></h2>
                    <p class="text-body-secondary small mb-0"><?= $integer($recurringCurrent['active_rules'] ?? 0) ?> aktive Regeln</p>
                </div>
            </section>
        </div>
    </div>

    <?php $currentRecurringLabel = (string) ($recurringCurrent['label'] ?? date('m.Y')); ?>
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-4">
            <section class="card shadow-sm border-0 app-card h-100">
                <div class="card-body">
                    <p class="text-body-secondary text-uppercase small mb-1">Offene wiederkehrende Beträge (<?= $esc($currentRecurringLabel) ?>)</p>
                    <h2 class="h4 <?= $signedMoneyClass($recurringCurrent['open_total'] ?? 0) ?> mb-0"><?= $money($recurringCurrent['open_total'] ?? 0) ?></h2>
                    <p class="text-body-secondary small mb-0">
                        <span class="text-success">Einnahmen <?= $money($recurringCurrent['open_income'] ?? 0) ?></span> ·
                        <span class="text-danger">Ausgaben <?= $money($recurringCurrent['open_expense'] ?? 0) ?></span><br>
                        <span><?= $integer($recurringCurrent['open_count'] ?? 0) ?> offene Regeln</span>
                    </p>
                </div>
            </section>
        </div>
        <div class="col-12 col-md-4">
            <section class="card shadow-sm border-0 app-card h-100">
                <div class="card-body">
                    <p class="text-body-secondary text-uppercase small mb-1">Gebuchte wiederkehrende Beträge (<?= $esc($currentRecurringLabel) ?>)</p>
                    <h2 class="h4 <?= $signedMoneyClass($recurringCurrent['booked_total'] ?? 0) ?> mb-0"><?= $money($recurringCurrent['booked_total'] ?? 0) ?></h2>
                    <p class="text-body-secondary small mb-0">
                        <span class="text-success">Einnahmen <?= $money($recurringCurrent['booked_income'] ?? 0) ?></span> ·
                        <span class="text-danger">Ausgaben <?= $money($recurringCurrent['booked_expense'] ?? 0) ?></span><br>
                        <span><?= $integer($recurringCurrent['booked_count'] ?? 0) ?> gebuchte Regeln</span>
                    </p>
                </div>
            </section>
        </div>
        <div class="col-12 col-md-4">
            <section class="card shadow-sm border-0 app-card h-100">
                <div class="card-body">
                    <p class="text-body-secondary text-uppercase small mb-1">Nicht fällige Regeln (<?= $esc($currentRecurringLabel) ?>)</p>
                    <h2 class="h4 text-body-secondary mb-0"><?= $integer($recurringCurrent['not_due_count'] ?? 0) ?></h2>
                    <p class="text-body-secondary small mb-0">Regeln, die im aktuellen Monat keine Zahlung erwarten.</p>
                </div>
            </section>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-12">
            <section class="card shadow-sm border-0 app-card">
                <div class="card-body">
                    <h2 class="h5 mb-3">Monatliche Übersicht (letzte 12 Monate)</h2>
                    <?php if ($monthlyComparison === []): ?>
                        <p class="text-body-secondary mb-0">Keine Daten vorhanden.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Monat</th>
                                        <th class="text-end">Ist Einnahmen</th>
                                        <th class="text-end">Ist Ausgaben</th>
                                        <th class="text-end">Ist Saldo</th>
                                        <th class="text-end border-start" style="border-left-width:2px;">Regel Einnahmen</th>
                                        <th class="text-end">Regel Ausgaben</th>
                                        <th class="text-end">Regel Saldo</th>
                                        <th class="text-end">
                                            <span class="d-inline-flex align-items-center justify-content-end gap-1">
                                                Übrig
                                                <button
                                                    type="button"
                                                    class="btn btn-link btn-sm p-0 lh-1 text-decoration-none text-body-secondary"
                                                    data-bs-toggle="tooltip"
                                                    data-bs-placement="top"
                                                    data-bs-custom-class="banking-tooltip"
                                                    data-bs-html="true"
                                                    title="Berechnung: <strong>Ist Saldo - Regel Saldo</strong><br>Negativ = weniger übrig als laut Regeln erwartet."
                                                    aria-label="Info zur Berechnung von Übrig"
                                                >ⓘ</button>
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($monthlyComparison as $month): ?>
                                        <tr>
                                            <td><?= $esc($month['label'] ?? '') ?></td>
                                            <td class="text-end text-success"><?= $money($month['income'] ?? 0) ?></td>
                                            <td class="text-end text-danger"><?= $money($month['expense'] ?? 0) ?></td>
                                            <td class="text-end <?= $signedMoneyClass($month['net'] ?? 0) ?>"><?= $money($month['net'] ?? 0) ?></td>
                                            <td class="text-end border-start text-success" style="border-left-width:2px;"><?= $money($month['expected_income'] ?? 0) ?></td>
                                            <td class="text-end text-danger"><?= $money($month['expected_expense'] ?? 0) ?></td>
                                            <td class="text-end <?= $signedMoneyClass($month['expected_net'] ?? 0) ?>"><?= $money($month['expected_net'] ?? 0) ?></td>
                                            <td class="text-end <?= $signedMoneyClass($month['difference'] ?? 0) ?>"><?= $money($month['difference'] ?? 0) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-12 col-xl-7">
            <section class="card shadow-sm border-0 app-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="h5 mb-0">Letzte Buchungen</h2>
                        <a class="text-decoration-none small" href="/banking/transactions">Alle anzeigen</a>
                    </div>
                    <?php if ($latestTransactions === []): ?>
                        <p class="text-body-secondary mb-0">Keine Buchungen vorhanden.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Datum</th>
                                        <th>Text</th>
                                        <th>Verwendungszweck</th>
                                        <th class="text-end">Betrag</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($latestTransactions as $transaction): ?>
                                        <?php
                                        $amount = (float) ($transaction['amount'] ?? 0);
                                        $title = (string) ($transaction['counterparty_name'] ?? '');
                                        if ($title === '') {
                                            $title = (string) ($transaction['booking_text'] ?? '');
                                        }
                                        if ($title === '') {
                                            $title = (string) ($transaction['purpose'] ?? 'Buchung');
                                        }
                                        ?>
                                        <tr>
                                            <td class="text-nowrap"><?= $esc($date($transaction['booking_date'] ?? '')) ?></td>
                                            <td><?= $esc($title) ?></td>
                                            <td><?= $esc($transaction['purpose'] ?? '') ?></td>
                                            <td class="text-end fw-semibold <?= $signedMoneyClass($amount) ?>">
                                                <?= $money($amount, (string) ($transaction['currency'] ?? 'EUR')) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <div class="col-12 col-xl-5">
            <section class="card shadow-sm border-0 app-card h-100">
                <div class="card-body">
                    <h2 class="h5 mb-3">Top Kategorien</h2>
                    <?php if ($topCategories === []): ?>
                        <p class="text-body-secondary mb-0">Keine Ausgabenkategorien vorhanden.</p>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($topCategories as $category): ?>
                                <?php
                                $categoryName = trim((string) ($category['name'] ?? ''));
                                if ($categoryName === '') {
                                    $categoryName = 'Ohne Kategorie';
                                }
                                ?>
                                <div class="list-group-item bg-transparent px-0 d-flex justify-content-between gap-3">
                                    <div>
                                        <div class="fw-semibold"><?= $esc($categoryName) ?></div>
                                        <div class="small text-body-secondary"><?= $integer($category['count_rows'] ?? 0) ?> Buchungen</div>
                                    </div>
                                    <div class="fw-semibold text-danger text-nowrap"><?= $money($category['total'] ?? 0) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
<?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof bootstrap === 'undefined' || typeof bootstrap.Tooltip !== 'function') {
        return;
    }

    document.querySelectorAll('.banking-dashboard [data-bs-toggle="tooltip"]').forEach(function (element) {
        bootstrap.Tooltip.getOrCreateInstance(element, {
            placement: 'top',
            fallbackPlacements: ['top'],
            boundary: 'viewport'
        });
    });
});
</script>
