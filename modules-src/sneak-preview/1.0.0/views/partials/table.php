<?php
declare(strict_types=1);

$movies = is_array($movies ?? null) ? $movies : [];
$fields = is_array($fields ?? null) ? $fields : [];
$tableId = (string) ($table_id ?? 'sneak-preview-table');
$adminMode = (bool) ($admin_mode ?? false);
$csrfToken = (string) ($csrf_token ?? '');

$e = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
$visible = static fn (string $field, string $area): bool => !empty($fields[$field][$area]);
$daysOffset = static function (?string $sneak, ?string $release): ?int {
    if (!$sneak || !$release) {
        return null;
    }
    try {
        $sneakDate = new DateTimeImmutable($sneak);
        $releaseDate = new DateTimeImmutable($release);
    } catch (Throwable) {
        return null;
    }
    return (int) $sneakDate->diff($releaseDate)->format('%r%a');
};
$formatDate = static function (mixed $value): string {
    $date = trim((string) ($value ?? ''));
    if ($date === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($date))->format('d.m.Y');
    } catch (Throwable) {
        return $date;
    }
};
$posterUrl = static function (array $movie, string $size = 'w300'): string {
    $local = trim((string) ($movie['poster_path'] ?? ''));
    if ($local !== '') {
        return str_starts_with($local, '/') ? $local : '/' . ltrim($local, '/');
    }
    $tmdb = trim((string) ($movie['poster_tmdb_path'] ?? ''));
    return $tmdb !== '' ? 'https://image.tmdb.org/t/p/' . rawurlencode($size) . '/' . ltrim($tmdb, '/') : '';
};
$lightboxCaption = static function (array $movie) use ($fields, $e): string {
    $bits = [];
    if (!empty($fields['title']['lightbox'])) {
        $bits[] = '<strong>' . $e($movie['title'] ?? '') . '</strong>';
    }
    if (!empty($fields['genres']['lightbox']) && !empty($movie['genres'])) {
        $bits[] = 'Genres: ' . $e($movie['genres']);
    }
    if (!empty($fields['runtime']['lightbox']) && !empty($movie['runtime'])) {
        $bits[] = 'Laufzeit: ' . $e($movie['runtime']) . ' min';
    }
    if (!empty($fields['certification']['lightbox']) && !empty($movie['certification'])) {
        $bits[] = 'FSK: ' . $e($movie['certification']);
    }
    if (!empty($fields['vote_average']['lightbox']) && !empty($movie['vote_average'])) {
        $bits[] = 'Bewertung: ' . $e($movie['vote_average']);
    }
    if (!empty($fields['overview']['lightbox']) && !empty($movie['overview'])) {
        $bits[] = '<em>' . $e($movie['overview']) . '</em>';
    }
    return implode('<br>', $bits);
};
?>
<div class="table-responsive sneak-preview-table-shell">
    <table id="<?= $e($tableId) ?>" class="table table-striped table-hover align-middle sneak-preview-table">
        <thead>
        <tr>
            <?php if ($adminMode): ?><th>ID</th><?php endif; ?>
            <?php if ($visible('poster', $adminMode ? 'admin' : 'table')): ?><th>Poster</th><?php endif; ?>
            <?php if ($visible('sneak_date', $adminMode ? 'admin' : 'table')): ?><th>Datum</th><?php endif; ?>
            <?php if ($visible('title', $adminMode ? 'admin' : 'table')): ?><th>Titel</th><?php endif; ?>
            <?php if ($visible('location', $adminMode ? 'admin' : 'table')): ?><th>Ort</th><?php endif; ?>
            <?php if ($visible('release_date_de', $adminMode ? 'admin' : 'table')): ?><th>Kinostart (DE)</th><?php endif; ?>
            <?php if ($visible('days_offset', $adminMode ? 'admin' : 'table')): ?><th>Tage davor/danach</th><?php endif; ?>
            <?php if ($visible('certification', $adminMode ? 'admin' : 'table')): ?><th>FSK</th><?php endif; ?>
            <?php if ($visible('genres', $adminMode ? 'admin' : 'table')): ?><th>Genres</th><?php endif; ?>
            <?php if ($visible('runtime', $adminMode ? 'admin' : 'table')): ?><th>Laufzeit</th><?php endif; ?>
            <?php if ($visible('vote_average', $adminMode ? 'admin' : 'table')): ?><th>Bewertung</th><?php endif; ?>
            <?php if ($adminMode): ?><th>Aktionen</th><?php endif; ?>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($movies as $movie): ?>
            <?php
            $movie = is_array($movie) ? $movie : [];
            $days = $daysOffset((string) ($movie['sneak_date'] ?? ''), (string) ($movie['release_date_de'] ?? ''));
            $thumb = $posterUrl($movie, 'w300');
            $full = $posterUrl($movie, 'original');
            ?>
            <tr>
                <?php if ($adminMode): ?><td><?= (int) ($movie['id'] ?? 0) ?></td><?php endif; ?>
                <?php if ($visible('poster', $adminMode ? 'admin' : 'table')): ?>
                    <td class="sneak-preview-poster-cell">
                        <?php if ($thumb !== ''): ?>
                            <a href="<?= $e($full) ?>" class="sneak-preview-lightbox-link" data-lightbox="sneak-posters" data-title="<?= $e($lightboxCaption($movie)) ?>">
                                <img src="<?= $e($thumb) ?>" alt="Poster" class="sneak-preview-thumb">
                            </a>
                        <?php endif; ?>
                    </td>
                <?php endif; ?>
                <?php if ($visible('sneak_date', $adminMode ? 'admin' : 'table')): ?><td class="sneak-preview-date" data-order="<?= $e($movie['sneak_date'] ?? '') ?>"><?= $e($formatDate($movie['sneak_date'] ?? '')) ?></td><?php endif; ?>
                <?php if ($visible('title', $adminMode ? 'admin' : 'table')): ?><td><?= $e($movie['title'] ?? '') ?></td><?php endif; ?>
                <?php if ($visible('location', $adminMode ? 'admin' : 'table')): ?><td><?= $e($movie['location'] ?? '') ?></td><?php endif; ?>
                <?php if ($visible('release_date_de', $adminMode ? 'admin' : 'table')): ?><td class="sneak-preview-date" data-order="<?= $e($movie['release_date_de'] ?? '') ?>"><?= $e($formatDate($movie['release_date_de'] ?? '')) ?></td><?php endif; ?>
                <?php if ($visible('days_offset', $adminMode ? 'admin' : 'table')): ?><td><?= $days !== null ? $e($days) : '' ?></td><?php endif; ?>
                <?php if ($visible('certification', $adminMode ? 'admin' : 'table')): ?><td><?= $e($movie['certification'] ?? '') ?></td><?php endif; ?>
                <?php if ($visible('genres', $adminMode ? 'admin' : 'table')): ?><td><?= $e($movie['genres'] ?? '') ?></td><?php endif; ?>
                <?php if ($visible('runtime', $adminMode ? 'admin' : 'table')): ?><td><?= $e($movie['runtime'] ?? '') ?></td><?php endif; ?>
                <?php if ($visible('vote_average', $adminMode ? 'admin' : 'table')): ?><td><?= $e($movie['vote_average'] ?? '') ?></td><?php endif; ?>
                <?php if ($adminMode): ?>
                    <td class="sneak-preview-actions">
                        <a class="btn btn-sm btn-primary" href="/admin/sneak-preview/<?= (int) ($movie['id'] ?? 0) ?>/edit">Bearbeiten</a>
                        <form method="post" action="/admin/sneak-preview/delete" class="d-inline" onsubmit="return confirm('Wirklich löschen?');">
                            <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                            <input type="hidden" name="id" value="<?= (int) ($movie['id'] ?? 0) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Löschen</button>
                        </form>
                    </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<script>
if (!window.sneakPreviewLightboxLayoutBound) {
    window.sneakPreviewLightboxLayoutBound = true;
    document.addEventListener('click', function (event) {
        if (event.target.closest('.sneak-preview-lightbox-link')) {
            document.body.classList.add('sneak-preview-lightbox-active');
            return;
        }
        if (event.target.closest('.lb-close') || event.target.classList.contains('lightboxOverlay')) {
            window.setTimeout(function () {
                document.body.classList.remove('sneak-preview-lightbox-active');
            }, 100);
        }
    }, true);
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            window.setTimeout(function () {
                document.body.classList.remove('sneak-preview-lightbox-active');
            }, 100);
        }
    });
}
</script>
