<?php
declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$installedVersion = (string) ($installed_version ?? '1.0.1');
?>
<div class="repository-manager-admin">
    <div class="d-flex flex-wrap gap-3 align-items-start justify-content-between mb-4">
        <div>
            <h1 class="h3 mb-1">Repository Manager</h1>
            <p class="text-body-secondary mb-0">Ehemalige Verwaltung von Modul-Katalogquellen und Repository-Spiegeln.</p>
        </div>
    </div>

    <div class="alert alert-warning border-warning p-4 shadow-sm" role="alert">
        <div class="d-flex align-items-start gap-3">
            <i class="bi bi-exclamation-triangle-fill fs-2 text-warning flex-shrink-0"></i>
            <div>
                <h4 class="alert-heading h5 mb-2">Dieses Modul ist veraltet (obsolet)</h4>
                <p class="mb-2">
                    Sämtliche Funktionen zur Verwaltung von Modul-Katalogquellen sind seit ModulNest 2.1.0 direkt im <strong>Modul-Katalog</strong> unter dem Reiter <em>Katalogquellen</em> integriert.
                    Die Spiegelung und Synchronisation von Repositories wird vollständig vom Modul <strong>Mirror Manager</strong> übernommen.
                </p>
                <p class="mb-3 text-body-secondary small">
                    Sie können dieses Modul gefahrlos und ohne Funktions- oder Datenverlust deinstallieren.
                </p>
                <div class="d-flex flex-wrap gap-2">
                    <a href="/admin/module-catalog/modulnest.repository-manager" class="btn btn-danger btn-sm">
                        <i class="bi bi-trash me-1"></i> Modul im Modul-Katalog deinstallieren
                    </a>
                    <a href="/admin/module-catalog?bereich=quellen" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-hdd-network me-1"></i> Zu den Katalogquellen im Modul-Katalog
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
