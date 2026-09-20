<?php
declare(strict_types=1);

$movies = is_array($movies ?? null) ? $movies : [];
$fields = is_array($fields ?? null) ? $fields : [];
?>
<link rel="stylesheet" href="https://cdn.datatables.net/2.0.8/css/dataTables.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/3.0.2/css/buttons.dataTables.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.4/css/lightbox.min.css">

<section class="app-card p-4 sneak-preview-page">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">🎬 Sneak-Preview-Liste</h1>
            <p class="text-muted mb-0">Tipp: Klicke auf ein Poster, um die Detailansicht zu öffnen.</p>
        </div>
        <div class="text-muted small align-self-lg-end">Spalten anpassen: 👇</div>
    </div>

    <?php if ($movies === []): ?>
        <div class="alert alert-secondary mb-0">Noch keine Sneak-Preview-Einträge vorhanden.</div>
    <?php else: ?>
        <?php
        $table_id = 'sneak-preview-public-table';
        $admin_mode = false;
        require __DIR__ . '/partials/table.php';
        ?>
    <?php endif; ?>
</section>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/2.0.8/js/dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/3.0.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/3.0.2/js/buttons.colVis.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.4/js/lightbox.min.js"></script>
<script>
window.addEventListener('DOMContentLoaded', function () {
    if (!window.jQuery || !document.getElementById('sneak-preview-public-table')) return;
    jQuery('#sneak-preview-public-table').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/2.0.8/i18n/de-DE.json' },
        pageLength: 25,
        lengthMenu: [10, 25, 50, 100],
        dom: '<"sneak-preview-dt-top"lfB>rt<"sneak-preview-dt-bottom"ip>',
        buttons: [{ extend: 'colvis', text: 'Spalten anpassen', className: 'btn btn-secondary' }],
        order: [[1, 'desc']],
        stateSave: true,
        stateDuration: -1
    });
});
</script>
