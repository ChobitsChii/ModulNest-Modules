<?php
declare(strict_types=1);

$movies = is_array($movies ?? null) ? $movies : [];
$fields = is_array($fields ?? null) ? $fields : [];
$message = (string) ($message ?? '');
$error = (string) ($error ?? '');
?>
<link rel="stylesheet" href="https://cdn.datatables.net/2.0.8/css/dataTables.dataTables.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.4/css/lightbox.min.css">

<section class="app-card p-4 sneak-preview-page">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h4 mb-1">Sneak Preview / Admin</h1>
            <p class="text-muted mb-0">Filme, Poster und Anzeige-Einstellungen verwalten.</p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-self-lg-start">
            <a class="btn btn-primary" href="/admin/sneak-preview/new">+ Neuer Eintrag</a>
            <a class="btn btn-outline-secondary" href="/admin/sneak-preview/settings">Anzeige-Einstellungen</a>
            <a class="btn btn-outline-secondary" href="/sneak-preview" target="_blank" rel="noopener">Öffentliche Seite</a>
        </div>
    </div>

    <?php if ($message !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <?php if ($movies === []): ?>
        <div class="alert alert-secondary mb-0">Noch keine Sneak-Preview-Einträge vorhanden.</div>
    <?php else: ?>
        <?php
        $table_id = 'sneak-preview-admin-table';
        $admin_mode = true;
        require __DIR__ . '/partials/table.php';
        ?>
    <?php endif; ?>
</section>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/2.0.8/js/dataTables.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.4/js/lightbox.min.js"></script>
<script>
window.addEventListener('DOMContentLoaded', function () {
    if (!window.jQuery || !document.getElementById('sneak-preview-admin-table')) return;
    jQuery('#sneak-preview-admin-table').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/2.0.8/i18n/de-DE.json' },
        pageLength: 25,
        order: [[2, 'desc']]
    });
});
</script>
