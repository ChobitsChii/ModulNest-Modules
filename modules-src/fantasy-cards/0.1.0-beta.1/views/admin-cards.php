<?php
declare(strict_types=1);

$cards = is_array($cards ?? null) ? $cards : [];
$sets = is_array($sets ?? null) ? $sets : [];
$rarities = is_array($rarities ?? null) ? $rarities : [];
$selectedSetId = (int) ($selected_set_id ?? 0);
$search = (string) ($search ?? '');
$status = (string) ($status ?? '');
$page = max(1, (int) ($page ?? 1));
$perPage = max(1, (int) ($per_page ?? 30));
$total = max(0, (int) ($total ?? count($cards)));
$pages = max(1, (int) ceil($total / $perPage));
$message = (string) ($message ?? '');
$error = (string) ($error ?? '');
$csrfToken = (string) ($csrf_token ?? '');
$e = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
$queryBase = static function (array $extra = []) use ($selectedSetId, $search, $status): string {
    return http_build_query(array_filter(array_merge([
        'set' => $selectedSetId > 0 ? (string) $selectedSetId : null,
        'q' => $search !== '' ? $search : null,
        'status' => $status !== '' ? $status : null,
    ], $extra), static fn ($value): bool => $value !== null && $value !== ''));
};
?>

<?php require __DIR__ . '/admin-nav.php'; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <p class="text-uppercase text-body-secondary small fw-semibold mb-1">Fantasy Cards Admin</p>
        <h1 class="h4 mb-1">Karten</h1>
        <p class="text-body-secondary mb-0">Karten als Asset-Management mit Inline-Editor, Filtern und Sortierung.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-primary" href="/admin/fantasy-cards/upload">Massen-Upload</a>
        <a class="btn btn-primary" href="/admin/fantasy-cards/cards/create">Karte anlegen</a>
    </div>
</div>

<?php if ($message !== ''): ?><div class="alert alert-success"><?= $e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>

<form class="app-card p-3 mb-4 js-fantasycards-card-filter" method="get" action="/admin/fantasy-cards/cards">
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small" for="fc-card-set-filter">Set</label>
            <select class="form-select" id="fc-card-set-filter" name="set">
                <option value="0">Alle Sets</option>
                <?php foreach ($sets as $set): ?>
                    <option value="<?= (int) ($set['id'] ?? 0) ?>" <?= (int) ($set['id'] ?? 0) === $selectedSetId ? 'selected' : '' ?>><?= $e($set['name'] ?? '') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small" for="fc-card-status-filter">Status</label>
            <select class="form-select" id="fc-card-status-filter" name="status">
                <option value="">Alle</option>
                <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option>
                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Aktiv</option>
                <option value="retired" <?= $status === 'retired' ? 'selected' : '' ?>>Archiviert</option>
            </select>
        </div>
        <div class="col-md">
            <label class="form-label small" for="fc-card-search">Suche</label>
            <input class="form-control" id="fc-card-search" name="q" value="<?= $e($search) ?>" placeholder="Name, Nummer, Beschreibung">
        </div>
        <div class="col-md-auto">
            <noscript><button class="btn btn-outline-primary" type="submit">Filtern</button></noscript>
        </div>
    </div>
</form>

<div class="app-card p-3 mb-3">
    <div class="fantasycards-card-toolbar">
        <div class="d-flex flex-wrap align-items-center gap-2 js-fantasycards-bulk-actions">
            <span class="small text-body-secondary js-fantasycards-selected-count">0 ausgewählt</span>
            <select class="form-select form-select-sm fantasycards-bulk-action-select" aria-label="Bulk-Aktion auswählen">
                <option value="">Bulk-Aktion</option>
                <optgroup label="Status">
                    <option value="status:draft">Status: Draft</option>
                    <option value="status:active">Status: Aktiv</option>
                    <option value="status:retired">Status: Archiviert</option>
                </optgroup>
                <optgroup label="Aktivstatus">
                    <option value="active:1">Aktivieren</option>
                    <option value="active:0">Deaktivieren</option>
                </optgroup>
                <optgroup label="Booster">
                    <option value="booster:1">Für Booster erlauben</option>
                    <option value="booster:0">Für Booster sperren</option>
                </optgroup>
                <optgroup label="Löschen">
                    <option value="delete">Ausgewählte Karten löschen</option>
                </optgroup>
            </select>
            <button type="button" class="btn btn-sm btn-outline-primary js-fantasycards-bulk-apply" disabled>Anwenden</button>
        </div>
        <div class="fantasycards-card-toolbar-hint text-body-secondary small">Sortierung per Drag-Handle innerhalb des gewählten Sets</div>
        <div class="fantasycards-card-toolbar-count">
            <strong><?= $total ?></strong> Karten
            <span class="text-body-secondary small">· Seite <?= $page ?> von <?= $pages ?></span>
        </div>
    </div>
</div>

<div id="fantasycards-admin-feedback" class="small mb-2" aria-live="polite"></div>

<div class="app-card table-responsive fantasycards-admin-card-list" data-csrf-token="<?= $e($csrfToken) ?>" data-inline-url="/admin/fantasy-cards/cards/inline" data-reorder-url="/admin/fantasy-cards/cards/reorder" data-bulk-url="/admin/fantasy-cards/cards/bulk" data-current-set="<?= $selectedSetId ?>">
    <table class="table table-hover align-middle mb-0 app-table">
        <thead>
            <tr>
                <th class="ps-4"><input class="form-check-input js-fantasycards-select-all" type="checkbox" aria-label="Alle sichtbaren Karten auswählen"></th>
                <th>Bild</th>
                <th>Karte</th>
                <th>Meta</th>
                <th>Status</th>
                <th class="pe-4 text-end">Aktionen</th>
            </tr>
        <tbody id="fantasycards-card-tbody">
            <?php if ($cards === []): ?>
                <tr><td colspan="6" class="ps-4 text-body-secondary">Keine Karten gefunden.</td></tr>
            <?php else: ?>
                <?php foreach ($cards as $card): ?>
                    <?php
                    $cardId = (int) ($card['id'] ?? 0);
                    $rarityKey = (string) ($card['rarity'] ?? 'common');
                    $rarity = $rarities[$rarityKey] ?? ['label' => $rarityKey, 'badge' => 'text-bg-secondary'];
                    $thumb = trim((string) ($card['thumbnail_path'] ?? ''));
                    $fullImage = trim((string) ($card['image_path'] ?? ''));
                    $image = $thumb !== '' ? $thumb : $fullImage;
                    $cardTitle = (string) ($card['name'] ?? '');
                    $caption = trim((string) ($card['card_number'] ?? '') . ' · ' . (string) ($rarity['label'] ?? ''));
                    ?>
                    <tr class="fantasycards-card-row" data-card-id="<?= $cardId ?>" data-set-id="<?= (int) ($card['set_id'] ?? 0) ?>">
                        <td class="ps-4">
                            <button type="button" class="fantasycards-drag-handle" title="Per Drag & Drop sortieren" aria-label="Karte sortieren">⋮⋮</button>
                            <input class="form-check-input ms-2 js-fantasycards-card-checkbox" type="checkbox" value="<?= $cardId ?>" aria-label="Karte auswählen">
                        </td>
                        <td>
                            <div class="fantasycards-admin-thumb">
                                <?php if ($image !== ''): ?>
                                    <?php if ($fullImage !== ''): ?>
                                        <button type="button" class="fantasycards-image-button" data-fantasycards-lightbox data-full-image="<?= $e($fullImage) ?>" data-title="<?= $e($cardTitle) ?>" data-caption="<?= $e($caption) ?>">
                                            <img src="<?= $e($image) ?>" alt="<?= $e($cardTitle) ?>" loading="lazy">
                                        </button>
                                    <?php else: ?>
                                        <img src="<?= $e($image) ?>" alt="<?= $e($cardTitle) ?>" loading="lazy">
                                    <?php endif; ?>
                                <?php else: ?><span>Bild</span><?php endif; ?>
                            </div>
                        </td>
                        <td class="fantasycards-inline-fields">
                            <input class="form-control form-control-sm fantasycards-inline-input" data-field="name" value="<?= $e($card['name'] ?? '') ?>" aria-label="Name">
                            <textarea class="form-control form-control-sm fantasycards-inline-input mt-2" data-field="description" rows="2" aria-label="Beschreibung"><?= $e($card['description'] ?? '') ?></textarea>
                        </td>
                        <td class="fantasycards-inline-fields">
                            <div class="row g-2">
                                <div class="col-6"><input class="form-control form-control-sm fantasycards-inline-input" data-field="card_number" value="<?= $e($card['card_number'] ?? '') ?>" placeholder="Nummer"></div>
                                <div class="col-6"><input class="form-control form-control-sm fantasycards-inline-input" data-field="sort_order" type="number" value="<?= (int) ($card['sort_order'] ?? 0) ?>" placeholder="Sortierung"></div>
                                <div class="col-12">
                                    <select class="form-select form-select-sm fantasycards-inline-input" data-field="rarity">
                                        <?php foreach ($rarities as $key => $item): ?>
                                            <option value="<?= $e($key) ?>" <?= $rarityKey === (string) $key ? 'selected' : '' ?>><?= $e($item['label'] ?? $key) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6"><input class="form-control form-control-sm fantasycards-inline-input" data-field="faction" value="<?= $e($card['faction'] ?? '') ?>" placeholder="Fraktion"></div>
                                <div class="col-6"><input class="form-control form-control-sm fantasycards-inline-input" data-field="element_name" value="<?= $e($card['element_name'] ?? '') ?>" placeholder="Element"></div>
                            </div>
                            <span class="badge mt-2 <?= $e($rarity['badge'] ?? 'text-bg-secondary') ?>"><?= $e($rarity['label'] ?? '') ?></span>
                        </td>
                        <td class="fantasycards-inline-fields">
                            <select class="form-select form-select-sm fantasycards-inline-input mb-2" data-field="status">
                                <option value="draft" <?= (string) ($card['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Draft</option>
                                <option value="active" <?= (string) ($card['status'] ?? '') === 'active' ? 'selected' : '' ?>>Aktiv</option>
                                <option value="retired" <?= (string) ($card['status'] ?? '') === 'retired' ? 'selected' : '' ?>>Archiviert</option>
                            </select>
                            <label class="form-check small"><input class="form-check-input fantasycards-inline-input" data-field="is_active" type="checkbox" value="1" <?= (int) ($card['is_active'] ?? 0) === 1 ? 'checked' : '' ?>> Aktivstatus</label>
                            <label class="form-check small"><input class="form-check-input fantasycards-inline-input" data-field="available_in_boosters" type="checkbox" value="1" <?= (int) ($card['available_in_boosters'] ?? 0) === 1 ? 'checked' : '' ?>> Booster</label>
                            <div class="fantasycards-save-state small text-body-secondary mt-2"></div>
                        </td>
                        <td class="pe-4 text-end"><a class="btn btn-sm btn-outline-primary" href="/admin/fantasy-cards/cards/<?= $cardId ?>/edit">Details</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="app-card p-3 mt-3">
    <div class="fantasycards-card-toolbar">
        <div class="d-flex flex-wrap align-items-center gap-2 js-fantasycards-bulk-actions">
            <span class="small text-body-secondary js-fantasycards-selected-count">0 ausgewählt</span>
            <select class="form-select form-select-sm fantasycards-bulk-action-select" aria-label="Bulk-Aktion auswählen">
                <option value="">Bulk-Aktion</option>
                <optgroup label="Status">
                    <option value="status:draft">Status: Draft</option>
                    <option value="status:active">Status: Aktiv</option>
                    <option value="status:retired">Status: Archiviert</option>
                </optgroup>
                <optgroup label="Aktivstatus">
                    <option value="active:1">Aktivieren</option>
                    <option value="active:0">Deaktivieren</option>
                </optgroup>
                <optgroup label="Booster">
                    <option value="booster:1">Für Booster erlauben</option>
                    <option value="booster:0">Für Booster sperren</option>
                </optgroup>
                <optgroup label="Löschen">
                    <option value="delete">Ausgewählte Karten löschen</option>
                </optgroup>
            </select>
            <button type="button" class="btn btn-sm btn-outline-primary js-fantasycards-bulk-apply" disabled>Anwenden</button>
        </div>
        <div class="fantasycards-card-toolbar-hint text-body-secondary small">Sortierung per Drag-Handle innerhalb des gewählten Sets</div>
        <div class="fantasycards-card-toolbar-count">
            <strong><?= $total ?></strong> Karten
            <span class="text-body-secondary small">· Seite <?= $page ?> von <?= $pages ?></span>
        </div>
    </div>
</div>

<?php if ($pages > 1): ?>
    <nav class="mt-3" aria-label="Karten-Pagination">
        <ul class="pagination pagination-sm">
            <?php for ($i = 1; $i <= $pages; $i++): ?>
                <?php $query = $queryBase(['page' => (string) $i]); ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="/admin/fantasy-cards/cards?<?= $e($query) ?>"><?= $i ?></a></li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>

<script src="/assets/js/fantasycards-lightbox.js" defer></script>
<script src="/assets/js/fantasycards-admin.js"></script>
