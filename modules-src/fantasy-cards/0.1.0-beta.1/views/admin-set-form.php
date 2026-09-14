<?php
declare(strict_types=1);

$set = is_array($set ?? null) ? $set : null;
$message = (string) ($message ?? '');
$error = (string) ($error ?? '');
$csrfToken = (string) ($csrf_token ?? '');
$e = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
$id = (int) ($set['id'] ?? 0);
?>

<?php require __DIR__ . '/admin-nav.php'; ?>

<div class="d-flex justify-content-between align-items-start gap-3 mb-4">
    <div>
        <p class="text-uppercase text-body-secondary small fw-semibold mb-1">Fantasy Cards Admin</p>
        <h1 class="h4 mb-1"><?= $id > 0 ? 'Set bearbeiten' : 'Set anlegen' ?></h1>
    </div>
    <a class="btn btn-outline-secondary" href="/admin/fantasy-cards">Zurück</a>
</div>

<?php if ($message !== ''): ?><div class="alert alert-success"><?= $e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>

<form class="app-card p-4" method="post" action="/admin/fantasy-cards/sets/save">
    <?= \Modulon\Core\View::csrfField($csrfToken) ?>
    <input type="hidden" name="set_id" value="<?= $id ?>">
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="fc-set-name">Name</label>
            <input class="form-control" id="fc-set-name" name="name" value="<?= $e($set['name'] ?? '') ?>" required>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="fc-set-slug">Slug</label>
            <input class="form-control" id="fc-set-slug" name="slug" value="<?= $e($set['slug'] ?? '') ?>" placeholder="wird bei Bedarf aus Name erzeugt">
        </div>
        <div class="col-12">
            <label class="form-label" for="fc-set-description">Beschreibung</label>
            <textarea class="form-control" id="fc-set-description" name="description" rows="4"><?= $e($set['description'] ?? '') ?></textarea>
        </div>
        <div class="col-md-8">
            <label class="form-label" for="fc-set-cover">Coverbild-Pfad</label>
            <input class="form-control" id="fc-set-cover" name="cover_image" value="<?= $e($set['cover_image'] ?? '') ?>" placeholder="/assets/...">
        </div>
        <div class="col-md-4">
            <label class="form-label" for="fc-set-sort">Sortierung</label>
            <input class="form-control" id="fc-set-sort" type="number" name="sort_order" value="<?= (int) ($set['sort_order'] ?? 0) ?>">
        </div>
        <div class="col-12 d-flex flex-wrap gap-3">
            <label class="form-check">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" <?= (int) ($set['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                <span class="form-check-label">Aktiv</span>
            </label>
            <label class="form-check">
                <input class="form-check-input" type="checkbox" name="available_in_free_packs" value="1" <?= (int) ($set['available_in_free_packs'] ?? 1) === 1 ? 'checked' : '' ?>>
                <span class="form-check-label">Für Free Packs verfügbar</span>
            </label>
        </div>
        <div class="col-12">
            <button class="btn btn-primary" type="submit">Set speichern</button>
        </div>
    </div>
</form>
