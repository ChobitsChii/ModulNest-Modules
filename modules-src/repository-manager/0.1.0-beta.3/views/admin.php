<?php
declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$sources = is_array($sources ?? null) ? $sources : [];
$editSource = is_array($edit_source ?? null) ? $edit_source : null;
$mirrorStatus = is_array($mirror_status ?? null) ? $mirror_status : [];
$installedVersion = (string) ($installed_version ?? '0.1.0-beta.3');
$csrfToken = (string) ($csrf_token ?? '');
$message = (string) ($message ?? '');
$error = (string) ($error ?? '');

$sourceTypeLabel = static fn (string $type): string => $type === 'local' ? 'Lokales Verzeichnis' : 'HTTPS-URL';
?>
<div class="row g-4 repository-manager-admin">
    <div class="col-12">
        <div class="card shadow-sm border-0 app-card">
            <div class="card-body p-4">
                <p class="text-uppercase text-body-secondary small fw-semibold mb-1">Admin</p>
                <h1 class="h4 mb-2">Repository Manager</h1>
                <p class="text-body-secondary mb-0">Verwaltet Modul-Katalogquellen und steuert den lokalen ModulNest-Repository-Spiegel. Trust, Validierung und Konflikt-Auflösung bleiben Kernaufgabe des Core.</p>
                <p class="small text-success-emphasis mt-2 mb-0">Eigenständiges Modulrelease <?= $e($installedVersion) ?> aktiv.</p>
                <?php if ($message !== ''): ?><div class="alert alert-success mt-3 mb-0" role="status"><?= $e($message) ?></div><?php endif; ?>
                <?php if ($error !== ''): ?><div class="alert alert-danger mt-3 mb-0" role="alert"><?= $e($error) ?></div><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Sektion 1: ModulNest Repository Mirror -->
    <div class="col-12">
        <section class="card shadow-sm border-0 app-card" id="mirror-section">
            <div class="card-body p-4">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
                    <div>
                        <h2 class="h5 mb-1">ModulNest-Repository-Mirror</h2>
                        <p class="text-body-secondary small mb-0">Lokaler Spiegel für <code>repo.modulnest.de</code>. Synchronisiert signierte Module direkt aus dem GitHub-Repository.</p>
                    </div>
                    <div>
                        <form method="post" action="/admin/repository-manager/sync" id="sync-form" class="d-inline">
                            <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                            <button type="submit" id="btn-sync-mirror" class="btn btn-primary d-inline-flex align-items-center gap-2" <?= empty($mirrorStatus['script_executable']) ? 'disabled' : '' ?>>
                                <span class="spinner-border spinner-border-sm d-none" id="sync-spinner" role="status" aria-hidden="true"></span>
                                <i class="bi bi-arrow-repeat" id="sync-icon"></i>
                                <span id="sync-btn-text">Repository jetzt synchronisieren</span>
                            </button>
                        </form>
                    </div>
                </div>

                <div id="sync-alert-container"></div>

                <div class="row g-3 mb-4">
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="p-3 bg-body-tertiary rounded-3 h-100 border">
                            <div class="text-body-secondary small fw-medium">Mirror-Status</div>
                            <div class="fs-5 fw-bold mt-1" id="mirror-status-badge">
                                <?php if (!empty($mirrorStatus['is_running'])): ?>
                                    <span class="badge text-bg-warning"><i class="bi bi-hourglass-split me-1"></i>Sync läuft...</span>
                                <?php elseif (!empty($mirrorStatus['available'])): ?>
                                    <span class="badge text-bg-success"><i class="bi bi-check-circle me-1"></i>Bereit &amp; Verifiziert</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary"><i class="bi bi-dash-circle me-1"></i>Nicht verfügbar</span>
                                <?php endif; ?>
                            </div>
                            <div class="text-body-secondary small mt-1" id="mirror-path-display">
                                <code><?= $e($mirrorStatus['repository_root'] ?? '/srv/http/modulnest-distribution/repository') ?></code>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="p-3 bg-body-tertiary rounded-3 h-100 border">
                            <div class="text-body-secondary small fw-medium">Katalog-Sequenz</div>
                            <div class="fs-5 fw-bold mt-1" id="mirror-sequence">
                                <?= !empty($mirrorStatus['sequence']) ? 'Sequenz ' . $e($mirrorStatus['sequence']) : '<span class="text-body-secondary">–</span>' ?>
                            </div>
                            <div class="text-body-secondary small mt-1" id="mirror-modules-count">
                                <?= !empty($mirrorStatus['module_count']) ? $e($mirrorStatus['module_count']) . ' Module (' . $e($mirrorStatus['catalog_id'] ?? '') . ')' : 'Kein Katalog' ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="p-3 bg-body-tertiary rounded-3 h-100 border">
                            <div class="text-body-secondary small fw-medium">Aktiver Snapshot</div>
                            <div class="text-truncate fw-semibold mt-1" id="mirror-snapshot" title="<?= $e($mirrorStatus['current_snapshot'] ?? 'Kein Snapshot') ?>">
                                <?= $e($mirrorStatus['current_snapshot'] ?? 'Kein Snapshot') ?>
                            </div>
                            <div class="text-body-secondary small mt-1" id="mirror-timestamp">
                                <?= !empty($mirrorStatus['snapshot_timestamp']) ? $e($mirrorStatus['snapshot_timestamp']) : '<span class="text-body-secondary">–</span>' ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="p-3 bg-body-tertiary rounded-3 h-100 border">
                            <div class="text-body-secondary small fw-medium">Source-Fingerprint</div>
                            <div class="font-monospace small text-truncate mt-1" id="mirror-fingerprint" title="<?= $e($mirrorStatus['fingerprint'] ?? '–') ?>">
                                <?= !empty($mirrorStatus['fingerprint']) ? $e(substr((string) $mirrorStatus['fingerprint'], 0, 16) . '…') : '<span class="text-body-secondary">–</span>' ?>
                            </div>
                            <div class="text-body-secondary small mt-1">
                                SHA-256 (Ed25519-geprüft)
                            </div>
                        </div>
                    </div>
                </div>

                <div class="accordion" id="mirrorDetailsAccordion">
                    <div class="accordion-item border rounded-3 overflow-hidden">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-body-tertiary py-2 px-3 small" type="button" data-bs-toggle="collapse" data-bs-target="#cronInfoCollapse">
                                <i class="bi bi-clock-history me-2"></i>Automatisierte Synchronisation (Cron &amp; Hintergrunddetails)
                            </button>
                        </h2>
                        <div id="cronInfoCollapse" class="accordion-collapse collapse" data-bs-parent="#mirrorDetailsAccordion">
                            <div class="accordion-body small">
                                <p class="mb-2">Der Repository-Mirror kann zeitgesteuert über die System-Crontab synchronisiert werden. Das vorhandene Skript nutzt atomare Snapshots und sperrt Parallelzugriffe automatisch per <code>flock</code>:</p>
                                <div class="bg-dark text-light p-2 rounded-2 font-monospace mb-3 user-select-all">
                                    <?= $e($mirrorStatus['cron_example'] ?? '5-59/15 * * * * /srv/http/modulnest-distribution/bin/sync-repository.sh --cron 2>&1 | /usr/bin/logger -t modulnest-sync-repository') ?>
                                </div>
                                <?php if (!empty($mirrorStatus['last_log'])): ?>
                                    <p class="fw-semibold mb-1">Letzte Protokollausgabe (storage/logs/repository-sync.log):</p>
                                    <pre class="bg-body-secondary p-2 rounded-2 font-monospace small mb-0 text-wrap"><?= $e($mirrorStatus['last_log']) ?></pre>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <!-- Sektion 2: Vorhandene Katalogquellen -->
    <div class="col-12">
        <section class="card shadow-sm border-0 app-card">
            <div class="card-body p-4">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
                    <h2 class="h5 mb-0">Vorhandene Modul-Katalogquellen</h2>
                    <span class="text-body-secondary small">Wird vom Core-CatalogLoader konsumiert</span>
                </div>
                <?php if ($sources === []): ?>
                    <p class="text-body-secondary mb-0">Noch keine Katalogquellen vorhanden.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                            <tr>
                                <th>Quelle</th>
                                <th>Typ</th>
                                <th>Ziel</th>
                                <th>Status</th>
                                <th>Priorität</th>
                                <th>Trust &amp; Fingerprints</th>
                                <th>Fehler / Status</th>
                                <th class="text-end">Aktionen</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($sources as $source):
                                $isOfficial = (bool) ($source['is_official'] ?? false);
                                $enabled = (bool) ($source['enabled'] ?? false);
                                $keyDetails = is_array($source['key_details'] ?? null) ? $source['key_details'] : [];
                            ?>
                                <tr id="source-row-<?= $e($source['id']) ?>">
                                    <td>
                                        <div class="fw-semibold"><?= $e($source['name'] ?? '') ?></div>
                                        <code class="small text-body-secondary"><?= $e($source['id'] ?? '') ?></code>
                                        <?php if ($isOfficial): ?><span class="badge text-bg-primary ms-1">Offiziell</span><?php endif; ?>
                                    </td>
                                    <td><?= $e($sourceTypeLabel((string) ($source['source_type'] ?? ''))) ?></td>
                                    <td class="text-break"><?= $e($source['location'] ?? '') ?></td>
                                    <td>
                                        <?php if ($enabled): ?>
                                            <span class="badge text-bg-success">Aktiv</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-secondary">Inaktiv</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $e($source['priority'] ?? 0) ?></td>
                                    <td>
                                        <?php if ($keyDetails === []): ?>
                                            <span class="text-body-secondary small">Keine Schlüssel</span>
                                        <?php else: ?>
                                            <div class="d-flex flex-column gap-1">
                                                <?php foreach ($keyDetails as $kd): ?>
                                                    <div class="small">
                                                        <code><?= $e($kd['key_id']) ?></code>
                                                        <?php if ($kd['is_root']): ?><span class="badge bg-info-subtle text-info-emphasis border border-info-subtle" style="font-size: 0.7rem;">Root</span><?php endif; ?>
                                                        <div class="text-body-secondary font-monospace" style="font-size: 0.75rem;" title="Fingerprint"><?= $e(substr((string) $kd['fingerprint'], 0, 16)) ?>…</div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="max-width: 200px;">
                                        <?php if (!empty($source['last_error'])): ?>
                                            <span class="text-danger small text-break" title="<?= $e($source['last_error']) ?>">
                                                <i class="bi bi-exclamation-triangle me-1"></i><?= $e(substr((string) $source['last_error'], 0, 60)) ?>…
                                            </span>
                                        <?php elseif (!empty($source['last_seen_sequence'])): ?>
                                            <span class="text-success small">
                                                <i class="bi bi-check-circle me-1"></i>Seq <?= $e($source['last_seen_sequence']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-body-secondary small">–</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <div class="btn-group btn-group-sm">
                                            <form method="post" action="/admin/repository-manager/test" class="d-inline test-source-form" data-source-id="<?= $e($source['id']) ?>">
                                                <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                                <input type="hidden" name="id" value="<?= $e($source['id']) ?>">
                                                <button type="submit" class="btn btn-outline-secondary test-btn" title="Verbindung &amp; Signatur prüfen">
                                                    <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                                                    <i class="bi bi-plug"></i> Testen
                                                </button>
                                            </form>
                                            <a href="/admin/repository-manager?edit=<?= $e(urlencode((string) $source['id'])) ?>" class="btn btn-outline-primary" title="Bearbeiten">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <?php if (!$isOfficial): ?>
                                                <form method="post" action="/admin/repository-manager/<?= $enabled ? 'disable' : 'enable' ?>" class="d-inline">
                                                    <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                                    <input type="hidden" name="id" value="<?= $e($source['id']) ?>">
                                                    <button type="submit" class="btn btn-outline-<?= $enabled ? 'warning' : 'success' ?>" title="<?= $enabled ? 'Deaktivieren' : 'Aktivieren' ?>">
                                                        <i class="bi bi-power"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
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

    <!-- Sektion 3: Formular Neue Katalogquelle / Bearbeiten -->
    <div class="col-12">
        <section class="card shadow-sm border-0 app-card">
            <div class="card-body p-4">
                <h2 class="h5 mb-3"><?= $editSource !== null ? 'Katalogquelle bearbeiten' : 'Neue Katalogquelle hinzufügen' ?></h2>
                <form method="post" action="/admin/repository-manager/<?= $editSource !== null ? 'update' : 'add' ?>" id="source-form">
                    <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label for="source-id" class="form-label">Quellen-ID <span class="text-danger">*</span></label>
                            <input type="text" class="form-control font-monospace" id="source-id" name="id"
                                   value="<?= $e($editSource['id'] ?? '') ?>"
                                   <?= $editSource !== null ? 'readonly' : 'required' ?>
                                   placeholder="z. B. community.repo">
                        </div>
                        <div class="col-12 col-md-5">
                            <label for="source-name" class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="source-name" name="name"
                                   value="<?= $e($editSource['name'] ?? '') ?>"
                                   <?= !empty($editSource['is_official']) ? 'readonly' : 'required' ?>
                                   placeholder="z. B. Community Repository">
                        </div>
                        <div class="col-12 col-md-3">
                            <label for="source-type" class="form-label">Typ <span class="text-danger">*</span></label>
                            <select class="form-select" id="source-type" name="source_type" <?= !empty($editSource['is_official']) ? 'disabled' : '' ?>>
                                <option value="https" <?= ($editSource['source_type'] ?? 'https') === 'https' ? 'selected' : '' ?>>HTTPS-URL</option>
                                <option value="local" <?= ($editSource['source_type'] ?? '') === 'local' ? 'selected' : '' ?>>Lokales Verzeichnis</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-9">
                            <label for="source-location" class="form-label">Ziel-URL oder Pfad <span class="text-danger">*</span></label>
                            <input type="text" class="form-control font-monospace" id="source-location" name="location"
                                   value="<?= $e($editSource['location'] ?? '') ?>"
                                   <?= !empty($editSource['is_official']) ? 'readonly' : 'required' ?>
                                   placeholder="https://repo.example.com oder /srv/http/katalog">
                        </div>
                        <div class="col-12 col-md-3">
                            <label for="source-priority" class="form-label">Priorität</label>
                            <input type="number" class="form-control" id="source-priority" name="priority"
                                   value="<?= $e($editSource['priority'] ?? 0) ?>"
                                   <?= !empty($editSource['is_official']) ? 'readonly' : '' ?>>
                        </div>

                        <!-- Strukturierte Trust-Key-Verwaltung -->
                        <div class="col-12 mt-4">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label mb-0 fw-semibold">Vertrauenswürdige Ed25519-Schlüssel</label>
                                <?php if (empty($editSource['is_official'])): ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-add-key-row">
                                        <i class="bi bi-plus-lg"></i> Schlüssel hinzufügen
                                    </button>
                                <?php endif; ?>
                            </div>
                            <p class="text-body-secondary small mb-2">
                                Geben Sie die autorisierten Ed25519-Public-Keys im Base64-Format ein. Markieren Sie diejenigen Schlüssel als <strong>Root-Key</strong>, die berechtigt sind, die Katalog-Metadaten (<code>root.json</code>) zu signieren.
                            </p>

                            <div id="trust-keys-container" class="d-flex flex-column gap-2 mb-3">
                                <?php
                                $existingTrusted = is_array($editSource['trusted_keys'] ?? null) ? $editSource['trusted_keys'] : [];
                                $existingRoots = is_array($editSource['root_key_ids'] ?? null) ? $editSource['root_key_ids'] : [];
                                if ($existingTrusted === []):
                                ?>
                                    <div class="row g-2 align-items-center trust-key-row">
                                        <div class="col-12 col-md-4">
                                            <input type="text" class="form-control form-control-sm font-monospace" name="trust_key_id[]" placeholder="Key-ID (z. B. repo-release-2026)" value="">
                                        </div>
                                        <div class="col-12 col-md-5">
                                            <input type="text" class="form-control form-control-sm font-monospace" name="trust_public_key[]" placeholder="Base64 Public Key" value="">
                                        </div>
                                        <div class="col-8 col-md-2">
                                            <div class="form-check form-switch mb-0">
                                                <input class="form-check-input" type="checkbox" name="trust_is_root[]" value="0" id="trust_root_0">
                                                <label class="form-check-label small" for="trust_root_0">Root-Key</label>
                                            </div>
                                        </div>
                                        <div class="col-4 col-md-1 text-end">
                                            <button type="button" class="btn btn-sm btn-outline-danger btn-remove-key-row" title="Zeile entfernen"><i class="bi bi-trash"></i></button>
                                        </div>
                                    </div>
                                <?php else:
                                    $rowIndex = 0;
                                    foreach ($existingTrusted as $kId => $kPub):
                                        $isRoot = in_array((string) $kId, $existingRoots, true);
                                ?>
                                    <div class="row g-2 align-items-center trust-key-row">
                                        <div class="col-12 col-md-4">
                                            <input type="text" class="form-control form-control-sm font-monospace" name="trust_key_id[]" placeholder="Key-ID" value="<?= $e($kId) ?>" <?= !empty($editSource['is_official']) ? 'readonly' : '' ?>>
                                        </div>
                                        <div class="col-12 col-md-5">
                                            <input type="text" class="form-control form-control-sm font-monospace" name="trust_public_key[]" placeholder="Base64 Public Key" value="<?= $e($kPub) ?>" <?= !empty($editSource['is_official']) ? 'readonly' : '' ?>>
                                        </div>
                                        <div class="col-8 col-md-2">
                                            <div class="form-check form-switch mb-0">
                                                <input class="form-check-input" type="checkbox" name="trust_is_root[]" value="<?= $e($rowIndex) ?>" id="trust_root_<?= $e($rowIndex) ?>" <?= $isRoot ? 'checked' : '' ?> <?= !empty($editSource['is_official']) ? 'disabled' : '' ?>>
                                                <label class="form-check-label small" for="trust_root_<?= $e($rowIndex) ?>">Root-Key</label>
                                            </div>
                                        </div>
                                        <div class="col-4 col-md-1 text-end">
                                            <?php if (empty($editSource['is_official'])): ?>
                                                <button type="button" class="btn btn-sm btn-outline-danger btn-remove-key-row" title="Zeile entfernen"><i class="bi bi-trash"></i></button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php
                                        $rowIndex++;
                                    endforeach;
                                endif;
                                ?>
                            </div>
                        </div>

                        <?php if ($editSource === null): ?>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="source-enabled" name="enabled" value="1" checked>
                                    <label class="form-check-label" for="source-enabled">Katalogquelle sofort aktivieren</label>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="col-12 d-flex gap-2">
                            <?php if (empty($editSource['is_official'])): ?>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save me-1"></i> <?= $editSource !== null ? 'Änderungen speichern' : 'Katalogquelle anlegen' ?>
                                </button>
                            <?php endif; ?>
                            <?php if ($editSource !== null): ?>
                                <a href="/admin/repository-manager" class="btn btn-outline-secondary">Abbrechen</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
        </section>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // 1. Live Repository Sync via AJAX
    const syncForm = document.getElementById('sync-form');
    const syncBtn = document.getElementById('btn-sync-mirror');
    const syncSpinner = document.getElementById('sync-spinner');
    const syncIcon = document.getElementById('sync-icon');
    const syncBtnText = document.getElementById('sync-btn-text');
    const syncAlertContainer = document.getElementById('sync-alert-container');

    let pollTimer = null;

    function showAlert(type, message) {
        if (!syncAlertContainer) return;
        syncAlertContainer.innerHTML = `
            <div class="alert alert-${type} alert-dismissible fade show my-3" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Schließen"></button>
            </div>`;
    }

    function setSyncButtonState(running) {
        if (!syncBtn) return;
        if (running) {
            syncBtn.disabled = true;
            if (syncSpinner) syncSpinner.classList.remove('d-none');
            if (syncIcon) syncIcon.classList.add('d-none');
            if (syncBtnText) syncBtnText.textContent = 'Synchronisiere...';
        } else {
            syncBtn.disabled = false;
            if (syncSpinner) syncSpinner.classList.add('d-none');
            if (syncIcon) syncIcon.classList.remove('d-none');
            if (syncBtnText) syncBtnText.textContent = 'Repository jetzt synchronisieren';
        }
    }

    function updateMirrorStatusUI(data) {
        const badgeEl = document.getElementById('mirror-status-badge');
        const seqEl = document.getElementById('mirror-sequence');
        const countEl = document.getElementById('mirror-modules-count');
        const snapEl = document.getElementById('mirror-snapshot');
        const timeEl = document.getElementById('mirror-timestamp');
        const fpEl = document.getElementById('mirror-fingerprint');

        if (badgeEl) {
            if (data.is_running) {
                badgeEl.innerHTML = '<span class="badge text-bg-warning"><i class="bi bi-hourglass-split me-1"></i>Sync läuft...</span>';
            } else if (data.available) {
                badgeEl.innerHTML = '<span class="badge text-bg-success"><i class="bi bi-check-circle me-1"></i>Bereit &amp; Verifiziert</span>';
            } else {
                badgeEl.innerHTML = '<span class="badge text-bg-secondary"><i class="bi bi-dash-circle me-1"></i>Nicht verfügbar</span>';
            }
        }

        if (seqEl && data.sequence) seqEl.textContent = 'Sequenz ' + data.sequence;
        if (countEl && data.module_count) countEl.textContent = data.module_count + ' Module (' + (data.catalog_id || '') + ')';
        if (snapEl && data.current_snapshot) {
            snapEl.textContent = data.current_snapshot;
            snapEl.title = data.current_snapshot;
        }
        if (timeEl && data.snapshot_timestamp) timeEl.textContent = data.snapshot_timestamp;
        if (fpEl && data.fingerprint) {
            fpEl.textContent = data.fingerprint.substring(0, 16) + '…';
            fpEl.title = data.fingerprint;
        }
    }

    function pollMirrorStatus() {
        fetch('/admin/repository-manager/mirror-status', {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
        .then(response => response.json())
        .then(data => {
            updateMirrorStatusUI(data);
            if (!data.is_running) {
                if (pollTimer) {
                    clearInterval(pollTimer);
                    pollTimer = null;
                }
                setSyncButtonState(false);
                showAlert('success', 'Repository-Synchronisation erfolgreich abgeschlossen.');
            }
        })
        .catch(err => {
            console.error('Status poll error:', err);
        });
    }

    if (syncForm) {
        syncForm.addEventListener('submit', function (e) {
            e.preventDefault();
            setSyncButtonState(true);
            const formData = new FormData(syncForm);

            fetch('/admin/repository-manager/sync', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: new URLSearchParams(formData)
            })
            .then(async response => {
                const data = await response.json();
                if (!response.ok) throw new Error(data.message || 'Fehler beim Starten des Syncs.');
                return data;
            })
            .then(result => {
                showAlert('info', result.message || 'Synchronisation gestartet.');
                if (result.status === 'completed' || result.status === 'already_running') {
                    setSyncButtonState(false);
                } else {
                    if (pollTimer) clearInterval(pollTimer);
                    pollTimer = setInterval(pollMirrorStatus, 2500);
                }
            })
            .catch(error => {
                setSyncButtonState(false);
                showAlert('danger', error.message || 'Verbindung fehlgeschlagen.');
            });
        });
    }

    // 2. Dynamische Schlüssel-Zeilen
    const btnAddKey = document.getElementById('btn-add-key-row');
    const trustContainer = document.getElementById('trust-keys-container');

    if (btnAddKey && trustContainer) {
        btnAddKey.addEventListener('click', function () {
            const rows = trustContainer.querySelectorAll('.trust-key-row');
            const newIndex = rows.length;

            const div = document.createElement('div');
            div.className = 'row g-2 align-items-center trust-key-row';
            div.innerHTML = `
                <div class="col-12 col-md-4">
                    <input type="text" class="form-control form-control-sm font-monospace" name="trust_key_id[]" placeholder="Key-ID (z. B. repo-release-2026)" value="">
                </div>
                <div class="col-12 col-md-5">
                    <input type="text" class="form-control form-control-sm font-monospace" name="trust_public_key[]" placeholder="Base64 Public Key" value="">
                </div>
                <div class="col-8 col-md-2">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" name="trust_is_root[]" value="${newIndex}" id="trust_root_${newIndex}">
                        <label class="form-check-label small" for="trust_root_${newIndex}">Root-Key</label>
                    </div>
                </div>
                <div class="col-4 col-md-1 text-end">
                    <button type="button" class="btn btn-sm btn-outline-danger btn-remove-key-row" title="Zeile entfernen"><i class="bi bi-trash"></i></button>
                </div>`;
            trustContainer.appendChild(div);
        });

        trustContainer.addEventListener('click', function (e) {
            const btn = e.target.closest('.btn-remove-key-row');
            if (!btn) return;
            const row = btn.closest('.trust-key-row');
            if (row) {
                const totalRows = trustContainer.querySelectorAll('.trust-key-row').length;
                if (totalRows > 1) {
                    row.remove();
                } else {
                    row.querySelectorAll('input[type="text"]').forEach(input => input.value = '');
                    row.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
                }
            }
        });
    }

    // 3. AJAX für Test-Button
    document.querySelectorAll('.test-source-form').forEach(form => {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const btn = form.querySelector('.test-btn');
            const spinner = btn ? btn.querySelector('.spinner-border') : null;
            const icon = btn ? btn.querySelector('i') : null;
            const sourceId = form.getAttribute('data-source-id');
            const formData = new FormData(form);

            if (btn) btn.disabled = true;
            if (spinner) spinner.classList.remove('d-none');
            if (icon) icon.classList.add('d-none');

            fetch('/admin/repository-manager/test', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: new URLSearchParams(formData)
            })
            .then(async response => {
                const data = await response.json();
                if (!response.ok) throw new Error(data.error || 'Test fehlgeschlagen.');
                return data;
            })
            .then(data => {
                alert(data.message || 'Verbindung erfolgreich!');
            })
            .catch(error => {
                alert('Test fehlgeschlagen: ' + error.message);
            })
            .finally(() => {
                if (btn) btn.disabled = false;
                if (spinner) spinner.classList.add('d-none');
                if (icon) icon.classList.remove('d-none');
            });
        });
    });
});
</script>
