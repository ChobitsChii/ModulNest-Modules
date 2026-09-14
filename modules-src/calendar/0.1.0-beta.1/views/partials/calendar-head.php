<?php
declare(strict_types=1);

/**
 * Gemeinsames Partial: Subnavigation (Tag/Woche/Monat), Flash-Meldungen und CSS/JS.
 * Wird von day.php, week.php, month.php und appointment-form.php eingebunden.
 *
 * Verfügbare Variablen (vom Controller + globalem View-Composer):
 *   - $csrf_token   (global composer)
 *   - $base_path
 *   - $view         ('day' | 'week' | 'month' | 'form')
 *   - $currentUser  (fallback, nicht zwingend benötigt)
 */

$calCsrf = (string) ($csrf_token ?? '');
$calBasePath = (string) ($base_path ?? '/calendar');
$calView = (string) ($view ?? 'day');
$calInfo = (string) ($calendar_info ?? '');
$calError = (string) ($calendar_error ?? '');
?>
<input type="hidden" name="_csrf" value="<?= htmlspecialchars($calCsrf, ENT_QUOTES, 'UTF-8') ?>">
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <ul class="nav nav-pills">
        <li class="nav-item">
            <a class="nav-link <?= $calView === 'day' ? 'active' : '' ?>" href="<?= $calBasePath ?>/day">Tag</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $calView === 'week' ? 'active' : '' ?>" href="<?= $calBasePath ?>/week">Woche</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $calView === 'month' ? 'active' : '' ?>" href="<?= $calBasePath ?>/month">Monat</a>
        </li>
    </ul>
    <a class="btn btn-primary" href="<?= $calBasePath ?>/appointment/create">Neuer Termin</a>
</div>

<?php if ($calInfo !== ''): ?>
    <div class="alert alert-success"><?= htmlspecialchars($calInfo, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if ($calError !== ''): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($calError, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<style><?php require __DIR__ . '/../../assets/css/calendar.css'; ?></style>
<script><?php require __DIR__ . '/../../assets/js/calendar.js'; ?></script>
