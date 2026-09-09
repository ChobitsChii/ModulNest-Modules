<?php

declare(strict_types=1);

namespace ModulNest\SneakPreview;

use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Session;
use Modulon\Core\View;
use Modulon\Modules\Auth\AuthService;

final class SneakPreviewController
{
    public function __construct(
        private readonly SneakPreviewRepository $repository,
        private readonly SneakPreviewTmdbService $tmdb,
        private readonly Session $session,
        private readonly ?AuthService $auth = null,
        private readonly string $basePath = '',
    ) {
    }

    public function index(Request $request): Response
    {
        return new Response(View::render('@modulnest.sneak-preview/index', [
            'title' => 'Sneak Preview',
            'current_path' => $request->path(),
            'movies' => $this->repository->allMovies(),
            'fields' => $this->repository->displayFields(),
        ]));
    }

    public function servePoster(Request $request): Response
    {
        $prefix = '/sneak-preview/posters/';
        $path = $request->path();
        $fileName = str_starts_with($path, $prefix) ? basename(substr($path, strlen($prefix))) : '';
        if (preg_match('/^tmdb_[0-9]+\.(jpg|jpeg|png|webp)$/D', $fileName) !== 1) {
            return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        $file = rtrim($this->basePath, '/') . '/storage/modules/modulnest.sneak-preview/posters/' . $fileName;
        if (!is_file($file) || !is_readable($file)) {
            return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        $content = file_get_contents($file);
        if (!is_string($content)) return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $type = match ($extension) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
        return new Response($content, 200, ['Content-Type' => $type, 'Cache-Control' => 'public, max-age=86400']);
    }

    public function adminIndex(Request $request): Response
    {
        return new Response(View::render('@modulnest.sneak-preview/admin', $this->adminViewData($request, [
            'movies' => $this->repository->allMovies(),
            'fields' => $this->repository->displayFields(),
            'message' => $this->session->pullFlash('sneak_preview_info'),
            'error' => $this->session->pullFlash('sneak_preview_error'),
        ])));
    }

    public function adminSubRoute(Request $request): Response
    {
        $path = trim($request->path(), '/');
        if ($path === 'admin/sneak-preview/new') {
            return $this->form($request, null);
        }
        if (preg_match('~^admin/sneak-preview/([0-9]+)/edit$~', $path, $matches) === 1) {
            return $this->form($request, (int) $matches[1]);
        }
        if ($path === 'admin/sneak-preview/settings') {
            return $this->settings($request);
        }
        if ($path === 'admin/sneak-preview/tmdb') {
            return $this->tmdb($request);
        }

        return new Response(View::render('errors/404', [
            'title' => '404 Not Found',
            'current_path' => $request->path(),
        ]), 404);
    }

    public function save(Request $request): Response
    {
        $movies = $request->inputRaw('movies', []);
        if (!is_array($movies)) {
            $movies = [];
        }

        $saved = 0;
        $adminId = $this->currentUserId();
        foreach ($movies as $movie) {
            if (!is_array($movie)) {
                continue;
            }
            $normalized = $this->normalizeMovieInput($movie);
            if ($normalized['title'] === '' || $normalized['sneak_date'] === '') {
                continue;
            }

            if (!empty($movie['save_poster_local']) && $this->repository->savePostersLocally()) {
                $tmdbId = is_numeric($normalized['tmdb_id']) ? (int) $normalized['tmdb_id'] : null;
                $poster = $this->tmdb->downloadPoster($normalized['poster_tmdb_path'], $tmdbId);
                if ($poster !== null) {
                    $normalized['poster_path'] = $poster;
                }
            }

            $this->repository->saveMovie($normalized, $adminId);
            $saved++;
        }

        $this->session->flash(
            $saved > 0 ? 'sneak_preview_info' : 'sneak_preview_error',
            $saved > 0 ? $saved . ' Eintrag/Einträge gespeichert.' : 'Es wurde kein gültiger Eintrag gespeichert.'
        );

        return Response::redirect('/admin/sneak-preview');
    }

    public function delete(Request $request): Response
    {
        $id = (int) $request->input('id', '0');
        $deleted = $id > 0 ? $this->repository->deleteMovie($id) : 0;
        $this->session->flash(
            $deleted > 0 ? 'sneak_preview_info' : 'sneak_preview_error',
            $deleted > 0 ? 'Eintrag gelöscht.' : 'Eintrag wurde nicht gefunden.'
        );

        return Response::redirect('/admin/sneak-preview');
    }

    public function saveSettings(Request $request): Response
    {
        $catalog = $this->repository->displayCatalog();
        $input = $request->inputRaw('fields', []);
        $fields = [];
        foreach ($catalog as $key => $_label) {
            $row = is_array($input[$key] ?? null) ? $input[$key] : [];
            $fields[$key] = [
                'table' => !empty($row['table']),
                'lightbox' => !empty($row['lightbox']),
                'admin' => !empty($row['admin']),
            ];
        }
        $this->repository->saveDisplayFields($fields);
        $this->repository->setSetting('save_posters_locally', $request->input('save_posters_locally', '') === '1' ? '1' : '0');

        $apiKey = trim((string) $request->input('tmdb_api_key', ''));
        if ($apiKey !== '') {
            $this->repository->setSetting('tmdb_api_key', $apiKey);
        }

        $this->session->flash('sneak_preview_info', 'Einstellungen gespeichert.');
        return Response::redirect('/admin/sneak-preview/settings');
    }

    private function form(Request $request, ?int $id): Response
    {
        $movie = $id !== null ? $this->repository->findMovie($id) : null;
        if ($id !== null && $movie === null) {
            $this->session->flash('sneak_preview_error', 'Eintrag wurde nicht gefunden.');
            return Response::redirect('/admin/sneak-preview');
        }

        return new Response(View::render('@modulnest.sneak-preview/form', $this->adminViewData($request, [
            'movie' => $movie,
            'locations' => $this->repository->locations(),
            'has_tmdb_api_key' => $this->repository->hasTmdbApiKey(),
        ])));
    }

    private function settings(Request $request): Response
    {
        return new Response(View::render('@modulnest.sneak-preview/settings', $this->adminViewData($request, [
            'fields' => $this->repository->displayFields(),
            'catalog' => $this->repository->displayCatalog(),
            'save_posters_locally' => $this->repository->savePostersLocally(),
            'has_tmdb_api_key' => $this->repository->hasTmdbApiKey(),
            'masked_tmdb_api_key' => $this->repository->maskedTmdbApiKey(),
            'message' => $this->session->pullFlash('sneak_preview_info'),
            'error' => $this->session->pullFlash('sneak_preview_error'),
        ])));
    }

    private function tmdb(Request $request): Response
    {
        $tmdbId = $request->query('tmdb_id');
        $payload = $tmdbId !== null && ctype_digit($tmdbId)
            ? $this->tmdb->details((int) $tmdbId)
            : $this->tmdb->search((string) $request->query('q', ''));

        return new Response(json_encode($payload, JSON_THROW_ON_ERROR), 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
    }

    /**
     * @param array<string, mixed> $movie
     * @return array<string, mixed>
     */
    private function normalizeMovieInput(array $movie): array
    {
        return [
            'id' => (int) ($movie['id'] ?? 0),
            'sneak_date' => trim((string) ($movie['sneak_date'] ?? '')),
            'title' => trim((string) ($movie['title'] ?? '')),
            'location' => trim((string) ($movie['location'] ?? '')),
            'release_date_de' => trim((string) ($movie['release_date_de'] ?? '')),
            'poster_path' => trim((string) ($movie['poster_path'] ?? '')),
            'poster_tmdb_path' => trim((string) ($movie['poster_tmdb_path'] ?? '')),
            'tmdb_id' => trim((string) ($movie['tmdb_id'] ?? '')),
            'overview' => trim((string) ($movie['overview'] ?? '')),
            'genres' => trim((string) ($movie['genres'] ?? '')),
            'runtime' => trim((string) ($movie['runtime'] ?? '')),
            'certification' => trim((string) ($movie['certification'] ?? '')),
            'original_language' => trim((string) ($movie['original_language'] ?? '')),
            'production_countries' => trim((string) ($movie['production_countries'] ?? '')),
            'vote_average' => trim((string) ($movie['vote_average'] ?? '')),
            'trailer_key' => trim((string) ($movie['trailer_key'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function adminViewData(Request $request, array $extra): array
    {
        return array_merge([
            'title' => 'Sneak Preview',
            'current_path' => $request->path(),
            'admin_section' => 'sneak-preview',
        ], $extra);
    }

    private function currentUserId(): int
    {
        $user = $this->auth?->currentUser();
        return is_array($user) ? (int) ($user['id'] ?? 0) : 0;
    }

}
