<?php
declare(strict_types=1);

$card = is_array($card ?? null) ? $card : null;
$sets = is_array($sets ?? null) ? $sets : [];
$rarities = is_array($rarities ?? null) ? $rarities : [];
$message = (string) ($message ?? '');
$error = (string) ($error ?? '');
$csrfToken = (string) ($csrf_token ?? '');
$e = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
$id = (int) ($card['id'] ?? 0);
$fullImage = trim((string) ($card['image_path'] ?? ''));
$thumbImage = trim((string) ($card['thumbnail_path'] ?? ''));
$previewImage = $thumbImage !== '' ? $thumbImage : $fullImage;
$cardTitle = (string) ($card['name'] ?? '');
?>

<?php require __DIR__ . '/admin-nav.php'; ?>

<div class="d-flex justify-content-between align-items-start gap-3 mb-4">
    <div>
        <p class="text-uppercase text-body-secondary small fw-semibold mb-1">Fantasy Cards Admin</p>
        <h1 class="h4 mb-1"><?= $id > 0 ? 'Karte bearbeiten' : 'Karte anlegen' ?></h1>
    </div>
    <a class="btn btn-outline-secondary" href="/admin/fantasy-cards/cards">Zurück</a>
</div>

<?php if ($message !== ''): ?><div class="alert alert-success"><?= $e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>

<form class="app-card p-4" method="post" action="/admin/fantasy-cards/cards/save">
    <?= \Modulon\Core\View::csrfField($csrfToken) ?>
    <input type="hidden" name="card_id" value="<?= $id ?>">
    <div class="row g-3">
        <div class="col-md-5">
            <label class="form-label" for="fc-card-set">Set</label>
            <select class="form-select" id="fc-card-set" name="set_id" required>
                <option value="">Bitte wählen</option>
                <?php foreach ($sets as $set): ?>
                    <option value="<?= (int) ($set['id'] ?? 0) ?>" <?= (int) ($set['id'] ?? 0) === (int) ($card['set_id'] ?? 0) ? 'selected' : '' ?>><?= $e($set['name'] ?? '') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="fc-card-number">Kartennummer</label>
            <input class="form-control" id="fc-card-number" name="card_number" value="<?= $e($card['card_number'] ?? '') ?>" placeholder="001">
        </div>
        <div class="col-md-4">
            <label class="form-label" for="fc-card-rarity">Seltenheit</label>
            <select class="form-select" id="fc-card-rarity" name="rarity">
                <?php foreach ($rarities as $key => $rarity): ?>
                    <option value="<?= $e($key) ?>" <?= (string) ($card['rarity'] ?? 'common') === (string) $key ? 'selected' : '' ?>><?= $e($rarity['label'] ?? $key) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="fc-card-status">Status</label>
            <select class="form-select" id="fc-card-status" name="status">
                <?php foreach (['draft' => 'Draft', 'active' => 'Aktiv', 'retired' => 'Archiviert'] as $value => $label): ?>
                    <option value="<?= $e($value) ?>" <?= (string) ($card['status'] ?? 'active') === $value ? 'selected' : '' ?>><?= $e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="fc-card-name">Name</label>
            <input class="form-control" id="fc-card-name" name="name" value="<?= $e($card['name'] ?? '') ?>" required>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="fc-card-slug">Slug</label>
            <input class="form-control" id="fc-card-slug" name="slug" value="<?= $e($card['slug'] ?? '') ?>" placeholder="wird bei Bedarf aus Name erzeugt">
        </div>
        <div class="col-12">
            <label class="form-label" for="fc-card-description">Beschreibung</label>
            <textarea class="form-control" id="fc-card-description" name="description" rows="4"><?= $e($card['description'] ?? '') ?></textarea>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="fc-card-faction">Fraktion</label>
            <input class="form-control" id="fc-card-faction" name="faction" value="<?= $e($card['faction'] ?? '') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="fc-card-element">Element</label>
            <input class="form-control" id="fc-card-element" name="element_name" value="<?= $e($card['element_name'] ?? '') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="fc-card-image">Bildpfad</label>
            <input class="form-control" id="fc-card-image" name="image_path" value="<?= $e($card['image_path'] ?? '') ?>" placeholder="/assets/...">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="fc-card-thumb">Thumbnail-Pfad</label>
            <input class="form-control" id="fc-card-thumb" name="thumbnail_path" value="<?= $e($card['thumbnail_path'] ?? '') ?>" placeholder="/assets/...">
        </div>
        <?php if ($previewImage !== ''): ?>
            <div class="col-12">
                <div class="fantasycards-admin-form-preview">
                    <div class="fantasycards-admin-thumb">
                        <?php if ($fullImage !== ''): ?>
                            <button type="button" class="fantasycards-image-button" data-fantasycards-lightbox data-full-image="<?= $e($fullImage) ?>" data-title="<?= $e($cardTitle) ?>" data-caption="Admin-Vorschau">
                                <img src="<?= $e($previewImage) ?>" alt="<?= $e($cardTitle) ?>" loading="lazy">
                            </button>
                        <?php else: ?>
                            <img src="<?= $e($previewImage) ?>" alt="<?= $e($cardTitle) ?>" loading="lazy">
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="fw-semibold">Bildvorschau</div>
                        <div class="small text-body-secondary">Thumbnail in der Verwaltung, großes Bild in der Lightbox.</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <div class="col-md-4">
            <label class="form-label" for="fc-card-sort">Sortierung</label>
            <input class="form-control" id="fc-card-sort" type="number" name="sort_order" value="<?= (int) ($card['sort_order'] ?? 0) ?>">
        </div>
        <div class="col-12 d-flex flex-wrap gap-3">
            <label class="form-check">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" <?= (int) ($card['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                <span class="form-check-label">Aktiv</span>
            </label>
            <label class="form-check">
                <input class="form-check-input" type="checkbox" name="available_in_boosters" value="1" <?= (int) ($card['available_in_boosters'] ?? 1) === 1 ? 'checked' : '' ?>>
                <span class="form-check-label">In Boostern verfügbar</span>
            </label>
        </div>
        <div class="col-12">
            <button class="btn btn-primary" type="submit">Karte speichern</button>
        </div>
    </div>
</form>

<script src="/assets/js/fantasycards-lightbox.js" defer></script>
