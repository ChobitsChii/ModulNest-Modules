<?php

declare(strict_types=1);

namespace ModulNest\SneakPreview;

final class SneakPreviewTmdbService
{
    public function __construct(
        private readonly SneakPreviewRepository $repository,
        private readonly string $basePath,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function search(string $query): array
    {
        $apiKey = $this->apiKey();
        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'TMDB API-Key fehlt. Bitte in den Sneak-Preview-Einstellungen hinterlegen.'];
        }

        $query = trim($query);
        if ($query === '') {
            return ['ok' => false, 'error' => 'Kein Suchbegriff.'];
        }

        $url = 'https://api.themoviedb.org/3/search/movie?query=' . rawurlencode($query)
            . '&language=de-DE&include_adult=false&region=DE&api_key=' . rawurlencode($apiKey);
        $result = $this->httpJson($url);

        if (!is_array($result) || empty($result['results'])) {
            $fallbackUrl = 'https://api.themoviedb.org/3/search/movie?query=' . rawurlencode($query)
                . '&language=en-US&include_adult=false&api_key=' . rawurlencode($apiKey);
            $result = $this->httpJson($fallbackUrl);
        }

        if (!is_array($result) || empty($result['results']) || !is_array($result['results'])) {
            return ['ok' => false, 'error' => 'Keine Treffer gefunden.'];
        }

        $movies = [];
        foreach ($result['results'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $releaseDate = (string) ($item['release_date'] ?? '');
            $year = $releaseDate !== '' ? ' (' . substr($releaseDate, 0, 4) . ')' : '';
            $movies[] = [
                'id' => (int) ($item['id'] ?? 0),
                'title' => (string) ($item['title'] ?? $item['original_title'] ?? 'Unbekannt') . $year,
                'release_date' => $releaseDate !== '' ? $releaseDate : 'Unbekannt',
                'overview' => mb_substr((string) ($item['overview'] ?? ''), 0, 120),
                'original_title' => (string) ($item['original_title'] ?? ''),
            ];
        }

        return [
            'ok' => true,
            'multiple' => count($movies) > 1,
            'results' => $movies,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function details(int $tmdbId): array
    {
        $apiKey = $this->apiKey();
        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'TMDB API-Key fehlt. Bitte in den Sneak-Preview-Einstellungen hinterlegen.'];
        }
        if ($tmdbId <= 0) {
            return ['ok' => false, 'error' => 'Ungültige TMDB-ID.'];
        }

        $url = 'https://api.themoviedb.org/3/movie/' . $tmdbId
            . '?language=de-DE&append_to_response=release_dates,videos&api_key=' . rawurlencode($apiKey);
        $detail = $this->httpJson($url);

        if (!is_array($detail) || empty($detail['title']) || str_contains((string) ($detail['title'] ?? ''), 'Translation missing')) {
            $fallbackUrl = 'https://api.themoviedb.org/3/movie/' . $tmdbId
                . '?language=en-US&append_to_response=release_dates,videos&api_key=' . rawurlencode($apiKey);
            $detail = $this->httpJson($fallbackUrl);
        }

        if (!is_array($detail)) {
            return ['ok' => false, 'error' => 'Details nicht gefunden.'];
        }

        [$releaseDateDe, $certification] = $this->extractGermanRelease($detail);
        $genres = [];
        foreach (($detail['genres'] ?? []) as $genre) {
            if (is_array($genre) && isset($genre['name'])) {
                $genres[] = (string) $genre['name'];
            }
        }
        $countries = [];
        foreach (($detail['production_countries'] ?? []) as $country) {
            if (is_array($country) && isset($country['iso_3166_1'])) {
                $countries[] = (string) $country['iso_3166_1'];
            }
        }

        $posterTmdbPath = is_string($detail['poster_path'] ?? null) ? (string) $detail['poster_path'] : null;

        return [
            'ok' => true,
            'tmdb_id' => $tmdbId,
            'title' => (string) ($detail['title'] ?? $detail['original_title'] ?? 'Unbekannt'),
            'release_date_de' => $releaseDateDe,
            'certification' => $certification,
            'genres' => implode(', ', $genres),
            'runtime' => isset($detail['runtime']) ? (int) $detail['runtime'] : null,
            'vote_average' => isset($detail['vote_average']) ? round((float) $detail['vote_average'], 1) : null,
            'overview' => (string) ($detail['overview'] ?? ''),
            'poster_tmdb_path' => $posterTmdbPath,
            'poster_preview_url' => $posterTmdbPath ? self::posterUrl($posterTmdbPath, 'w300') : null,
            'trailer_key' => $this->extractTrailerKey($detail),
            'original_language' => (string) ($detail['original_language'] ?? ''),
            'production_countries' => implode(', ', $countries),
        ];
    }

    public function downloadPoster(?string $posterTmdbPath, ?int $tmdbId): ?string
    {
        if (!$posterTmdbPath || !$tmdbId || $tmdbId <= 0) {
            return null;
        }

        $source = self::posterUrl($posterTmdbPath, 'original');
        if ($source === null) {
            return null;
        }

        $extension = strtolower((string) pathinfo(parse_url($source, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $extension = 'jpg';
        }

        $directory = rtrim($this->basePath, '/') . '/storage/modules/modulnest.sneak-preview/posters';
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            error_log('Sneak Preview: poster directory could not be created: ' . $directory);
            return null;
        }
        if (!is_writable($directory)) {
            error_log('Sneak Preview: poster directory is not writable: ' . $directory);
            return null;
        }

        $relative = '/sneak-preview/posters/tmdb_' . $tmdbId . '.' . $extension;
        $absolute = rtrim($this->basePath, '/') . '/public' . $relative;
        $data = $this->httpBody($source);
        if ($data === null || $data === '') {
            return null;
        }

        $temporary = $absolute . '.tmp.' . bin2hex(random_bytes(6));
        if (@file_put_contents($temporary, $data, LOCK_EX) === false) {
            error_log('Sneak Preview: poster file could not be written: ' . $absolute);
            return null;
        }
        @chmod($temporary, 0664);
        if (!@rename($temporary, $absolute)) {
            @unlink($temporary);
            error_log('Sneak Preview: poster file could not be moved into place: ' . $absolute);
            return null;
        }

        return $relative;
    }

    public static function posterUrl(?string $tmdbPath, string $size = 'w500'): ?string
    {
        $tmdbPath = trim((string) ($tmdbPath ?? ''));
        if ($tmdbPath === '') {
            return null;
        }

        return 'https://image.tmdb.org/t/p/' . rawurlencode($size) . '/' . ltrim($tmdbPath, '/');
    }

    private function apiKey(): string
    {
        return trim((string) $this->repository->setting('tmdb_api_key'));
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function extractGermanRelease(array $detail): array
    {
        $results = $detail['release_dates']['results'] ?? [];
        if (!is_array($results)) {
            return [null, null];
        }

        foreach ($results as $releaseCountry) {
            if (!is_array($releaseCountry) || ($releaseCountry['iso_3166_1'] ?? '') !== 'DE') {
                continue;
            }

            $dates = is_array($releaseCountry['release_dates'] ?? null) ? $releaseCountry['release_dates'] : [];
            $pick = null;
            foreach ($dates as $release) {
                if (is_array($release) && (int) ($release['type'] ?? 0) === 3) {
                    $pick = $release;
                    break;
                }
            }
            if ($pick === null && isset($dates[0]) && is_array($dates[0])) {
                $pick = $dates[0];
            }
            if ($pick !== null) {
                $date = substr((string) ($pick['release_date'] ?? ''), 0, 10);
                return [$date !== '' ? $date : null, (string) ($pick['certification'] ?? '') ?: null];
            }
        }

        return [null, null];
    }

    private function extractTrailerKey(array $detail): ?string
    {
        $videos = $detail['videos']['results'] ?? [];
        if (!is_array($videos)) {
            return null;
        }

        foreach ($videos as $video) {
            if (!is_array($video)) {
                continue;
            }
            if (($video['site'] ?? '') === 'YouTube' && ($video['type'] ?? '') === 'Trailer') {
                return (string) ($video['key'] ?? '') ?: null;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function httpJson(string $url): ?array
    {
        $body = $this->httpBody($url);
        if ($body === null) {
            return null;
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function httpBody(string $url): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FAILONERROR => true,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);

        return is_string($body) ? $body : null;
    }
}
