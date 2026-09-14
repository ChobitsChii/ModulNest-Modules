<?php
declare(strict_types=1);

$sets = is_array($sets ?? null) ? $sets : [];
$message = (string) ($message ?? '');
$error = (string) ($error ?? '');
$csrfToken = (string) ($csrf_token ?? '');
$e = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
?>

<?php require __DIR__ . '/admin-nav.php'; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <p class="text-uppercase text-body-secondary small fw-semibold mb-1">Fantasy Cards Admin</p>
        <h1 class="h4 mb-1">Massen-Upload</h1>
        <p class="text-body-secondary mb-0">Mehrere Kartenbilder oder eine ZIP-Datei hochladen. Neue Karten starten als Draft.</p>
    </div>
    <a class="btn btn-outline-secondary" href="/admin/fantasy-cards/cards">Zur Kartenliste</a>
</div>

<?php if ($message !== ''): ?><div class="alert alert-success"><?= $e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>

<section class="app-card p-4 mb-4">
    <form id="fantasycards-upload-form" data-upload-url="/admin/fantasy-cards/upload">
        <?= \Modulon\Core\View::csrfField($csrfToken) ?>
        <div class="row g-3">
            <div class="col-md-5">
                <label class="form-label" for="fantasycards-upload-set">Set</label>
                <select class="form-select" id="fantasycards-upload-set" name="set_id" required>
                    <option value="">Bitte Set auswählen</option>
                    <?php foreach ($sets as $set): ?>
                        <option value="<?= (int) ($set['id'] ?? 0) ?>"><?= $e($set['name'] ?? '') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-7">
                <label class="form-label" for="fantasycards-upload-files">Dateien</label>
                <input class="form-control" id="fantasycards-upload-files" name="cards[]" type="file" multiple accept=".jpg,.jpeg,.png,.webp,.zip,image/jpeg,image/png,image/webp,application/zip">
                <div class="form-text">JPG, PNG, WebP oder ZIP. Pro Bild maximal 15 MB, ZIP maximal 100 MB.</div>
            </div>
        </div>

        <div id="fantasycards-dropzone" class="fantasycards-dropzone mt-4">
            <div class="h5 mb-1">Dateien hier ablegen</div>
            <div class="text-body-secondary">oder über die Dateiauswahl mehrere Kartenbilder wählen.</div>
        </div>

        <div class="d-flex flex-wrap align-items-center gap-3 mt-4">
            <button class="btn btn-primary" type="submit">Upload starten</button>
            <div id="fantasycards-upload-summary" class="small text-body-secondary"></div>
        </div>
        <div class="progress mt-3 fantasycards-upload-progress" role="progressbar" aria-label="Upload-Fortschritt">
            <div id="fantasycards-upload-bar" class="progress-bar" style="width:0%">0%</div>
        </div>
    </form>
</section>

<section class="app-card p-4">
    <h2 class="h5 mb-3">Upload-Ergebnis</h2>
    <div id="fantasycards-upload-results" class="fantasycards-upload-results text-body-secondary">Noch kein Upload ausgeführt.</div>
</section>

<script src="/assets/js/fantasycards-lightbox.js" defer></script>
<script src="/assets/js/fantasycards-admin.js"></script>
