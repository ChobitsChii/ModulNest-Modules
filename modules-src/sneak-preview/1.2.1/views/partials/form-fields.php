<?php
declare(strict_types=1);

$idx = (int) ($idx ?? 0);
$initial = is_array($initial ?? null) ? $initial : [];
$e = $e ?? static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
$posterPreview = '';
if (!empty($initial['poster_path'])) {
    $posterPreview = str_starts_with((string) $initial['poster_path'], '/') ? (string) $initial['poster_path'] : '/' . ltrim((string) $initial['poster_path'], '/');
} elseif (!empty($initial['poster_tmdb_path'])) {
    $posterPreview = 'https://image.tmdb.org/t/p/w300/' . ltrim((string) $initial['poster_tmdb_path'], '/');
}
?>
<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label">Datum (Sneak)
            <input type="date" name="movies[<?= $idx ?>][sneak_date]" required class="form-control" value="<?= $e($initial['sneak_date'] ?? '') ?>">
        </label>
    </div>
    <div class="col-md-6">
        <label class="form-label">Titel
            <input type="text" name="movies[<?= $idx ?>][title]" required class="form-control js-title-field" value="<?= $e($initial['title'] ?? '') ?>">
        </label>
    </div>
    <div class="col-md-6">
        <label class="form-label">Ort
            <input list="sneak-preview-locations" name="movies[<?= $idx ?>][location]" class="form-control" value="<?= $e($initial['location'] ?? '') ?>">
        </label>
    </div>
    <div class="col-md-6">
        <label class="form-label">Kinostart (DE)
            <input type="date" name="movies[<?= $idx ?>][release_date_de]" class="form-control" value="<?= $e($initial['release_date_de'] ?? '') ?>">
        </label>
    </div>
    <div class="col-md-6">
        <label class="form-label">FSK
            <input type="text" name="movies[<?= $idx ?>][certification]" class="form-control" value="<?= $e($initial['certification'] ?? '') ?>">
        </label>
    </div>
    <div class="col-md-6">
        <label class="form-label">Laufzeit (min)
            <input type="number" name="movies[<?= $idx ?>][runtime]" class="form-control" value="<?= $e($initial['runtime'] ?? '') ?>">
        </label>
    </div>
    <div class="col-md-6">
        <label class="form-label">Genres
            <input type="text" name="movies[<?= $idx ?>][genres]" class="form-control" value="<?= $e($initial['genres'] ?? '') ?>">
        </label>
    </div>
    <div class="col-md-6">
        <label class="form-label">Bewertung (TMDB)
            <input type="text" name="movies[<?= $idx ?>][vote_average]" class="form-control" value="<?= $e($initial['vote_average'] ?? '') ?>">
        </label>
    </div>
    <div class="col-12">
        <label class="form-label">Beschreibung
            <textarea name="movies[<?= $idx ?>][overview]" rows="3" class="form-control"><?= $e($initial['overview'] ?? '') ?></textarea>
        </label>
    </div>
    <div class="col-md-6">
        <label class="form-label">Poster (TMDB-Pfad)
            <input type="text" name="movies[<?= $idx ?>][poster_tmdb_path]" class="form-control" value="<?= $e($initial['poster_tmdb_path'] ?? '') ?>">
        </label>
    </div>
    <div class="col-md-6">
        <label class="form-label">TMDB-ID
            <input type="text" name="movies[<?= $idx ?>][tmdb_id]" class="form-control" value="<?= $e($initial['tmdb_id'] ?? '') ?>">
        </label>
    </div>
    <div class="col-md-6">
        <label class="form-label">Originalsprache
            <input type="text" name="movies[<?= $idx ?>][original_language]" class="form-control" value="<?= $e($initial['original_language'] ?? '') ?>">
        </label>
    </div>
    <div class="col-md-6">
        <label class="form-label">Produktionsländer
            <input type="text" name="movies[<?= $idx ?>][production_countries]" class="form-control" value="<?= $e($initial['production_countries'] ?? '') ?>">
        </label>
    </div>
    <div class="col-md-6">
        <label class="form-label">Trailer-Key
            <input type="text" name="movies[<?= $idx ?>][trailer_key]" class="form-control" value="<?= $e($initial['trailer_key'] ?? '') ?>">
        </label>
    </div>
    <div class="col-md-6 d-flex align-items-end justify-content-between gap-3">
        <label class="form-check mb-2">
            <input type="checkbox" name="movies[<?= $idx ?>][save_poster_local]" value="1" class="form-check-input" checked>
            <span class="form-check-label">Poster lokal speichern</span>
        </label>
        <img id="posterPreview-<?= $idx ?>" class="sneak-preview-poster-preview" src="<?= $e($posterPreview) ?>" <?= $posterPreview === '' ? 'style="display:none"' : '' ?> alt="Poster">
    </div>
    <?php if (!empty($initial['poster_path'])): ?>
        <input type="hidden" name="movies[<?= $idx ?>][poster_path]" value="<?= $e($initial['poster_path']) ?>">
    <?php endif; ?>
    <?php if (!empty($initial['id'])): ?>
        <input type="hidden" name="movies[<?= $idx ?>][id]" value="<?= (int) $initial['id'] ?>">
    <?php endif; ?>
</div>
