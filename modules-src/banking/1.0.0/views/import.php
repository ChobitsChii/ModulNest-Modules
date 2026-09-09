<?php
declare(strict_types=1);

$csrfToken = (string) ($csrf_token ?? '');
$result = is_array($result ?? null) ? $result : null;
$maxUploadLabel = (string) ($max_upload_label ?? 'PHP-Upload-Limit');

$esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>

<?php require dirname(__DIR__) . '/partials/module-nav.php'; ?>

<?php if ($result !== null): ?>
    <section class="alert <?= (bool) ($result['success'] ?? false) ? 'alert-success' : 'alert-warning' ?>">
        <div class="fw-semibold mb-2">
            <?= (bool) ($result['success'] ?? false) ? 'Import abgeschlossen' : 'Import mit Hinweisen' ?>
        </div>
        <div class="d-flex flex-wrap gap-3 small">
            <span>Neu: <?= (int) ($result['imported'] ?? 0) ?></span>
            <span>Aktualisiert: <?= (int) ($result['updated'] ?? 0) ?></span>
            <span>Übersprungen: <?= (int) ($result['skipped'] ?? 0) ?></span>
            <span>Fehler: <?= (int) ($result['error_count'] ?? 0) ?></span>
            <?php if (($result['batch_id'] ?? null) !== null): ?>
                <span>Batch #<?= (int) $result['batch_id'] ?></span>
            <?php endif; ?>
        </div>
        <?php $errors = is_array($result['errors'] ?? null) ? $result['errors'] : []; ?>
        <?php if ($errors !== []): ?>
            <ul class="mb-0 mt-2">
                <?php foreach (array_slice($errors, 0, 8) as $error): ?>
                    <li><?= $esc($error) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-12 col-lg-8 col-xl-6">
        <section class="card shadow-sm border-0 app-card">
            <div class="card-body">
                <h1 class="h4 mb-4">CSV-Umsätze importieren</h1>
                <p class="text-body-secondary">
                    Unterstützt werden Sparkassen-Exporte mit Semikolon-Trennung und Anführungszeichen. Beim Import werden Duplikate automatisch erkannt.
                </p>
                <form method="post" action="/banking/import" enctype="multipart/form-data" class="row g-3">
                    <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                    <div class="col-12">
                        <label for="banking-csv-file" class="form-label">CSV-Datei auswählen</label>
                        <input id="banking-csv-file" type="file" name="csv_file" class="form-control" accept=".csv,text/csv" required>
                        <div class="form-text">
                            Bitte lade hier wie bisher den Sparkassen-CSV-Export hoch. Benötigt werden u. a. Auftragskonto, Buchungstag, Valutadatum, Buchungstext, Verwendungszweck, Begünstigter/Zahlungspflichtiger, Kontonummer/IBAN, Betrag, Währung und Info. Kategorie ist optional.
                        </div>
                    </div>
                    <div class="col-12 d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary">Import starten</button>
                    </div>
                </form>
                <div class="text-body-secondary small mt-3">
                    Maximale Dateigröße: <?= $esc($maxUploadLabel) ?>.
                </div>
            </div>
        </section>
    </div>
</div>
