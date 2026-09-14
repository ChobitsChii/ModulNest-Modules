<?php
declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$sources = is_array($sources ?? null) ? $sources : [];
$editSource = is_array($edit_source ?? null) ? $edit_source : null;
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
                <p class="text-body-secondary mb-0">Verwaltet Modul-Katalogquellen. In dieser Beta werden ausschließlich Katalogquellen gepflegt; Trust, Validierung und Konflikt-Auflösung bleiben Aufgabe des Core.</p>
                <p class="small text-success-emphasis mt-2 mb-0">Eigenständiges Modulrelease 0.1.0-beta.1 aktiv.</p>
                <?php if ($message !== ''): ?><div class="alert alert-success mt-3 mb-0" role="status"><?= $e($message) ?></div><?php endif; ?>
                <?php if ($error !== ''): ?><div class="alert alert-danger mt-3 mb-0" role="alert"><?= $e($error) ?></div><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12">
        <section class="card shadow-sm border-0 app-card">
            <div class="card-body p-4">
                <h2 class="h5 mb-3">Vorhandene Katalogquellen</h2>
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
                                        <?php if ($enabled): ?><span class="badge text-bg-success">Aktiviert</span><?php else: ?><span class="badge text-bg-secondary">Deaktiviert</span><?php endif; ?>
                                    </td>
                                    <td><?= (int) ($source['priority'] ?? 0) ?></td>
                                    <td>
                                        <?php if ($keyDetails === []): ?>
                                            <span class="text-body-secondary">—</span>
                                        <?php else: ?>
                                            <ul class="list-unstyled mb-0 small">
                                                <?php foreach ($keyDetails as $key): ?>
                                                    <li class="mb-1">
                                                        <code class="fw-semibold"><?= $e($key['key_id']) ?></code>
                                                        <?php if (!empty($key['is_root'])): ?><span class="badge text-bg-info ms-1">Root</span><?php endif; ?>
                                                        <?php if (!empty($key['fingerprint'])): ?>
                                                            <div class="font-monospace text-body-secondary text-truncate" style="max-width: 200px;" title="SHA-256: <?= $e($key['fingerprint']) ?>">
                                                                FP: <?= $e(substr((string) $key['fingerprint'], 0, 16)) ?>…
                                                            </div>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div id="test-feedback-<?= $e($source['id']) ?>">
                                            <?php if (!empty($source['last_error_message'])): ?>
                                                <span class="text-danger small d-block"><?= $e($source['last_error_code'] ?? '') ?>: <?= $e($source['last_error_message']) ?></span>
                                            <?php elseif (!empty($source['last_success_local'])): ?>
                                                <span class="text-body-secondary small d-block">Zuletzt erfolgreich: <?= $e($source['last_success_local']) ?></span>
                                            <?php else: ?>
                                                <span class="text-body-secondary small d-block">—</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <form method="post" action="/admin/repository-manager/test" class="d-inline form-test-source" data-source-id="<?= $e($source['id']) ?>">
                                            <?= \Modulon\Core\View::csrfField((string) ($csrf_token ?? '')) ?>
                                            <input type="hidden" name="id" value="<?= $e($source['id']) ?>">
                                            <button class="btn btn-sm btn-outline-info btn-test-source" type="submit" data-source-id="<?= $e($source['id']) ?>">Testen</button>
                                        </form>
                                        <a class="btn btn-sm btn-outline-secondary" href="/admin/repository-manager?edit=<?= $e(rawurlencode((string) $source['id'])) ?>">Bearbeiten</a>
                                        <?php if ($enabled && !$isOfficial): ?>
                                            <form method="post" action="/admin/repository-manager/disable" class="d-inline">
                                                <?= \Modulon\Core\View::csrfField((string) ($csrf_token ?? '')) ?>
                                                <input type="hidden" name="id" value="<?= $e($source['id']) ?>">
                                                <button class="btn btn-sm btn-outline-warning" type="submit">Deaktivieren</button>
                                            </form>
                                        <?php elseif (!$enabled): ?>
                                            <form method="post" action="/admin/repository-manager/enable" class="d-inline">
                                                <?= \Modulon\Core\View::csrfField((string) ($csrf_token ?? '')) ?>
                                                <input type="hidden" name="id" value="<?= $e($source['id']) ?>">
                                                <button class="btn btn-sm btn-outline-success" type="submit">Aktivieren</button>
                                            </form>
                                        <?php endif; ?>
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

    <div class="col-12 col-xl-6">
        <section class="card shadow-sm border-0 app-card h-100">
            <div class="card-body p-4">
                <h2 class="h5 mb-3">Katalogquelle hinzufügen</h2>
                <form method="post" action="/admin/repository-manager/add" class="row g-3">
                    <?= \Modulon\Core\View::csrfField((string) ($csrf_token ?? '')) ?>
                    <div class="col-12">
                        <label class="form-label" for="rm_add_id">ID</label>
                        <input class="form-control" id="rm_add_id" name="id" required maxlength="120" pattern="[a-z][a-z0-9.\-]{2,119}" autocomplete="off" placeholder="publisher.source-name">
                        <div class="form-text">Unveränderliche, kleingeschriebene ID im Format <code>publisher.name</code>.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="rm_add_name">Anzeigename</label>
                        <input class="form-control" id="rm_add_name" name="name" required maxlength="160" autocomplete="off" placeholder="z. B. Mein Publisher Repository">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="rm_add_type">Quelltyp</label>
                        <select class="form-select" id="rm_add_type" name="source_type">
                            <option value="https" selected>HTTPS-URL</option>
                            <option value="local">Lokales Verzeichnis</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="rm_add_priority">Priorität</label>
                        <input class="form-control" id="rm_add_priority" name="priority" type="number" step="1" value="0">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="rm_add_location">URL bzw. Pfad</label>
                        <input class="form-control" id="rm_add_location" name="location" required maxlength="2048" autocomplete="off" placeholder="https://repo.example.test">
                        <div class="form-text">HTTPS-Basis-URL ohne Zugangsdaten oder ein sicherer absoluter lokaler Pfad.</div>
                    </div>

                    <div class="col-12">
                        <label class="form-label d-block fw-semibold mb-2">Vertrauenswürdige Signaturschlüssel (Trust Keys)</label>
                        <div class="form-text mb-2">Trage die Ed25519-Public-Keys der Katalog-Herausgeber ein. Mindestens ein Schlüssel muss als Root-Key markiert werden.</div>
                        <div id="add_trust_keys_container" class="d-flex flex-column gap-2 mb-2">
                            <div class="card bg-body-tertiary border p-3 trust-key-row">
                                <div class="row g-2 align-items-center">
                                    <div class="col-12 col-md-4">
                                        <label class="form-label small mb-1">Schlüssel-ID</label>
                                        <input class="form-control form-control-sm font-monospace" name="trust_key_id[]" required placeholder="z. B. publisher-2026-root">
                                    </div>
                                    <div class="col-12 col-md-5">
                                        <label class="form-label small mb-1">Public Key (Base64)</label>
                                        <input class="form-control form-control-sm font-monospace" name="trust_public_key[]" required placeholder="Ed25519 Base64 Public Key">
                                    </div>
                                    <div class="col-6 col-md-2 d-flex align-items-center pt-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input trust-root-checkbox" type="checkbox" name="trust_is_root[]" value="0" id="add_root_0" checked>
                                            <label class="form-check-label small" for="add_root_0">Root-Key</label>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-1 text-end pt-md-4">
                                        <button type="button" class="btn btn-sm btn-outline-danger btn-remove-key" title="Schlüssel entfernen" disabled>&times;</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary mt-1" id="btn_add_key_row">+ Weiteren Schlüssel hinzufügen</button>
                    </div>

                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" id="rm_add_enabled" type="checkbox" name="enabled" value="1" checked>
                            <label class="form-check-label" for="rm_add_enabled">Quelle aktiviert</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary" type="submit">Quelle hinzufügen</button>
                    </div>
                </form>
            </div>
        </section>
    </div>

    <div class="col-12 col-xl-6">
        <section class="card shadow-sm border-0 app-card h-100">
            <div class="card-body p-4">
                <h2 class="h5 mb-3">Katalogquelle bearbeiten</h2>
                <?php if ($editSource === null): ?>
                    <p class="text-body-secondary mb-0">Wähle in der Tabelle oben „Bearbeiten“, um eine vorhandene Quelle anzupassen. Die offizielle ModulNest-Quelle ist gegen versehentliches Überschreiben geschützt.</p>
                <?php else:
                    $isOfficial = (bool) ($editSource['is_official'] ?? false);
                    $editKeys = is_array($editSource['key_details'] ?? null) ? $editSource['key_details'] : [];
                ?>
                    <?php if ($isOfficial): ?><div class="alert alert-info mb-3" role="alert">Dies ist die offizielle ModulNest-Katalogquelle. Sie kann hier nicht verändert werden; Name, Typ, Ziel, Priorität und Aktivstatus sind geschützt.</div><?php endif; ?>
                    <form method="post" action="/admin/repository-manager/update" class="row g-3">
                        <?= \Modulon\Core\View::csrfField((string) ($csrf_token ?? '')) ?>
                        <input type="hidden" name="id" value="<?= $e($editSource['id']) ?>">
                        <div class="col-12">
                            <label class="form-label" for="rm_edit_name">Anzeigename</label>
                            <input class="form-control" id="rm_edit_name" name="name" required maxlength="160" value="<?= $e($editSource['name'] ?? '') ?>" <?= $isOfficial ? 'readonly' : '' ?>>
                            <?php if ($isOfficial): ?><div class="form-text">Geschützt.</div><?php endif; ?>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="rm_edit_type">Quelltyp</label>
                            <select class="form-select" id="rm_edit_type" name="source_type" <?= $isOfficial ? 'disabled' : '' ?>>
                                <option value="https"<?= ($editSource['source_type'] ?? '') === 'https' ? ' selected' : '' ?>>HTTPS-URL</option>
                                <option value="local"<?= ($editSource['source_type'] ?? '') === 'local' ? ' selected' : '' ?>>Lokales Verzeichnis</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="rm_edit_priority">Priorität</label>
                            <input class="form-control" id="rm_edit_priority" name="priority" type="number" step="1" value="<?= (int) ($editSource['priority'] ?? 0) ?>" <?= $isOfficial ? 'readonly' : '' ?>>
                            <?php if ($isOfficial): ?><div class="form-text">Geschützt.</div><?php endif; ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="rm_edit_location">URL bzw. Pfad</label>
                            <input class="form-control" id="rm_edit_location" name="location" required maxlength="2048" value="<?= $e($editSource['location'] ?? '') ?>" <?= $isOfficial ? 'readonly' : '' ?>>
                        </div>

                        <div class="col-12">
                            <label class="form-label d-block fw-semibold mb-2">Vertrauenswürdige Signaturschlüssel (Trust Keys)</label>
                            <div class="form-text mb-2">Verwalte die Schlüssel dieser Quelle. Mindestens ein Schlüssel muss als Root-Key markiert sein.</div>
                            <div id="edit_trust_keys_container" class="d-flex flex-column gap-2 mb-2">
                                <?php if ($editKeys === []): ?>
                                    <div class="card bg-body-tertiary border p-3 trust-key-row">
                                        <div class="row g-2 align-items-center">
                                            <div class="col-12 col-md-4">
                                                <label class="form-label small mb-1">Schlüssel-ID</label>
                                                <input class="form-control form-control-sm font-monospace" name="trust_key_id[]" required placeholder="z. B. key-id" <?= $isOfficial ? 'readonly' : '' ?>>
                                            </div>
                                            <div class="col-12 col-md-5">
                                                <label class="form-label small mb-1">Public Key (Base64)</label>
                                                <input class="form-control form-control-sm font-monospace" name="trust_public_key[]" required placeholder="Ed25519 Base64" <?= $isOfficial ? 'readonly' : '' ?>>
                                            </div>
                                            <div class="col-6 col-md-2 d-flex align-items-center pt-md-4">
                                                <div class="form-check">
                                                    <input class="form-check-input trust-root-checkbox" type="checkbox" name="trust_is_root[]" value="0" id="edit_root_0" checked <?= $isOfficial ? 'disabled' : '' ?>>
                                                    <label class="form-check-label small" for="edit_root_0">Root-Key</label>
                                                </div>
                                            </div>
                                            <div class="col-6 col-md-1 text-end pt-md-4">
                                                <button type="button" class="btn btn-sm btn-outline-danger btn-remove-key" title="Schlüssel entfernen" disabled>&times;</button>
                                            </div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($editKeys as $idx => $key): ?>
                                        <div class="card bg-body-tertiary border p-3 trust-key-row">
                                            <div class="row g-2 align-items-center">
                                                <div class="col-12 col-md-4">
                                                    <label class="form-label small mb-1">Schlüssel-ID</label>
                                                    <input class="form-control form-control-sm font-monospace" name="trust_key_id[]" required value="<?= $e($key['key_id']) ?>" <?= $isOfficial ? 'readonly' : '' ?>>
                                                </div>
                                                <div class="col-12 col-md-5">
                                                    <label class="form-label small mb-1">Public Key (Base64)</label>
                                                    <input class="form-control form-control-sm font-monospace" name="trust_public_key[]" required value="<?= $e($key['public_key']) ?>" <?= $isOfficial ? 'readonly' : '' ?>>
                                                </div>
                                                <div class="col-6 col-md-2 d-flex align-items-center pt-md-4">
                                                    <div class="form-check">
                                                        <input class="form-check-input trust-root-checkbox" type="checkbox" name="trust_is_root[]" value="<?= $idx ?>" id="edit_root_<?= $idx ?>" <?= !empty($key['is_root']) ? 'checked' : '' ?> <?= $isOfficial ? 'disabled' : '' ?>>
                                                        <label class="form-check-label small" for="edit_root_<?= $idx ?>">Root-Key</label>
                                                    </div>
                                                </div>
                                                <div class="col-6 col-md-1 text-end pt-md-4">
                                                    <button type="button" class="btn btn-sm btn-outline-danger btn-remove-key" title="Schlüssel entfernen" <?= ($isOfficial || count($editKeys) <= 1) ? 'disabled' : '' ?>>&times;</button>
                                                </div>
                                                <?php if (!empty($key['fingerprint'])): ?>
                                                    <div class="col-12 small font-monospace text-body-secondary mt-1">
                                                        SHA-256 Fingerprint: <span class="text-break"><?= $e($key['fingerprint']) ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <?php if (!$isOfficial): ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary mt-1" id="btn_edit_key_row">+ Weiteren Schlüssel hinzufügen</button>
                            <?php endif; ?>
                        </div>

                        <div class="col-12">
                            <button class="btn btn-primary" type="submit" <?= $isOfficial ? 'disabled' : '' ?>>Änderungen speichern</button>
                            <a class="btn btn-outline-secondary ms-2" href="/admin/repository-manager">Abbrechen</a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<script>
(function() {
    function reindexRows(container) {
        var rows = container.querySelectorAll('.trust-key-row');
        rows.forEach(function(row, index) {
            var cb = row.querySelector('.trust-root-checkbox');
            if (cb) {
                cb.value = index;
                cb.id = container.id + '_root_' + index;
                var label = row.querySelector('label[for*="_root_"]');
                if (label) label.setAttribute('for', cb.id);
            }
            var removeBtn = row.querySelector('.btn-remove-key');
            if (removeBtn && !removeBtn.dataset.permanentDisabled) {
                removeBtn.disabled = rows.length <= 1;
            }
        });
    }

    function createRowTemplate(containerId) {
        var div = document.createElement('div');
        div.className = 'card bg-body-tertiary border p-3 trust-key-row';
        div.innerHTML = '<div class="row g-2 align-items-center">' +
            '<div class="col-12 col-md-4">' +
                '<label class="form-label small mb-1">Schlüssel-ID</label>' +
                '<input class="form-control form-control-sm font-monospace" name="trust_key_id[]" required placeholder="z. B. publisher-2026-root">' +
            '</div>' +
            '<div class="col-12 col-md-5">' +
                '<label class="form-label small mb-1">Public Key (Base64)</label>' +
                '<input class="form-control form-control-sm font-monospace" name="trust_public_key[]" required placeholder="Ed25519 Base64">' +
            '</div>' +
            '<div class="col-6 col-md-2 d-flex align-items-center pt-md-4">' +
                '<div class="form-check">' +
                    '<input class="form-check-input trust-root-checkbox" type="checkbox" name="trust_is_root[]" value="0">' +
                    '<label class="form-check-label small">Root-Key</label>' +
                '</div>' +
            '</div>' +
            '<div class="col-6 col-md-1 text-end pt-md-4">' +
                '<button type="button" class="btn btn-sm btn-outline-danger btn-remove-key" title="Schlüssel entfernen">&times;</button>' +
            '</div>' +
        '</div>';
        return div;
    }

    function setupContainer(containerId, addBtnId) {
        var container = document.getElementById(containerId);
        var addBtn = document.getElementById(addBtnId);
        if (!container) return;

        if (addBtn) {
            addBtn.addEventListener('click', function() {
                var row = createRowTemplate(containerId);
                container.appendChild(row);
                reindexRows(container);
            });
        }

        container.addEventListener('click', function(e) {
            if (e.target.closest('.btn-remove-key')) {
                var row = e.target.closest('.trust-key-row');
                if (row && container.querySelectorAll('.trust-key-row').length > 1) {
                    row.remove();
                    reindexRows(container);
                }
            }
        });

        reindexRows(container);
    }

    setupContainer('add_trust_keys_container', 'btn_add_key_row');
    setupContainer('edit_trust_keys_container', 'btn_edit_key_row');

    // AJAX Test Connection
    document.querySelectorAll('.form-test-source').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var sourceId = form.dataset.sourceId;
            var btn = form.querySelector('.btn-test-source');
            var feedback = document.getElementById('test-feedback-' + sourceId);
            if (!btn || !feedback) return;

            var originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Prüfe...';

            feedback.innerHTML = '<span class="text-body-secondary small"><span class="spinner-border spinner-border-sm me-1" role="status"></span>Verbindung und Signatur werden geprüft...</span>';

            var formData = new FormData(form);
            var params = new URLSearchParams();
            formData.forEach(function(val, key) { params.append(key, val); });
            params.append('format', 'json');

            fetch(form.action, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: params.toString()
            })
            .then(function(res) {
                return res.json().then(function(data) {
                    return { status: res.status, ok: res.ok, data: data };
                }).catch(function() {
                    return { status: res.status, ok: res.ok, data: { error: 'Ungültige Serverantwort.' } };
                });
            })
            .then(function(result) {
                btn.disabled = false;
                btn.textContent = originalText;
                if (result.ok && result.data.success) {
                    feedback.innerHTML = '<span class="text-success small fw-semibold d-block">✓ ' + (result.data.message || 'Prüfung erfolgreich') + '</span>';
                } else {
                    var err = result.data.error || 'Prüfung fehlgeschlagen.';
                    feedback.innerHTML = '<span class="text-danger small fw-semibold d-block">✗ ' + err + '</span>';
                }
            })
            .catch(function(err) {
                btn.disabled = false;
                btn.textContent = originalText;
                feedback.innerHTML = '<span class="text-danger small d-block">✗ Netzwerkfehler: ' + err.message + '</span>';
            });
        });
    });
})();
</script>
