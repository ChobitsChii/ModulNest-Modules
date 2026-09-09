<?php
declare(strict_types=1);

$fields = is_array($fields ?? null) ? $fields : [];
$catalog = is_array($catalog ?? null) ? $catalog : [];
$savePostersLocally = (bool) ($save_posters_locally ?? true);
$hasTmdbApiKey = (bool) ($has_tmdb_api_key ?? false);
$maskedTmdbApiKey = (string) ($masked_tmdb_api_key ?? '');
$csrfToken = (string) ($csrf_token ?? '');
$message = (string) ($message ?? '');
$error = (string) ($error ?? '');
$e = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
$areas = ['table' => 'Tabelle', 'lightbox' => 'Lightbox', 'admin' => 'Admin'];
?>
<section class="app-card p-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h4 mb-1">Sneak Preview / Anzeige-Einstellungen</h1>
            <p class="text-muted mb-0">Spalten je Ansicht steuern und TMDB-Anbindung konfigurieren.</p>
        </div>
        <a class="btn btn-outline-secondary align-self-lg-start" href="/admin/sneak-preview">Zurück</a>
    </div>

    <?php if ($message !== ''): ?><div class="alert alert-success"><?= $e($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>

    <form method="post" action="/admin/sneak-preview/settings">
        <?= \Modulon\Core\View::csrfField($csrfToken) ?>

        <div class="row g-3 mb-4">
            <div class="col-12">
                <label class="form-label">TMDB API-Key</label>
                <input type="password" class="form-control" name="tmdb_api_key" placeholder="<?= $hasTmdbApiKey ? 'Gespeicherter Key bleibt erhalten, wenn leer' : 'API-Key eintragen' ?>">
                <div class="form-text">
                    Der Key wird in der Modulon-Datenbank gespeichert und nicht in Dateien geschrieben.
                    <?php if ($maskedTmdbApiKey !== ''): ?>
                        Aktuell gespeichert: <code><?= $e($maskedTmdbApiKey) ?></code>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-12">
                <label class="form-check mb-0">
                    <input type="checkbox" class="form-check-input" name="save_posters_locally" value="1" <?= $savePostersLocally ? 'checked' : '' ?>>
                    <span class="form-check-label">Poster beim Speichern lokal ablegen</span>
                </label>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                <tr>
                    <th>Feld</th>
                    <?php foreach ($areas as $label): ?><th class="text-center"><?= $e($label) ?></th><?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($catalog as $key => $label): ?>
                    <tr>
                        <td><?= $e($label) ?></td>
                        <?php foreach ($areas as $area => $_label): ?>
                            <td class="text-center">
                                <input type="checkbox" class="form-check-input" name="fields[<?= $e($key) ?>][<?= $e($area) ?>]" value="1" <?= !empty($fields[$key][$area]) ? 'checked' : '' ?>>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <button class="btn btn-primary" type="submit">Speichern</button>
    </form>
</section>
