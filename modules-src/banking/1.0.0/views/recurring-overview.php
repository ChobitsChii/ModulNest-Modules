<?php
declare(strict_types=1);

$overview = is_array($recurring_overview ?? null) ? $recurring_overview : [];
$hasNativeTables = (bool) ($overview['has_native_tables'] ?? false);
$selectedPeriod = is_array($overview['selected_period'] ?? null) ? $overview['selected_period'] : [];
$periodOptions = is_array($overview['period_options'] ?? null) ? $overview['period_options'] : [];
$months = is_array($periodOptions['months'] ?? null) ? $periodOptions['months'] : [];
$years = is_array($periodOptions['years'] ?? null) ? $periodOptions['years'] : [];
$items = is_array($overview['items'] ?? null) ? $overview['items'] : [];
$summary = is_array($overview['summary'] ?? null) ? $overview['summary'] : [];

$esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$money = static function (mixed $value): string {
    $amount = (float) $value;
    $prefix = $amount < 0 ? '-' : '';
    return $prefix . number_format(abs($amount), 2, ',', '.') . ' €';
};
$signedMoneyClass = static fn (mixed $value): string => (float) $value < 0 ? 'text-danger' : 'text-success';
$statusClass = static fn (string $status): string => match ($status) {
    'offen' => 'bg-warning text-dark',
    'gebucht' => 'bg-success',
    default => 'bg-secondary',
};
$rowClass = static fn (string $interval): string => match ($interval) {
    'vierteljährlich' => 'banking-recurring-row-quarterly',
    'jährlich' => 'banking-recurring-row-yearly',
    default => '',
};
$date = static function (mixed $value): string {
    $text = (string) $value;
    if ($text === '') {
        return '-';
    }
    $time = strtotime($text);
    return $time !== false ? date('d.m.Y', $time) : $text;
};
?>

<?php require dirname(__DIR__) . '/partials/module-nav.php'; ?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
    <div>
        <h1 class="h4 mb-1">Status wiederkehrender Zahlungen</h1>
        <p class="text-body-secondary mb-0">Überblick, welche Regeln im gewählten Zeitraum bereits Buchungen gefunden haben.</p>
    </div>
    <form class="d-flex gap-2" method="get" action="/banking/recurring/overview">
        <label class="visually-hidden" for="banking-period">Zeitraum</label>
        <select id="banking-period" name="period" class="form-select" onchange="this.form.submit()">
            <optgroup label="Monate">
                <?php foreach ($months as $month): ?>
                    <option value="<?= $esc($month['value'] ?? '') ?>" <?= ($selectedPeriod['value'] ?? '') === ($month['value'] ?? '') ? 'selected' : '' ?>>
                        <?= $esc($month['label'] ?? '') ?>
                    </option>
                <?php endforeach; ?>
            </optgroup>
            <optgroup label="Jahre">
                <?php foreach ($years as $year): ?>
                    <option value="<?= $esc($year['value'] ?? '') ?>" <?= ($selectedPeriod['value'] ?? '') === ($year['value'] ?? '') ? 'selected' : '' ?>>
                        <?= $esc($year['label'] ?? '') ?>
                    </option>
                <?php endforeach; ?>
            </optgroup>
        </select>
    </form>
</div>

<?php if (!$hasNativeTables): ?>
    <div class="alert alert-info">Die nativen Banking-Tabellen sind noch nicht vollständig vorhanden.</div>
<?php else: ?>
    <section class="card shadow-sm border-0 app-card">
        <div class="card-body">
            <h2 class="h5 mb-3">Zeitraum <?= $esc($selectedPeriod['label'] ?? '') ?></h2>
            <div class="banking-recurring-status-summary mb-4">
                <div>
                    <div class="text-body-secondary small">Offener Betrag für den ausgewählten Zeitraum</div>
                    <div class="fs-4 fw-semibold <?= $signedMoneyClass($summary['open_amount'] ?? 0) ?>">
                        <?= $money($summary['open_amount'] ?? 0) ?>
                    </div>
                </div>
                <div class="banking-recurring-status-meta">
                    <span class="text-success small">Einnahmen <?= $money($summary['open_income'] ?? 0) ?></span>
                    <span class="text-danger small">Ausgaben <?= $money($summary['open_expense'] ?? 0) ?></span>
                    <span class="text-warning small"><?= (int) ($summary['open'] ?? 0) ?> offen</span>
                    <span class="text-success small"><?= (int) ($summary['booked'] ?? 0) ?> gebucht</span>
                    <span class="text-body-secondary small"><?= (int) ($summary['not_due'] ?? 0) ?> nicht fällig</span>
                </div>
            </div>

            <?php if ($items === []): ?>
                <div class="alert alert-info mb-0">Keine wiederkehrenden Regeln für deinen Benutzer gefunden.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Regel</th>
                                <th>Status</th>
                                <th class="text-end">Treffer</th>
                                <th>Letzte Buchung</th>
                                <th class="text-end">Letzter Betrag</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <?php
                                $status = (string) ($item['status'] ?? 'nicht fällig');
                                $matches = is_array($item['matches'] ?? null) ? $item['matches'] : [];
                                ?>
                                <tr class="<?= $rowClass((string) ($item['interval_type'] ?? 'monatlich')) ?>">
                                    <td>
                                        <strong><?= $esc($item['label'] ?? '') ?></strong><br>
                                        <span class="text-body-secondary small"><?= $esc($item['interval_type'] ?: 'monatlich') ?></span>
                                        <span class="text-body-secondary small d-block">Aktiv: <?= $esc($item['active_from'] ?: 'immer') ?> bis <?= $esc($item['active_to'] ?: 'offen') ?></span>
                                        <span class="text-body-secondary small d-block">
                                            Zuordnung: <?= $esc($item['period_mode'] ?: 'buchungsmonat') ?>
                                            <?php if ($item['due_day'] !== null): ?> · Fälligkeit: <?= (int) $item['due_day'] ?>.<?php endif; ?>
                                        </span>
                                    </td>
                                    <td><span class="badge <?= $statusClass($status) ?>"><?= $esc($status) ?></span></td>
                                    <td class="text-end">
                                        <?php if ($matches !== []): ?>
                                            <details class="banking-recurring-match-details">
                                                <summary><?= count($matches) ?> Treffer</summary>
                                                <ul class="list-unstyled small mb-0 mt-2 text-start">
                                                    <?php foreach ($matches as $match): ?>
                                                        <?php
                                                        $matchDate = $match['effective_date'] ?? $match['booking_date'] ?? $match['value_date'] ?? $match['created_at'] ?? '';
                                                        $matchAmount = (float) ($match['amount'] ?? 0);
                                                        $matchText = trim((string) ($match['purpose'] ?? ''));
                                                        if ($matchText === '') {
                                                            $matchText = trim((string) ($match['booking_text'] ?? ''));
                                                        }
                                                        ?>
                                                        <li>
                                                            <?= $esc($date($matchDate)) ?> ·
                                                            <span class="<?= $signedMoneyClass($matchAmount) ?>"><?= $money($matchAmount) ?></span>
                                                            <?php if ($matchText !== ''): ?>
                                                                · <span class="text-body-secondary"><?= $esc($matchText) ?></span>
                                                            <?php endif; ?>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </details>
                                        <?php elseif ($status === 'nicht fällig'): ?>
                                            <span class="text-body-secondary">Nicht fällig</span>
                                        <?php else: ?>
                                            <span class="text-body-secondary">Keine Treffer</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $esc($date($item['latest_booking_date'] ?? '')) ?></td>
                                    <td class="text-end <?= $item['latest_amount'] === null ? 'text-body-secondary' : $signedMoneyClass($item['latest_amount']) ?>">
                                        <?= $item['latest_amount'] === null ? '-' : $money($item['latest_amount']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <p class="text-body-secondary small mb-0">Hinweis: Die Übersicht prüft vorhandene native Buchungen und leitet offene Beträge aus den importierten Regeln ab.</p>
        </div>
    </section>
<?php endif; ?>
