<?php
declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$mirrors = is_array($mirrors ?? null) ? $mirrors : [];
$editMirror = $edit_mirror ?? null;
$csrfToken = (string) ($csrf_token ?? '');
$message = (string) ($message ?? '');
$error = (string) ($error ?? '');
$homeDir = (string) ($home_directory ?? '/home');
?>
<div class="row g-4 mirror-manager-admin">
    <div class="col-12">
        <div class="card shadow-sm border-0 app-card">
            <div class="card-body p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div>
                        <p class="text-uppercase text-body-secondary small fw-semibold mb-1">System &amp; Verteilung</p>
                        <h1 class="h4 mb-1">Mirror Manager</h1>
                        <p class="text-body-secondary mb-0">Multi-Mirror-Verwaltung für Modul-Repositories und Core-System-Updates. Spiegelt GitHub-Quellen in frei wählbare lokale Web-Verzeichnisse.</p>
                    </div>
                    <div>
                        <button type="button" class="btn btn-primary btn-sm d-flex align-items-center gap-2" data-bs-toggle="collapse" data-bs-target="#collapseMirrorForm" aria-expanded="<?= $editMirror !== null ? 'true' : 'false' ?>">
                            <i class="bi bi-plus-lg"></i>
                            <span><?= $editMirror !== null ? 'Mirror bearbeiten' : 'Neuen Mirror anlegen' ?></span>
                        </button>
                    </div>
                </div>

                <?php if ($message !== ''): ?>
                    <div class="alert alert-success mt-3 mb-0" role="status"><?= $e($message) ?></div>
                <?php endif; ?>
                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger mt-3 mb-0" role="alert"><?= $e($error) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Formular zum Anlegen / Bearbeiten -->
    <div class="col-12 collapse <?= $editMirror !== null ? 'show' : '' ?>" id="collapseMirrorForm">
        <div class="card shadow-sm border-0 app-card border-primary">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h5 mb-0"><?= $editMirror !== null ? 'Mirror bearbeiten: ' . $e($editMirror->name) : 'Neuen Mirror konfigurieren' ?></h2>
                    <?php if ($editMirror !== null): ?>
                        <a href="/admin/mirror" class="btn btn-outline-secondary btn-sm">Abbrechen</a>
                    <?php endif; ?>
                </div>

                <form method="post" action="/admin/mirror/save" id="mirror-config-form">
                    <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                    <?php if ($editMirror !== null): ?>
                        <input type="hidden" name="id" value="<?= $e($editMirror->id) ?>">
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold small" for="mirror_name">Bezeichnung / Name</label>
                            <input type="text" class="form-control form-control-sm" id="mirror_name" name="name" value="<?= $e($editMirror->name ?? '') ?>" placeholder="z. B. Offizieller Repo-Mirror oder Core-Updates" required>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold small" for="mirror_type">Mirror-Typ</label>
                            <select class="form-select form-select-sm" id="mirror_type" name="type" required>
                                <option value="repository" <?= ($editMirror->type ?? '') === 'repository' ? 'selected' : '' ?>>Modul-Repository (Modul-Katalog &amp; Pakete)</option>
                                <option value="updates" <?= ($editMirror->type ?? '') === 'updates' ? 'selected' : '' ?>>Core-System-Updates (stable.json &amp; zips)</option>
                            </select>
                        </div>

                        <div class="col-12 col-md-8">
                            <label class="form-label fw-semibold small" for="mirror_source_url">Quell-Repository (GitHub URL)</label>
                            <input type="text" class="form-control form-control-sm" id="mirror_source_url" name="source_url" value="<?= $e($editMirror->sourceUrl ?? 'https://github.com/ChobitsChii/modulon') ?>" placeholder="https://github.com/Organisation/Repository" required>
                            <div class="form-text small">URL des fertig gebauten <strong>Distribution-Repositories</strong> (z. B. <code>https://github.com/ChobitsChii/modulnest-distribution</code>). Ein reines Code-Repository reicht hierfür nicht aus!</div>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold small" for="mirror_source_branch">Branch / Zweig</label>
                            <input type="text" class="form-control form-control-sm" id="mirror_source_branch" name="source_branch" value="<?= $e($editMirror->sourceBranch ?? 'main') ?>" placeholder="main" required>
                        </div>

                        <div class="col-12 col-md-8">
                            <label class="form-label fw-semibold small" for="mirror_target_path">Lokales Zielverzeichnis</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text"><i class="bi bi-folder2"></i></span>
                                <input type="text" class="form-control" id="mirror_target_path" name="target_path" value="<?= $e($editMirror->targetPath ?? $homeDir) ?>" placeholder="/home/username/domains/repo.example.com/public_html" required>
                                <button type="button" class="btn btn-outline-secondary" id="btn-open-dir-picker" data-bs-toggle="modal" data-bs-target="#mirrorDirectoryPickerModal">
                                    <i class="bi bi-folder-symlink me-1"></i>Ordner auswählen...
                                </button>
                            </div>
                            <div class="form-text small">Verzeichnis auf dem Server (z. B. DocumentRoot der Subdomain in Virtualmin oder Apache/Nginx).</div>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold small" for="mirror_public_url">Öffentliche Basis-URL (optional)</label>
                            <input type="url" class="form-control form-control-sm" id="mirror_public_url" name="public_url" value="<?= $e($editMirror->publicUrl ?? '') ?>" placeholder="https://repo.modulnest.de">
                            <div class="form-text small">Öffentliche URL, unter der die gespiegelten Daten erreichbar sind.</div>
                        </div>

                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="mirror_enabled" name="enabled" value="1" <?= ($editMirror === null || !empty($editMirror->enabled)) ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="mirror_enabled">Mirror aktiv (kann synchronisiert werden)</label>
                            </div>
                        </div>

                        <div class="col-12 d-flex justify-content-end gap-2 pt-2 border-top">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="bi bi-check-lg me-1"></i><?= $editMirror !== null ? 'Änderungen speichern' : 'Mirror erstellen' ?>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Übersicht der konfigurierten Mirrors -->
    <div class="col-12">
        <div class="card shadow-sm border-0 app-card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h5 mb-0">Konfigurierte Mirrors</h2>
                    <span class="badge text-bg-secondary"><?= count($mirrors) ?> vorhanden</span>
                </div>

                <?php if ($mirrors === []): ?>
                    <div class="text-center py-5 text-body-secondary">
                        <i class="bi bi-hdd-stack display-4 d-block mb-3 text-secondary-emphasis"></i>
                        <p class="mb-2">Es sind noch keine Mirrors konfiguriert.</p>
                        <p class="small mb-3">Erstelle deinen ersten Mirror für ein Modul-Repository oder Core-Updates.</p>
                        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#collapseMirrorForm">
                            <i class="bi bi-plus-lg me-1"></i>Jetzt Mirror anlegen
                        </button>
                    </div>
                <?php else: ?>
                    <div class="row g-3" id="mirror-cards-container">
                        <?php foreach ($mirrors as $m):
                            $isUpdates = $m->type === 'updates';
                            $isRunning = $m->lastStatus === 'running';
                            $isSuccess = $m->lastStatus === 'success';
                            $isError = $m->lastStatus === 'error';
                        ?>
                            <div class="col-12" id="mirror-card-<?= $e($m->id) ?>" data-mirror-id="<?= $e($m->id) ?>" data-status="<?= $e($m->lastStatus) ?>">
                                <div class="border rounded-3 p-3 p-md-4 bg-body-tertiary">
                                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                                        <div>
                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                <h3 class="h6 mb-0 fw-bold"><?= $e($m->name) ?></h3>
                                                <?php if ($isUpdates): ?>
                                                    <span class="badge text-bg-warning"><i class="bi bi-arrow-repeat me-1"></i>Core-Updates</span>
                                                <?php else: ?>
                                                    <span class="badge text-bg-primary"><i class="bi bi-puzzle me-1"></i>Modul-Repository</span>
                                                <?php endif; ?>

                                                <?php if ($m->enabled): ?>
                                                    <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Aktiv</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary-subtle text-secondary border">Inaktiv</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="small text-body-secondary d-flex flex-wrap gap-3">
                                                <span><i class="bi bi-github me-1"></i><?= $e($m->sourceUrl) ?> (<code><?= $e($m->sourceBranch) ?></code>)</span>
                                                <?php if (!empty($m->publicUrl)): ?>
                                                    <span><i class="bi bi-globe me-1"></i><a href="<?= $e($m->publicUrl) ?>" target="_blank" rel="noopener"><?= $e($m->publicUrl) ?></a></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="d-flex align-items-center gap-2 mirror-status-wrapper">
                                            <?php if ($isRunning): ?>
                                                <span class="badge text-bg-info d-flex align-items-center gap-1 status-badge">
                                                    <span class="spinner-border spinner-border-sm" role="status"></span>
                                                    <span>Synchronisiert...</span>
                                                </span>
                                            <?php elseif ($isSuccess): ?>
                                                <span class="badge text-bg-success status-badge">
                                                    <i class="bi bi-check-circle me-1"></i>Synchronisiert
                                                </span>
                                            <?php elseif ($isError): ?>
                                                <span class="badge text-bg-danger status-badge" title="Fehler bei letzter Synchronisation">
                                                    <i class="bi bi-exclamation-triangle me-1"></i>Fehler
                                                </span>
                                            <?php else: ?>
                                                <span class="badge text-bg-secondary status-badge">Bereit</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="row g-2 mb-3 small">
                                        <div class="col-12 col-md-8">
                                            <div class="p-2 bg-body rounded border">
                                                <span class="text-body-secondary d-block font-monospace" style="font-size: 0.75rem;">Zielverzeichnis:</span>
                                                <code class="text-break"><?= $e($m->targetPath) ?></code>
                                            </div>
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <div class="p-2 bg-body rounded border h-100">
                                                <span class="text-body-secondary d-block" style="font-size: 0.75rem;">Letzte Synchronisation:</span>
                                                <span class="fw-semibold sync-timestamp">
    <?= $e($m->lastSyncedAt ?? 'Noch nie') ?>
    <?php if ($m->lastSyncedAt && ($m->lastTrigger ?? null)): ?>
        <span class="text-secondary fw-normal">(<?= $m->lastTrigger === 'cron' ? 'CronJob' : 'Manuell' ?>)</span>
    <?php endif; ?>
</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 pt-2 border-top">
                                        <div class="d-flex flex-wrap gap-2">
                                            <button type="button" class="btn btn-primary btn-sm btn-trigger-sync" data-mirror-id="<?= $e($m->id) ?>" <?= (!$m->enabled || $isRunning) ? 'disabled' : '' ?>>
                                                <i class="bi bi-arrow-repeat me-1"></i>
                                                <span class="sync-btn-text"><?= $isRunning ? 'Synchronisiert...' : 'Jetzt synchronisieren' ?></span>
                                            </button>

                                            <?php if (!empty($m->lastLog)): ?>
                                                <button type="button" class="btn btn-outline-secondary btn-sm btn-view-log" data-mirror-id="<?= $e($m->id) ?>" data-log="<?= $e($m->lastLog) ?>">
                                                    <i class="bi bi-file-earmark-text me-1"></i>Protokoll
                                                </button>
                                            <?php endif; ?>
                                        </div>

                                        <div class="d-flex gap-2">
                                            <a href="/admin/mirror?edit=<?= $e($m->id) ?>" class="btn btn-outline-secondary btn-sm" title="Bearbeiten">
                                                <i class="bi bi-pencil"></i>
                                            </a>

                                            <form method="post" action="/admin/mirror/toggle" class="d-inline">
                                                <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                                <input type="hidden" name="id" value="<?= $e($m->id) ?>">
                                                <button type="submit" class="btn btn-outline-<?= $m->enabled ? 'warning' : 'success' ?> btn-sm" title="<?= $m->enabled ? 'Deaktivieren' : 'Aktivieren' ?>">
                                                    <i class="bi bi-power"></i>
                                                </button>
                                            </form>

                                            <form method="post" action="/admin/mirror/delete" class="d-inline" onsubmit="return confirm('Möchtest du diesen Mirror wirklich löschen? Die lokalen Dateien bleiben unberührt.');">
                                                <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                                <input type="hidden" name="id" value="<?= $e($m->id) ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm" title="Löschen">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>

                                    <!-- Cronjob Box -->
                                    <div class="mt-3 pt-3 border-top">
                                        <details class="small">
                                            <summary class="text-body-secondary fw-semibold cursor-pointer user-select-none">
                                                <i class="bi bi-clock-history me-1"></i>Cronjob-Befehl für zeitgesteuerte Aktualisierung anzeigen
                                            </summary>
                                            <div class="mt-2">
                                                <?php
                                                $cronOffset = max(0, ((int) $m->id - 1)) * 5;
                                                $cronMin = $cronOffset % 60;
                                                $cronHOffset = intdiv($cronOffset, 60);
                                                $cronH1 = (3 + $cronHOffset) % 24;
                                                $cronH2 = (15 + $cronHOffset) % 24;
                                                $cronHours = ($cronH1 === $cronH2) ? (string) $cronH1 : "{$cronH1},{$cronH2}";
                                                ?>
                                                <p class="text-body-secondary mb-1">Empfohlenes Intervall: 1–2× täglich (automatisch kollisionsfrei um 5 Min. versetzt). Befehl für die Server-Crontab (z. B. via Virtualmin &gt; Scheduled Cron Jobs):</p>
                                                <pre class="p-2 bg-dark text-light rounded font-monospace small user-select-all mb-0"><code><?= $cronMin ?> <?= $cronHours ?> * * * php /srv/http/modulon/tools/release/sync-mirror-job.php --id=<?= $e($m->id) ?> --trigger=cron >/dev/null 2>&1</code></pre>
                                            </div>
                                        </details>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Modal für Directory-Picker -->
<?= \Modulon\Core\View::renderPartial('@modulnest.mirror/partials/directory-picker-modal', [
    'home_directory' => $homeDir,
]) ?>

<!-- Mirror Script -->
<script><?php require __DIR__ . '/../assets/js/mirror.js'; ?></script>

<!-- Modal für Sync-Log -->
<div class="modal fade" id="mirrorLogModal" tabindex="-1" aria-labelledby="mirrorLogModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title h6" id="mirrorLogModalTitle">
                    <i class="bi bi-file-earmark-text me-2 text-primary"></i>Synchronisationsprotokoll
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <div class="modal-body p-3">
                <pre id="mirrorLogContent" class="bg-dark text-light p-3 rounded small font-monospace mb-0 text-wrap" style="max-height: 400px;"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Schließen</button>
            </div>
        </div>
    </div>
</div>
