<?php
declare(strict_types=1);

$sectionTitle = (string) ($section_title ?? 'Banking');
$sectionDescription = (string) ($section_description ?? '');
$legacyStatus = is_array($legacy_status ?? null) ? $legacy_status : [];
?>

<?php require dirname(__DIR__) . '/partials/module-nav.php'; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <p class="text-uppercase text-body-secondary small fw-semibold mb-1">Banking</p>
        <h1 class="h4 mb-1"><?= htmlspecialchars($sectionTitle, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="text-body-secondary mb-0"><?= htmlspecialchars($sectionDescription, ENT_QUOTES, 'UTF-8') ?></p>
    </div>
    <span class="badge text-bg-secondary">Platzhalter</span>
</div>

<div class="card shadow-sm border-0 app-card">
    <div class="card-body">
        <h2 class="h5 mb-3">Noch nicht migriert</h2>
        <p class="mb-3">
            Dieser Bereich ist als native Modulon-Route vorbereitet. Die fachliche Umsetzung folgt erst nach der gesicherten
            Banking-Datenmigration.
        </p>
        <div class="alert alert-info mb-0">
            Legacy bleibt unter <code><?= htmlspecialchars((string) ($legacyStatus['path'] ?? 'app/Legacy/banking.old'), ENT_QUOTES, 'UTF-8') ?></code>
            als read-only Referenz archiviert.
        </div>
    </div>
</div>
