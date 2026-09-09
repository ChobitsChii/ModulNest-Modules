<?php
declare(strict_types=1);

$overview = is_array($monthly_overview ?? null) ? $monthly_overview : [];
$hasNativeTables = (bool) ($overview['has_native_tables'] ?? false);
$availableYears = is_array($overview['available_years'] ?? null) ? $overview['available_years'] : [];
$selectedYear = $overview['selected_year'] ?? null;
$months = is_array($overview['months'] ?? null) ? $overview['months'] : [];
$totals = is_array($overview['totals'] ?? null) ? $overview['totals'] : [];

$esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$money = static function (mixed $value): string {
    $amount = (float) $value;
    $prefix = $amount < 0 ? '-' : '';
    return $prefix . number_format(abs($amount), 2, ',', '.') . ' €';
};
$signedMoneyClass = static fn (mixed $value): string => (float) $value < 0 ? 'text-danger' : 'text-success';
?>

<?php require dirname(__DIR__) . '/partials/module-nav.php'; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h4 mb-1">Entwicklung nach Monaten</h1>
        <p class="text-body-secondary mb-0">Monatliche Einnahmen, Ausgaben und Salden aus den importierten Umsätzen.</p>
    </div>
</div>

<?php if (!$hasNativeTables): ?>
    <div class="alert alert-info">Die native Tabelle <code>banking_transactions</code> ist noch nicht vorhanden.</div>
<?php else: ?>
    <section class="card shadow-sm border-0 app-card mb-4">
        <div class="card-body">
            <form method="get" action="/banking/overview" class="row g-3 align-items-end" id="banking-overview-filter">
                <div class="col-12 col-md-5">
                    <label for="banking-overview-year" class="form-label">Jahr</label>
                    <select id="banking-overview-year" name="year" class="form-select js-banking-overview-auto-submit">
                        <option value="all" <?= $selectedYear === null ? 'selected' : '' ?>>Alle Jahre</option>
                        <?php foreach ($availableYears as $year): ?>
                            <option value="<?= (int) $year ?>" <?= (int) $selectedYear === (int) $year ? 'selected' : '' ?>>
                                <?= (int) $year ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-7 d-flex gap-2">
                    <noscript><button type="submit" class="btn btn-primary">Anzeigen</button></noscript>
                    <?php if ($selectedYear !== null): ?>
                        <a href="/banking/overview" class="btn btn-outline-secondary">Zurücksetzen</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </section>

    <section class="card shadow-sm border-0 app-card">
        <div class="card-body">
            <?php if ($months === []): ?>
                <p class="text-body-secondary mb-0">Noch keine Umsätze vorhanden, daher kann keine Monatsübersicht angezeigt werden.</p>
            <?php else: ?>
                <div class="banking-sticky-table-shell">
                    <table class="table align-middle banking-sticky-table">
                        <thead class="table-light">
                            <tr>
                                <th>Monat</th>
                                <th class="text-end">Einnahmen</th>
                                <th class="text-end">Ausgaben</th>
                                <th class="text-end">Saldo</th>
                                <th class="text-end">Buchungen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($months as $month): ?>
                                <tr>
                                    <td><?= $esc($month['label'] ?? $month['month'] ?? '') ?></td>
                                    <td class="text-end text-success"><?= $money($month['income'] ?? 0) ?></td>
                                    <td class="text-end text-danger"><?= $money(-abs((float) ($month['expenses'] ?? 0))) ?></td>
                                    <td class="text-end <?= $signedMoneyClass($month['balance'] ?? 0) ?>"><?= $money($month['balance'] ?? 0) ?></td>
                                    <td class="text-end"><?= number_format((int) ($month['count'] ?? 0), 0, ',', '.') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="banking-table-summary-row">
                            <?php $monthCount = count($months); ?>
                            <tr>
                                <th>Summe (<?= number_format($monthCount, 0, ',', '.') ?> Monate)</th>
                                <th class="text-end text-success"><?= $money($totals['income'] ?? 0) ?></th>
                                <th class="text-end text-danger"><?= $money(-abs((float) ($totals['expenses'] ?? 0))) ?></th>
                                <th class="text-end <?= $signedMoneyClass($totals['balance'] ?? 0) ?>"><?= $money($totals['balance'] ?? 0) ?></th>
                                <th class="text-end"><?= number_format((int) ($totals['count'] ?? 0), 0, ',', '.') ?></th>
                            </tr>
                            <tr>
                                <th>Durchschnitt pro Monat</th>
                                <th class="text-end text-success"><?= $money($monthCount > 0 ? (float) ($totals['income'] ?? 0) / $monthCount : 0) ?></th>
                                <th class="text-end text-danger"><?= $money($monthCount > 0 ? -abs((float) ($totals['expenses'] ?? 0)) / $monthCount : 0) ?></th>
                                <th class="text-end <?= $signedMoneyClass($monthCount > 0 ? (float) ($totals['balance'] ?? 0) / $monthCount : 0) ?>"><?= $money($monthCount > 0 ? (float) ($totals['balance'] ?? 0) / $monthCount : 0) ?></th>
                                <th class="text-end"><?= number_format($monthCount > 0 ? (int) round((int) ($totals['count'] ?? 0) / $monthCount) : 0, 0, ',', '.') ?></th>
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
    const form = document.getElementById('banking-overview-filter');
    if (!form) {
        return;
    }

    form.querySelectorAll('.js-banking-overview-auto-submit').forEach(function (field) {
        field.addEventListener('change', function () {
            form.requestSubmit();
        });
    });
});
</script>
