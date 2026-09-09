<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$providers = is_array($providers ?? null) ? $providers : [];
$preview = is_array($preview ?? null) ? $preview : null;
$targetUser = is_array($target_user ?? null) ? $target_user : [];
$providerCount = count($providers);
$previewModules = is_array($preview['modules'] ?? null) ? $preview['modules'] : [];
$targetUserLabel = (string) ($targetUser['display_name'] ?? $targetUser['email'] ?? 'aktueller Admin-User');
$targetUserId = (int) ($targetUser['id'] ?? 0);
$importMode = (string) ($import_mode ?? ($preview['import_mode'] ?? 'merge'));
$importMode = $importMode === 'replace' ? 'replace' : 'merge';
$countLabel = static fn (string $name): string => [
    'accounts' => 'Konten',
    'categories' => 'Kategorien',
    'transactions' => 'Buchungen',
    'recurring_rules' => 'Regeln',
    'conditions' => 'Filter',
    'entries' => 'Einträge',
    'settings' => 'Einstellungen',
    'files' => 'Dateien',
    'new' => 'neu',
    'update' => 'aktualisieren',
    'invalid' => 'ungültig',
][strtolower($name)] ?? $name;
?>
<section class="app-card data-portability-hero p-4 mb-4">
    <div class="d-flex flex-column flex-xl-row justify-content-between gap-4">
        <div class="data-portability-hero-copy">
            <p class="text-uppercase text-body-secondary small fw-semibold mb-1">Admin</p>
            <h1 class="h3 mb-2">Export / Import</h1>
            <p class="text-body-secondary mb-3">Moduldaten zwischen ModulNest-Instanzen übertragen.</p>
            <div class="alert alert-warning small mb-0" role="alert">
                Diese Funktion ersetzt kein vollständiges System- oder Datenbank-Backup.
            </div>
        </div>
        <div class="data-portability-info-grid" aria-label="Export-Import-Eigenschaften">
            <div class="data-portability-info-tile">
                <span class="data-portability-info-value"><?= $providerCount ?></span>
                <span class="data-portability-info-label">verfügbare Bereiche</span>
            </div>
            <div class="data-portability-info-tile">
                <span class="data-portability-info-value">ZIP</span>
                <span class="data-portability-info-label">Format v1</span>
            </div>
            <div class="data-portability-info-tile">
                <span class="data-portability-info-value">Preview</span>
                <span class="data-portability-info-label">Import mit Vorschau</span>
            </div>
            <div class="data-portability-info-tile">
                <span class="data-portability-info-value">Storage</span>
                <span class="data-portability-info-label">außerhalb Webroot</span>
            </div>
        </div>
    </div>
</section>

<?php if (!empty($message)): ?>
    <div class="alert alert-success"><?= $e($message) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= $e($error) ?></div>
<?php endif; ?>

<div class="row g-4 align-items-stretch">
    <div class="col-lg-6">
        <section class="card shadow-sm border-0 app-card h-100">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                    <div>
                        <p class="text-uppercase text-body-secondary small fw-semibold mb-1">Export</p>
                        <h2 class="h5 mb-1">ZIP-Archiv erstellen</h2>
                        <p class="text-body-secondary mb-0">Wähle die Bereiche aus, die in das Archiv geschrieben werden sollen.</p>
                    </div>
                    <span class="badge text-bg-secondary"><?= $providerCount ?> Provider</span>
                </div>
                <form method="post" action="/admin/data-portability/export">
                    <?= \Modulon\Core\View::csrfField((string) ($csrf_token ?? '')) ?>
                    <?php if ($providers === []): ?>
                        <div class="alert alert-info">Aktuell sind keine exportfähigen Zielmodule aktiv.</div>
                    <?php endif; ?>
                    <div class="vstack gap-3 data-portability-provider-list">
                        <?php foreach ($providers as $provider): ?>
                            <label class="data-portability-provider">
                                <span class="data-portability-provider-check">
                                    <input class="form-check-input" type="checkbox" name="providers[]" value="<?= $e($provider['key'] ?? '') ?>" checked>
                                </span>
                                <span class="data-portability-provider-body">
                                    <span class="data-portability-provider-header">
                                        <span>
                                            <strong><?= $e($provider['label'] ?? '') ?></strong>
                                            <span class="badge data-portability-route-badge ms-2"><?= $e($provider['route_prefix'] ?? $provider['key'] ?? '') ?></span>
                                        </span>
                                        <span class="data-portability-provider-badges">
                                            <?php if (!empty($provider['has_files'])): ?>
                                                <span class="badge text-bg-info">mit Dateien</span>
                                            <?php endif; ?>
                                            <span class="badge text-bg-secondary">Format v<?= (int) ($provider['schema_version'] ?? 0) ?></span>
                                        </span>
                                    </span>
                                    <span class="d-block text-body-secondary small mt-2"><?= $e($provider['description'] ?? '') ?></span>
                                    <?php if (!empty($provider['sensitivity_note'])): ?>
                                        <span class="alert alert-warning data-portability-provider-note small mt-3 mb-0" role="note">
                                            <?= $e($provider['sensitivity_note']) ?>
                                        </span>
                                    <?php endif; ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <button class="btn btn-primary mt-4" type="submit">ZIP-Export herunterladen</button>
                </form>
            </div>
        </section>
    </div>

    <div class="col-lg-6">
        <section class="card shadow-sm border-0 app-card h-100">
            <div class="card-body p-4">
                <div class="mb-4">
                    <p class="text-uppercase text-body-secondary small fw-semibold mb-1">Import</p>
                    <h2 class="h5 mb-1">Import vorbereiten</h2>
                    <p class="text-body-secondary mb-0">Eine ZIP-Datei wird zuerst geprüft. Daten werden erst nach bestätigter Vorschau geschrieben.</p>
                </div>

                <div class="data-portability-workflow mb-4">
                    <div class="data-portability-step">
                        <div class="data-portability-step-marker">1</div>
                        <div class="data-portability-step-body">
                            <h3 class="h6 mb-1">ZIP auswählen</h3>
                            <p class="text-body-secondary small mb-0">Wähle ein ModulNest-Exportarchiv im ZIP-Format.</p>
                        </div>
                    </div>
                    <div class="data-portability-step">
                        <div class="data-portability-step-marker">2</div>
                        <div class="data-portability-step-body">
                            <h3 class="h6 mb-1">Import prüfen</h3>
                            <p class="text-body-secondary small mb-0">Manifest, Module und Dateien werden validiert.</p>
                        </div>
                    </div>
                    <div class="data-portability-step <?= $preview === null ? 'is-disabled' : 'is-ready' ?>">
                        <div class="data-portability-step-marker">3</div>
                        <div class="data-portability-step-body">
                            <h3 class="h6 mb-1">Vorschau bestätigen</h3>
                            <?php if ($preview === null): ?>
                                <p class="text-body-secondary small mb-0">Dieser Schritt wird nach einer erfolgreichen Prüfung freigeschaltet.</p>
                            <?php else: ?>
                                <p class="text-body-secondary small mb-3">
                                    Die Vorschau ist bereit.
                                    <?php if ($importMode === 'replace'): ?>
                                        Der Ersetzen-Modus löscht vorher Daten der enthaltenen Module im jeweiligen Zielbereich.
                                    <?php else: ?>
                                        Der Standardmodus fügt Daten hinzu oder führt sie zusammen.
                                    <?php endif; ?>
                                </p>
                                <form method="post" action="/admin/data-portability/import/run" onsubmit="return confirm('Import jetzt ausführen? <?= $importMode === 'replace' ? 'Bestehende Moduldaten werden vorher gelöscht.' : 'Daten werden hinzugefügt oder zusammengeführt.' ?>');">
                                    <?= \Modulon\Core\View::csrfField((string) ($csrf_token ?? '')) ?>
                                    <input type="hidden" name="import_mode" value="<?= $e($importMode) ?>">
                                    <button class="btn btn-danger btn-sm" type="submit">Import ausführen</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <form method="post" action="/admin/data-portability/import/preview" enctype="multipart/form-data" class="mb-4">
                    <?= \Modulon\Core\View::csrfField((string) ($csrf_token ?? '')) ?>
                    <div class="mb-3">
                        <label class="form-label" for="import_zip">Export-ZIP</label>
                        <input class="form-control" type="file" id="import_zip" name="import_zip" accept=".zip,application/zip" required>
                        <div class="form-text text-body-secondary">Temporäre Dateien werden im privaten Modulstorage gespeichert, nicht im Webroot.</div>
                    </div>
                    <fieldset class="data-portability-import-mode mb-3">
                        <legend class="form-label mb-2">Importmodus</legend>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="import_mode" id="import_mode_merge" value="merge"<?= $importMode !== 'replace' ? ' checked' : '' ?>>
                            <label class="form-check-label" for="import_mode_merge">Hinzufügen / Zusammenführen</label>
                            <div class="form-text text-body-secondary">Bestehende Daten bleiben erhalten. Das ist der sichere Standard.</div>
                        </div>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="radio" name="import_mode" id="import_mode_replace" value="replace"<?= $importMode === 'replace' ? ' checked' : '' ?>>
                            <label class="form-check-label" for="import_mode_replace">Bestehende Moduldaten ersetzen</label>
                            <div class="form-text text-warning">Bestehende Daten der ausgewählten Module werden vor dem Import gelöscht. Bitte vorher Backup erstellen.</div>
                        </div>
                    </fieldset>
                    <button class="btn btn-outline-primary" type="submit">Import prüfen</button>
                </form>

                <div class="data-portability-target">
                    <div>
                        <p class="text-uppercase text-body-secondary small fw-semibold mb-1">Zielbereich</p>
                        <p class="mb-0">
                            <strong><?= $e($targetUserLabel) ?></strong>
                            <?php if ($targetUserId > 0): ?>
                                <span class="text-body-secondary">· ID <?= $targetUserId ?></span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <span class="badge text-bg-secondary align-self-start">user-bezogene Daten</span>
                </div>
            </div>
        </section>
    </div>
</div>

<?php if ($preview !== null): ?>
    <section class="card shadow-sm border-0 app-card mt-4">
        <div class="card-body p-4">
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start gap-3 mb-4">
                <div>
                    <p class="text-uppercase text-body-secondary small fw-semibold mb-1">Import-Vorschau</p>
                    <h2 class="h5 mb-1"><?= $e($preview['manifest']['product'] ?? 'Export') ?></h2>
                    <p class="text-body-secondary mb-0">
                        Format <?= (int) ($preview['manifest']['format_version'] ?? 0) ?> · App-Version <?= $e($preview['manifest']['app_version'] ?? '') ?>
                    </p>
                    <p class="mb-0 mt-2">
                        <span class="badge <?= $importMode === 'replace' ? 'text-bg-warning' : 'text-bg-secondary' ?>">
                            Importmodus: <?= $importMode === 'replace' ? 'Bestehende Moduldaten ersetzen' : 'Hinzufügen / Zusammenführen' ?>
                        </span>
                    </p>
                </div>
                <div class="data-portability-preview-summary">
                    <span class="badge text-bg-secondary"><?= count($previewModules) ?> Module</span>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 app-table">
                    <thead>
                    <tr>
                        <th class="ps-4">Modul</th>
                        <th>Status</th>
                        <th>Datensätze</th>
                        <th>Dateien</th>
                        <th class="pe-4">Hinweise</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($previewModules as $module): ?>
                        <?php $counts = is_array($module['counts'] ?? null) ? $module['counts'] : []; ?>
                        <tr>
                            <td class="ps-4">
                                <strong><?= $e($module['label'] ?? $module['key'] ?? '') ?></strong>
                                <div class="text-body-secondary small"><?= $e($module['key'] ?? '') ?> · Format v<?= (int) ($module['schema_version'] ?? 0) ?></div>
                            </td>
                            <td>
                                <?php if (!empty($module['can_import'])): ?>
                                    <span class="badge text-bg-success">bereit</span>
                                <?php elseif (!empty($module['available'])): ?>
                                    <span class="badge text-bg-warning">prüfen</span>
                                <?php else: ?>
                                    <span class="badge text-bg-danger">Modul fehlt</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($counts === []): ?>
                                    <span class="text-body-secondary small">keine Angaben</span>
                                <?php else: ?>
                                    <div class="d-flex flex-wrap gap-1">
                                        <?php foreach ($counts as $name => $count): ?>
                                            <span class="badge text-bg-secondary"><?= $e($countLabel((string) $name)) ?>: <?= (int) $count ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?= (int) ($module['file_count'] ?? 0) ?></td>
                            <td class="small pe-4">
                                <?php if (($module['warnings'] ?? []) === []): ?>
                                    <span class="text-body-secondary">-</span>
                                <?php else: ?>
                                    <div class="vstack gap-1">
                                        <?php foreach (($module['warnings'] ?? []) as $warning): ?>
                                            <div class="text-warning"><?= $e($warning) ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($importMode === 'replace'): ?>
                <div class="alert alert-warning mt-4 mb-0" role="alert">
                    Bestehende Daten der ausgewählten Module werden vor dem Import gelöscht. Bitte vorher Backup erstellen.
                </div>
            <?php endif; ?>

            <div class="data-portability-preview-action mt-4">
                <div>
                    <h3 class="h6 mb-1">Import bereit</h3>
                    <p class="text-body-secondary small mb-0">
                        Wenn die Vorschau korrekt aussieht, kannst du den Import jetzt ausführen.
                        <?= $importMode === 'replace' ? 'Der Ersetzen-Modus löscht vorher die Daten der enthaltenen Module im Zielbereich.' : 'Im Standardmodus werden Daten hinzugefügt oder zusammengeführt.' ?>
                    </p>
                </div>
                <form method="post" action="/admin/data-portability/import/run" onsubmit="return confirm('Import jetzt ausführen? <?= $importMode === 'replace' ? 'Bestehende Moduldaten werden vorher gelöscht.' : 'Daten werden hinzugefügt oder zusammengeführt.' ?>');">
                    <?= \Modulon\Core\View::csrfField((string) ($csrf_token ?? '')) ?>
                    <input type="hidden" name="import_mode" value="<?= $e($importMode) ?>">
                    <button class="btn btn-danger" type="submit">Import ausführen</button>
                </form>
            </div>
        </div>
    </section>
<?php endif; ?>
