<?php

declare(strict_types=1);

namespace ModulNest\News;

use DateTimeImmutable;
use Modulon\Core\MarkdownRenderer;
use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Session;
use Modulon\Core\View;
use Modulon\Modules\Auth\AuthService;

final class NewsController
{
    /**
     * @var array<int, string>
     */
    private array $allowedTypes = ['news', 'update', 'release', 'note'];
    /**
     * @var array<int, string>
     */
    private array $allowedStatuses = ['draft', 'published'];

    public function __construct(
        private readonly NewsRepository $news,
        private readonly Session $session,
        private readonly ?AuthService $auth = null,
        private readonly ?MarkdownRenderer $markdown = null,
    ) {
    }

    public function index(Request $request): Response
    {
        $viewMode = strtolower((string) $request->query('view', 'compact'));
        if (!in_array($viewMode, ['compact', 'expanded'], true)) {
            $viewMode = 'compact';
        }

        return new Response(View::render('@modulnest.news/index', $this->viewData($request, [
            'title' => 'News',
            'entries' => $this->renderedEntries($this->news->listPublished()),
            'view_mode' => $viewMode,
        ])));
    }

    public function subRoute(Request $request): Response
    {
        $path = trim($request->path(), '/');
        if ($path === 'news') {
            return $this->index($request);
        }

        if (preg_match('/^news\/([a-z0-9][a-z0-9\-]*)$/', $path, $matches) !== 1) {
            return new Response(View::render('errors/404', $this->viewData($request, ['title' => '404 Not Found'])), 404);
        }

        $entry = $this->news->findPublishedBySlug($matches[1]);
        if ($entry === null) {
            return new Response(View::render('errors/404', $this->viewData($request, ['title' => '404 Not Found'])), 404);
        }

        return new Response(View::render('@modulnest.news/show', $this->viewData($request, [
            'title' => (string) ($entry['title'] ?? 'News'),
            'entry' => $this->renderedEntry($entry),
        ])));
    }

    public function adminIndex(Request $request): Response
    {
        return new Response(View::render('@modulnest.news/admin', $this->viewData($request, [
            'title' => 'Admin News',
            'admin_section' => 'news',
            'message' => $this->session->pullFlash('news_info'),
            'error' => $this->session->pullFlash('news_error'),
            'entries' => $this->news->listAllForAdmin(),
        ])));
    }

    public function adminSubRoute(Request $request): Response
    {
        $path = trim($request->path(), '/');
        if ($path === 'admin/news/create') {
            return $this->renderForm($request, null);
        }

        if (preg_match('/^admin\/news\/([0-9]+)\/edit$/', $path, $matches) === 1) {
            return $this->renderForm($request, (int) $matches[1]);
        }

        return new Response(View::render('errors/404', $this->viewData($request, ['title' => '404 Not Found'])), 404);
    }

    public function create(Request $request): Response
    {
        $payload = $this->normalizePayload($request);
        if ($payload['error'] !== null) {
            $this->session->flash('news_error', $payload['error']);
            return Response::redirect('/admin/news/create');
        }

        if ($this->news->slugExists((string) $payload['data']['slug'])) {
            $this->session->flash('news_error', 'Slug ist bereits vergeben.');
            return Response::redirect('/admin/news/create');
        }

        $userId = (int) (($this->auth?->currentUser()['id'] ?? 0) ?: 0);
        $payload['data']['created_by'] = $userId > 0 ? $userId : null;
        $payload['data']['updated_by'] = $userId > 0 ? $userId : null;

        $entryId = $this->news->create($payload['data']);
        $this->session->flash('news_info', 'News-Eintrag erstellt.');

        return Response::redirect('/admin/news/' . $entryId . '/edit');
    }

    public function update(Request $request): Response
    {
        $entryId = (int) $request->input('entry_id', '0');
        if ($entryId <= 0) {
            $this->session->flash('news_error', 'Ungültige Eintrags-ID.');
            return Response::redirect('/admin/news');
        }

        if ($this->news->findById($entryId) === null) {
            $this->session->flash('news_error', 'Eintrag nicht gefunden.');
            return Response::redirect('/admin/news');
        }

        $payload = $this->normalizePayload($request);
        if ($payload['error'] !== null) {
            $this->session->flash('news_error', $payload['error']);
            return Response::redirect('/admin/news/' . $entryId . '/edit');
        }

        if ($this->news->slugExists((string) $payload['data']['slug'], $entryId)) {
            $this->session->flash('news_error', 'Slug ist bereits vergeben.');
            return Response::redirect('/admin/news/' . $entryId . '/edit');
        }

        $userId = (int) (($this->auth?->currentUser()['id'] ?? 0) ?: 0);
        $payload['data']['updated_by'] = $userId > 0 ? $userId : null;
        $this->news->update($entryId, $payload['data']);
        $this->session->flash('news_info', 'News-Eintrag gespeichert.');

        return Response::redirect('/admin/news/' . $entryId . '/edit');
    }

    public function delete(Request $request): Response
    {
        $entryId = (int) $request->input('entry_id', '0');
        if ($entryId <= 0) {
            $this->session->flash('news_error', 'Ungültige Eintrags-ID.');
            return Response::redirect('/admin/news');
        }

        $this->news->delete($entryId);
        $this->session->flash('news_info', 'News-Eintrag gelöscht.');

        return Response::redirect('/admin/news');
    }

    private function renderForm(Request $request, ?int $entryId): Response
    {
        $entry = $entryId === null ? null : $this->news->findById($entryId);
        if ($entryId !== null && $entry === null) {
            return new Response(View::render('errors/404', $this->viewData($request, ['title' => '404 Not Found'])), 404);
        }

        return new Response(View::render('@modulnest.news/admin-form', $this->viewData($request, [
            'title' => $entryId === null ? 'News erstellen' : 'News bearbeiten',
            'admin_section' => 'news',
            'message' => $this->session->pullFlash('news_info'),
            'error' => $this->session->pullFlash('news_error'),
            'entry' => $entry,
            'types' => $this->allowedTypes,
            'statuses' => $this->allowedStatuses,
        ])));
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    private function renderedEntries(array $entries): array
    {
        foreach ($entries as &$entry) {
            if (is_array($entry)) {
                $entry = $this->renderedEntry($entry);
            }
        }
        unset($entry);

        return $entries;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function renderedEntry(array $entry): array
    {
        $entry['content_html'] = ($this->markdown ?? new MarkdownRenderer())->render((string) ($entry['content'] ?? ''));

        return $entry;
    }

    /**
     * @return array{data: array<string, mixed>, error: ?string}
     */
    private function normalizePayload(Request $request): array
    {
        $title = trim((string) $request->input('title', ''));
        $slug = trim((string) $request->input('slug', ''));
        $excerpt = trim((string) $request->input('excerpt', ''));
        $content = trim((string) $request->input('content', ''));
        $type = strtolower(trim((string) $request->input('type', 'news')));
        $version = trim((string) $request->input('version', ''));
        $status = strtolower(trim((string) $request->input('status', 'draft')));
        $publishedAtRaw = trim((string) $request->input('published_at', ''));

        if ($title === '') {
            return ['data' => [], 'error' => 'Titel ist erforderlich.'];
        }

        if ($excerpt === '') {
            return ['data' => [], 'error' => 'Kurzbeschreibung ist erforderlich.'];
        }

        if ($content === '') {
            return ['data' => [], 'error' => 'Inhalt ist erforderlich.'];
        }

        if (!in_array($type, $this->allowedTypes, true)) {
            $type = 'news';
        }

        if (!in_array($status, $this->allowedStatuses, true)) {
            $status = 'draft';
        }

        if ($slug === '') {
            $slug = $this->slugify($title);
        } else {
            $slug = $this->slugify($slug);
        }

        if ($slug === '') {
            return ['data' => [], 'error' => 'Slug ist ungültig.'];
        }

        $publishedAt = null;
        if ($status === 'published') {
            if ($publishedAtRaw === '') {
                $publishedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');
            } else {
                try {
                    $publishedAt = (new DateTimeImmutable($publishedAtRaw))->format('Y-m-d H:i:s');
                } catch (\Exception) {
                    return ['data' => [], 'error' => 'Ungültiges Veröffentlichungsdatum.'];
                }
            }
        } elseif ($publishedAtRaw !== '') {
            try {
                $publishedAt = (new DateTimeImmutable($publishedAtRaw))->format('Y-m-d H:i:s');
            } catch (\Exception) {
                return ['data' => [], 'error' => 'Ungültiges Veröffentlichungsdatum.'];
            }
        }

        return [
            'data' => [
                'title' => mb_substr($title, 0, 180),
                'slug' => mb_substr($slug, 0, 180),
                'excerpt' => mb_substr($excerpt, 0, 400),
                'content' => $content,
                'type' => $type,
                'version' => $version !== '' ? mb_substr($version, 0, 30) : null,
                'status' => $status,
                'published_at' => $publishedAt,
            ],
            'error' => null,
        ];
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = trim($value, '-');

        return $value;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function viewData(Request $request, array $extra = []): array
    {
        $user = $this->auth?->currentUser();

        return array_merge([
            'current_path' => $request->path(),
            'auth' => [
                'is_authenticated' => $user !== null,
                'is_admin' => $this->auth?->isAdmin() ?? false,
                'user_name' => (string) ($user['name'] ?? ''),
            ],
        ], $extra);
    }
}
