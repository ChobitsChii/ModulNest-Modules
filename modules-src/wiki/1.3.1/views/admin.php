<?php
declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$source = is_array($source ?? null) ? $source : null;
$message = (string) ($message ?? '');
$error = (string) ($error ?? '');
$status = (string) ($source['last_sync_status'] ?? 'never');
$hasSuccessfulSync = $source !== null && (string) ($source['last_commit_sha'] ?? '') !== '';
$exampleSource = $source === null;
$statusLabel = $status === 'success' ? 'Erfolgreich' : ($status === 'failed' ? 'Fehlgeschlagen' : 'Noch nicht synchronisiert');
$statusClass = $status === 'success' ? 'text-bg-success' : ($status === 'failed' ? 'text-bg-danger' : 'text-bg-secondary');
$sourcePathUnavailable = $status === 'failed' && (string) ($source['last_error_code'] ?? '') === 'no_markdown_found';
$sourceType = (string) ($source['source_type'] ?? 'github');
$activeType = (string) ($source['active_source_type'] ?? '');
$activeType = $activeType === '' && $hasSuccessfulSync ? $sourceType : $activeType;
$activeGithub = $activeType === 'github';
$activeLocal = $activeType === 'local';
$configuredMatchesActive = $hasSuccessfulSync && $activeType === $sourceType
    && ($sourceType !== 'github' || ((string) ($source['active_repository_owner'] ?? '') === (string) ($source['repository_owner'] ?? '') && (string) ($source['active_repository_name'] ?? '') === (string) ($source['repository_name'] ?? '') && (string) ($source['active_ref_name'] ?? '') === (string) ($source['ref_name'] ?? '')))
    && (string) ($source['active_docs_root'] ?? '') === (string) ($source['docs_root'] ?? '');
$searchIndex = is_array($search_index ?? null) ? $search_index : ['status'=>'missing','version'=>\ModulNest\Wiki\WikiSearchIndexer::VERSION,'pages'=>0,'terms'=>0,'updated_at_local'=>''];
$searchStatus = (string) $searchIndex['status'];
$searchStatusLabel = $searchStatus === 'current' ? 'Aktuell' : ($searchStatus === 'stale' ? 'Veraltet' : 'Nicht erstellt');
$searchStatusClass = $searchStatus === 'current' ? 'text-bg-success' : ($searchStatus === 'stale' ? 'text-bg-warning' : 'text-bg-secondary');
?>
<link rel="stylesheet" href="<?= htmlspecialchars((string) ($wiki_asset_base ?? ''), ENT_QUOTES, 'UTF-8') ?>/wiki.css">
<div class="row g-4 wiki-admin">
    <div class="col-12">
        <div class="card shadow-sm border-0 app-card">
            <div class="card-body p-4">
                <p class="text-uppercase text-body-secondary small fw-semibold mb-1">Admin</p>
                <h1 class="h4 mb-2">Wiki verwalten</h1>
                <p class="text-body-secondary mb-0">Synchronisiere Markdown-Dokumentation aus einem öffentlichen GitHub-Repository und stelle sie lokal als Wiki bereit.</p>
                <p class="small text-success-emphasis mt-2 mb-0" data-wiki-independent-release>Eigenständiges Wiki-Modulrelease 1.3.0 aktiv.</p>
                <?php if ($message !== ''): ?><div class="alert alert-success mt-3 mb-0" role="status"><?= $e($message) ?></div><?php endif; ?>
                <?php if ($error !== ''): ?><div class="alert alert-danger mt-3 mb-0" role="alert"><?= $e($error) ?></div><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-7">
        <section class="card shadow-sm border-0 app-card h-100">
            <div class="card-body p-4">
                <h2 class="h5 mb-1">Wiki-Quelle</h2>
                <p class="text-body-secondary small mb-4">Wähle ein öffentliches GitHub-Repository oder ein lokales Verzeichnis. Inhalte bleiben nach einer erfolgreichen Synchronisierung lokal verfügbar.</p>
                <form method="post" action="/admin/wiki/save" class="row g-3" data-wiki-source-form>
                    <?= \Modulon\Core\View::csrfField($csrf_token) ?>
                    <div class="col-12"><label class="form-label" for="wiki_source_type">Quelltyp</label><select class="form-select" id="wiki_source_type" name="source_type" data-wiki-source-type><option value="github"<?= $sourceType === 'github' ? ' selected' : '' ?>>GitHub Repository</option><option value="local"<?= $sourceType === 'local' ? ' selected' : '' ?>>Lokales Verzeichnis</option></select></div>
                    <div class="col-12" data-wiki-local-help hidden><div class="alert alert-info mb-0">Synchronisiert Markdown-Dateien aus einem Verzeichnis innerhalb dieser ModulNest-Installation.</div></div>
                    <div class="col-12" data-wiki-github-field>
                        <label class="form-label" for="wiki_url">GitHub-URL <span class="text-body-secondary">(optional)</span></label>
                        <input class="form-control" id="wiki_url" type="url" inputmode="url" autocomplete="off" placeholder="https://github.com/ChobitsChii/ModulNest/tree/main/docs" data-wiki-url>
                        <div class="form-text">Alternativ kannst du hier die URL zum Repository oder Dokumentationsordner einfügen. Die Felder darunter werden automatisch ausgefüllt.</div>
                        <div class="invalid-feedback" data-wiki-url-feedback></div>
                    </div>
                    <div class="col-12 col-md-6" data-wiki-github-field>
                        <label class="form-label" for="wiki_owner">GitHub-Benutzer oder Organisation</label>
                        <input class="form-control" id="wiki_owner" required name="owner" maxlength="39" autocomplete="off" value="<?= $e($source['repository_owner'] ?? 'ChobitsChii') ?>">
                        <div class="form-text">Zum Beispiel: ChobitsChii</div>
                    </div>
                    <div class="col-12 col-md-6" data-wiki-github-field>
                        <label class="form-label" for="wiki_repository">Repository</label>
                        <input class="form-control" id="wiki_repository" required name="repository" maxlength="100" autocomplete="off" value="<?= $e($source['repository_name'] ?? 'ModulNest') ?>">
                        <div class="form-text">Zum Beispiel: ModulNest</div>
                    </div>
                    <div class="col-12 col-md-6" data-wiki-github-field>
                        <label class="form-label" for="wiki_ref">Branch oder Tag</label>
                        <input class="form-control" id="wiki_ref" name="ref" maxlength="160" value="<?= $e($source['ref_name'] ?? 'main') ?>">
                        <div class="form-text">Normalerweise <code>main</code>. Alternativ kann ein bestimmter Release-Tag verwendet werden.</div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="wiki_docs_root">Dokumentationsordner</label>
                        <div class="input-group"><input class="form-control" id="wiki_docs_root" required name="docs_root" maxlength="255" value="<?= $e($source['docs_root'] ?? 'docs') ?>"><button class="btn btn-outline-secondary" type="button" data-wiki-directory-picker<?= $sourceType === 'local' ? '' : ' hidden' ?>>Ordner auswählen</button></div>
                        <div class="form-text" data-wiki-path-help>Pfad relativ zur ModulNest-Installation bzw. innerhalb des GitHub-Repositories.</div>
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" id="wiki_enabled" type="checkbox" name="enabled" value="1" <?= !isset($source['enabled']) || (int) $source['enabled'] === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="wiki_enabled">Quelle aktiviert</label>
                        </div>
                    </div>
                    <div class="col-12"><button class="btn btn-primary" type="submit">Konfiguration speichern</button></div>
                </form>
            </div>
        </section>
    </div>

    <div class="col-12 col-xl-5">
        <section class="card shadow-sm border-0 app-card h-100">
            <div class="card-body p-4 d-flex flex-column">
                <?php if ($source !== null): ?>
                    <h2 class="h5 mb-3">Konfigurierte Quelle</h2>
                    <dl class="row small mb-4" data-wiki-configured-source>
                        <dt class="col-sm-5 text-body-secondary">Quelltyp</dt><dd class="col-sm-7"><?= $sourceType === 'local' ? 'Lokales Verzeichnis' : 'GitHub-Repository' ?></dd>
                        <?php if ($sourceType === 'github'): ?>
                            <dt class="col-sm-5 text-body-secondary">Repository</dt><dd class="col-sm-7 text-break"><?= $e((string) $source['repository_owner'] . '/' . (string) $source['repository_name']) ?></dd>
                            <dt class="col-sm-5 text-body-secondary">Branch oder Tag</dt><dd class="col-sm-7"><?= $e($source['ref_name'] ?? 'main') ?></dd>
                        <?php endif; ?>
                        <dt class="col-sm-5 text-body-secondary">Dokumentationsordner</dt><dd class="col-sm-7 text-break"><?= $e($source['docs_root'] ?? 'docs') ?></dd>
                        <dt class="col-sm-5 text-body-secondary">Quelle aktiviert</dt><dd class="col-sm-7"><?= (int) ($source['enabled'] ?? 0) === 1 ? 'Ja' : 'Nein' ?></dd>
                    </dl>
                    <h2 class="h5 mb-3 pt-3 border-top">Aktiver Wiki-Stand</h2>
                    <?php if (!$hasSuccessfulSync): ?>
                        <p class="text-body-secondary">Noch keine Dokumentation synchronisiert.</p>
                    <?php else: ?>
                        <dl class="row small mb-4" data-wiki-active-source>
                            <dt class="col-sm-5 text-body-secondary">Status</dt><dd class="col-sm-7"><span class="badge <?= $statusClass ?>"><?= $e($statusLabel) ?></span></dd>
                            <dt class="col-sm-5 text-body-secondary">Quelle</dt><dd class="col-sm-7"><?= $activeLocal ? 'Lokales Verzeichnis' : 'GitHub-Repository' ?></dd>
                            <?php if ($activeGithub): ?>
                                <dt class="col-sm-5 text-body-secondary">Repository</dt><dd class="col-sm-7 text-break"><?= $e((string) ($source['active_repository_owner'] ?? '') . '/' . (string) ($source['active_repository_name'] ?? '')) ?></dd>
                                <dt class="col-sm-5 text-body-secondary">Branch oder Tag</dt><dd class="col-sm-7"><?= $e($source['active_ref_name'] ?? 'main') ?></dd>
                            <?php endif; ?>
                            <dt class="col-sm-5 text-body-secondary">Dokumentationsordner</dt><dd class="col-sm-7 text-break"><?= $e($source['active_docs_root'] ?? $source['docs_root'] ?? 'docs') ?></dd>
                            <dt class="col-sm-5 text-body-secondary">Letzter Sync</dt><dd class="col-sm-7"><?= $e($source['last_sync_at_local'] ?? '—') ?></dd>
                            <dt class="col-sm-5 text-body-secondary"><?= $activeGithub ? 'Commit' : 'Inhalts-Hash' ?></dt><dd class="col-sm-7 font-monospace"><?= $e(!empty($source['last_commit_sha']) ? substr((string) $source['last_commit_sha'], 0, 12) : '—') ?></dd>
                            <dt class="col-sm-5 text-body-secondary">Seiten</dt><dd class="col-sm-7"><?= (int) $page_count ?></dd>
                            <dt class="col-sm-5 text-body-secondary">Bilder</dt><dd class="col-sm-7"><?= (int) $asset_count ?></dd>
                        </dl>
                        <?php if (!$configuredMatchesActive): ?><div class="alert alert-info small" role="status">Die konfigurierte Quelle wurde noch nicht synchronisiert. Der aktuell angezeigte Wiki-Inhalt stammt weiterhin vom letzten erfolgreichen Sync.</div><?php endif; ?>
                    <?php endif; ?>
                    <?php if ($status === 'failed'): ?>
                        <div class="alert alert-warning small" role="alert">
                            <?php if ($sourcePathUnavailable): ?>Der Dokumentationsordner enthält in der veröffentlichten Quelle noch keine unterstützten Markdown-Dateien. Prüfe Branch und Dokumentationsordner; bei ModulNest ist <code>docs/development</code> erst nutzbar, sobald diese Dokumentation öffentlich verfügbar ist.<?php else: ?>Die letzte Synchronisierung ist fehlgeschlagen. Der zuvor synchronisierte lokale Wiki-Stand bleibt verfügbar.<?php endif; ?>
                            <?php if (!empty($source['last_error_code'])): ?><details class="mt-2"><summary>Technische Details</summary><code><?= $e($source['last_error_code']) ?></code></details><?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="text-body-secondary">Speichere zuerst eine Quelle. Für ModulNest sind die vorgeschlagenen Werte bereits eingetragen.</p>
                <?php endif; ?>
                <form method="post" action="/admin/wiki/sync" class="mt-auto">
                    <?= \Modulon\Core\View::csrfField($csrf_token) ?>
                    <button class="btn btn-outline-primary" type="submit" <?= $source === null ? 'disabled' : '' ?>>Jetzt synchronisieren</button>
                </form>
            </div>
        </section>
    </div>
</div>
<div class="modal fade" id="wikiDirectoryPicker" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5">Ordner auswählen</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button></div><div class="modal-body"><p class="small text-body-secondary">Aktueller Pfad: <code data-wiki-picker-path>/</code></p><button class="btn btn-sm btn-outline-secondary mb-3" type="button" data-wiki-picker-parent hidden>← Zurück</button><div class="list-group wiki-directory-picker-list" data-wiki-picker-directories></div><div class="alert alert-danger mt-3 d-none" data-wiki-picker-error></div></div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Abbrechen</button><button class="btn btn-primary" type="button" data-wiki-picker-select>Dieses Verzeichnis auswählen</button></div></div></div></div>
<div class="row g-4 wiki-admin mt-0"><div class="col-12"><section class="card shadow-sm border-0 app-card"><div class="card-body p-4"><h2 class="h5 mb-2">Suchindex</h2><p class="text-body-secondary small mb-3">Die Suche verwendet einen lokalen Index und benötigt beim Suchen keinen Zugriff auf die Quelle.</p><dl class="row small mb-3" data-wiki-search-status><dt class="col-sm-4 text-body-secondary">Status</dt><dd class="col-sm-8"><span class="badge <?= $searchStatusClass ?>"><?= $e($searchStatusLabel) ?></span></dd><dt class="col-sm-4 text-body-secondary">Indexversion</dt><dd class="col-sm-8"><?= (int)$searchIndex['version'] ?></dd><dt class="col-sm-4 text-body-secondary">Indexierte Seiten</dt><dd class="col-sm-8"><?= (int)$searchIndex['pages'] ?></dd><dt class="col-sm-4 text-body-secondary">Begriffe</dt><dd class="col-sm-8"><?= (int)$searchIndex['terms'] ?></dd><dt class="col-sm-4 text-body-secondary">Zuletzt aktualisiert</dt><dd class="col-sm-8"><?= $e($searchIndex['updated_at_local'] ?: '—') ?></dd></dl><form method="post" action="/admin/wiki/search/rebuild"><?= \Modulon\Core\View::csrfField($csrf_token) ?><button class="btn btn-outline-primary" type="submit" <?= !$hasSuccessfulSync ? 'disabled' : '' ?>>Suchindex neu aufbauen</button></form></div></section></div></div>
<script src="<?= htmlspecialchars((string) ($wiki_asset_base ?? ''), ENT_QUOTES, 'UTF-8') ?>/wiki.js" defer></script>
