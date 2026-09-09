<?php
declare(strict_types=1);

$movie = is_array($movie ?? null) ? $movie : null;
$locations = is_array($locations ?? null) ? $locations : [];
$csrfToken = (string) ($csrf_token ?? '');
$hasTmdbApiKey = (bool) ($has_tmdb_api_key ?? false);
$isEdit = $movie !== null;
$e = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
$initial = $movie ?? [
    'id' => '',
    'sneak_date' => '',
    'title' => '',
    'location' => '',
    'release_date_de' => '',
    'poster_path' => '',
    'poster_tmdb_path' => '',
    'tmdb_id' => '',
    'overview' => '',
    'genres' => '',
    'runtime' => '',
    'certification' => '',
    'original_language' => '',
    'production_countries' => '',
    'vote_average' => '',
    'trailer_key' => '',
];
?>
<link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">

<section class="app-card p-4 sneak-preview-admin-form">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h4 mb-1"><?= $isEdit ? 'Film bearbeiten' : 'Mehrere Filme hinzufügen' ?></h1>
            <p class="text-muted mb-0">Jeder Eintrag hat einen eigenen TMDB-Auto-Fill. Bei mehreren Treffern erscheint eine Auswahl.</p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-self-lg-start">
            <a class="btn btn-outline-secondary" href="/admin/sneak-preview">Zurück</a>
            <a class="btn btn-outline-secondary" href="/sneak-preview" target="_blank" rel="noopener">Öffentliche Seite</a>
        </div>
    </div>

    <?php if (!$hasTmdbApiKey): ?>
        <div class="alert alert-warning">TMDB Auto-Fill ist erst verfügbar, wenn in den Anzeige-Einstellungen ein API-Key hinterlegt ist.</div>
    <?php endif; ?>

    <?php if (!$isEdit): ?>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <button id="add1" class="btn btn-outline-secondary" type="button">+1 hinzufügen</button>
            <button id="add10" class="btn btn-outline-secondary" type="button">+10 hinzufügen</button>
            <span class="text-muted small">Für Masseneinträge können beliebig viele Formularblöcke ergänzt werden.</span>
        </div>
    <?php endif; ?>

    <form id="sneak-preview-batch-form" method="post" action="/admin/sneak-preview/save" autocomplete="off">
        <?= \Modulon\Core\View::csrfField($csrfToken) ?>
        <datalist id="sneak-preview-locations">
            <?php foreach ($locations as $location): ?><option value="<?= $e($location) ?>"></option><?php endforeach; ?>
        </datalist>

        <div id="sneak-preview-forms">
            <?php $idx = 0; ?>
            <div class="sneak-preview-movie-form app-card p-3 mb-3" data-index="<?= $idx ?>">
                <div class="d-flex justify-content-between gap-2 mb-3">
                    <h2 class="h6 mb-0">Film <?= $idx + 1 ?><?= $isEdit ? ' (ID ' . (int) ($initial['id'] ?? 0) . ')' : '' ?></h2>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary js-auto-tmdb">Auto aus TMDB</button>
                        <?php if (!$isEdit): ?><button type="button" class="btn btn-sm btn-outline-danger js-remove-form">Entfernen</button><?php endif; ?>
                    </div>
                </div>
                <?php require __DIR__ . '/partials/form-fields.php'; ?>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit">Speichern</button>
            <a class="btn btn-outline-secondary" href="/admin/sneak-preview">Abbrechen</a>
        </div>
    </form>
</section>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
<script>
let sneakPreviewFormIndex = 1;

function sneakPreviewEscape(value) {
    return String(value || '').replace(/[&<>"']/g, function (char) {
        return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char];
    });
}

function sneakPreviewRenderForm(idx, prefill) {
    prefill = prefill || {};
    const preview = prefill.poster_path ? prefill.poster_path : (prefill.poster_tmdb_path ? 'https://image.tmdb.org/t/p/w300/' + String(prefill.poster_tmdb_path).replace(/^\/+/, '') : '');
    const previewStyle = preview ? '' : 'display:none';
    return `
        <div class="sneak-preview-movie-form app-card p-3 mb-3" data-index="${idx}">
            <div class="d-flex justify-content-between gap-2 mb-3">
                <h2 class="h6 mb-0">Film ${idx + 1}</h2>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary js-auto-tmdb">Auto aus TMDB</button>
                    <button type="button" class="btn btn-sm btn-outline-danger js-remove-form">Entfernen</button>
                </div>
            </div>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Datum (Sneak)<input type="date" name="movies[${idx}][sneak_date]" required class="form-control" value="${sneakPreviewEscape(prefill.sneak_date)}"></label></div>
                <div class="col-md-6"><label class="form-label">Titel<input type="text" name="movies[${idx}][title]" required class="form-control js-title-field" value="${sneakPreviewEscape(prefill.title)}"></label></div>
                <div class="col-md-6"><label class="form-label">Ort<input list="sneak-preview-locations" name="movies[${idx}][location]" class="form-control" value="${sneakPreviewEscape(prefill.location)}"></label></div>
                <div class="col-md-6"><label class="form-label">Kinostart (DE)<input type="date" name="movies[${idx}][release_date_de]" class="form-control" value="${sneakPreviewEscape(prefill.release_date_de)}"></label></div>
                <div class="col-md-6"><label class="form-label">FSK<input type="text" name="movies[${idx}][certification]" class="form-control" value="${sneakPreviewEscape(prefill.certification)}"></label></div>
                <div class="col-md-6"><label class="form-label">Laufzeit (min)<input type="number" name="movies[${idx}][runtime]" class="form-control" value="${sneakPreviewEscape(prefill.runtime)}"></label></div>
                <div class="col-md-6"><label class="form-label">Genres<input type="text" name="movies[${idx}][genres]" class="form-control" value="${sneakPreviewEscape(prefill.genres)}"></label></div>
                <div class="col-md-6"><label class="form-label">Bewertung (TMDB)<input type="text" name="movies[${idx}][vote_average]" class="form-control" value="${sneakPreviewEscape(prefill.vote_average)}"></label></div>
                <div class="col-12"><label class="form-label">Beschreibung<textarea name="movies[${idx}][overview]" rows="3" class="form-control">${sneakPreviewEscape(prefill.overview)}</textarea></label></div>
                <div class="col-md-6"><label class="form-label">Poster (TMDB-Pfad)<input type="text" name="movies[${idx}][poster_tmdb_path]" class="form-control" value="${sneakPreviewEscape(prefill.poster_tmdb_path)}"></label></div>
                <div class="col-md-6"><label class="form-label">TMDB-ID<input type="text" name="movies[${idx}][tmdb_id]" class="form-control" value="${sneakPreviewEscape(prefill.tmdb_id)}"></label></div>
                <div class="col-md-6"><label class="form-label">Originalsprache<input type="text" name="movies[${idx}][original_language]" class="form-control" value="${sneakPreviewEscape(prefill.original_language)}"></label></div>
                <div class="col-md-6"><label class="form-label">Produktionsländer<input type="text" name="movies[${idx}][production_countries]" class="form-control" value="${sneakPreviewEscape(prefill.production_countries)}"></label></div>
                <div class="col-md-6"><label class="form-label">Trailer-Key<input type="text" name="movies[${idx}][trailer_key]" class="form-control" value="${sneakPreviewEscape(prefill.trailer_key)}"></label></div>
                <div class="col-md-6 d-flex align-items-end justify-content-between gap-3">
                    <label class="form-check mb-2"><input type="checkbox" name="movies[${idx}][save_poster_local]" value="1" class="form-check-input" checked> <span class="form-check-label">Poster lokal speichern</span></label>
                    <img id="posterPreview-${idx}" class="sneak-preview-poster-preview" src="${sneakPreviewEscape(preview)}" style="${previewStyle}" alt="Poster">
                </div>
            </div>
        </div>`;
}

function sneakPreviewAddForm(prefill) {
    const idx = sneakPreviewFormIndex++;
    const wrapper = document.createElement('div');
    wrapper.innerHTML = sneakPreviewRenderForm(idx, prefill || {});
    const node = wrapper.firstElementChild;
    document.getElementById('sneak-preview-forms').appendChild(node);
    node.scrollIntoView({behavior: 'smooth', block: 'center'});
}

function sneakPreviewSetField(wrapper, idx, name, value) {
    const field = wrapper.find(`[name="movies[${idx}][${name}]"]`);
    if (field.length && value !== undefined && value !== null) field.val(value);
}

function sneakPreviewLoadTmdb(wrapper, idx, tmdbId) {
    jQuery.getJSON('/admin/sneak-preview/tmdb', {tmdb_id: tmdbId}).done(function (res) {
        if (!res || !res.ok) {
            alert('Details nicht gefunden: ' + ((res && res.error) || 'Unbekannter Fehler'));
            return;
        }
        ['title','release_date_de','certification','genres','runtime','vote_average','overview','tmdb_id','poster_tmdb_path','original_language','production_countries','trailer_key'].forEach(function (field) {
            sneakPreviewSetField(wrapper, idx, field, res[field]);
        });
        if (res.poster_preview_url) wrapper.find(`#posterPreview-${idx}`).attr('src', res.poster_preview_url).show();
    }).fail(function () {
        alert('Fehler beim Laden der Details für TMDB-ID: ' + tmdbId);
    });
}

function sneakPreviewManualIdDialog(wrapper, idx) {
    const dialog = jQuery('<div>')
        .append('<p>Keine Treffer gefunden. Bitte gib die TMDB-ID manuell ein.</p>')
        .append('<input type="text" id="manualTmdbId" class="form-control" placeholder="z. B. 1001079">');
    dialog.dialog({
        title: 'Manuelle TMDB-ID',
        width: 420,
        modal: true,
        buttons: {
            'OK': function () {
                const tmdbId = String(jQuery('#manualTmdbId').val() || '').trim();
                if (!/^[0-9]+$/.test(tmdbId)) {
                    alert('Bitte eine gültige TMDB-ID eingeben.');
                    return;
                }
                sneakPreviewLoadTmdb(wrapper, idx, tmdbId);
                jQuery(this).dialog('close');
            },
            'Abbrechen': function () { jQuery(this).dialog('close'); }
        }
    });
}

document.getElementById('add1')?.addEventListener('click', function () { sneakPreviewAddForm({}); });
document.getElementById('add10')?.addEventListener('click', function () { for (let i = 0; i < 10; i++) sneakPreviewAddForm({}); });

jQuery(document).on('click', '.js-remove-form', function () {
    jQuery(this).closest('.sneak-preview-movie-form').remove();
});

jQuery(document).on('click', '.js-auto-tmdb', function () {
    const wrapper = jQuery(this).closest('.sneak-preview-movie-form');
    const idx = wrapper.data('index');
    const q = String(wrapper.find(`[name="movies[${idx}][title]"]`).val() || '').trim();
    if (!q) {
        alert('Bitte zuerst einen Titel eingeben.');
        return;
    }

    jQuery.getJSON('/admin/sneak-preview/tmdb', {q: q}).done(function (res) {
        if (!res || !res.ok) {
            if (confirm('Keine Treffer gefunden. Möchtest du die TMDB-ID manuell eingeben?')) sneakPreviewManualIdDialog(wrapper, idx);
            return;
        }
        if (!res.multiple && res.results && res.results[0]) {
            sneakPreviewLoadTmdb(wrapper, idx, res.results[0].id);
            return;
        }
        const dialog = jQuery('<div>').append('<p>Es gibt mehrere Treffer für "' + sneakPreviewEscape(q) + '":</p>').append('<ul class="sneak-preview-tmdb-results"></ul>');
        res.results.forEach(function (movie) {
            jQuery('<li>')
                .text(movie.title + ': ' + (movie.overview || ''))
                .on('click', function () {
                    sneakPreviewLoadTmdb(wrapper, idx, movie.id);
                    dialog.dialog('close');
                })
                .appendTo(dialog.find('ul'));
        });
        dialog.dialog({title: 'Film auswählen', width: 560, modal: true, buttons: {'Abbrechen': function () { jQuery(this).dialog('close'); }}});
    }).fail(function () {
        if (confirm('Fehler beim Abrufen der TMDB-Daten. Möchtest du die TMDB-ID manuell eingeben?')) sneakPreviewManualIdDialog(wrapper, idx);
    });
});
</script>
