<?php

declare(strict_types=1);

namespace ModulNest\Pages;

use Modulon\Core\MarkdownRenderer;
use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Session;
use Modulon\Core\View;
use Modulon\Modules\Auth\AuthService;

final class PagesController
{
    public function __construct(
        private readonly PagesRepository $pages,
        private readonly Session $session,
        private readonly ?AuthService $auth = null,
        private readonly ?MarkdownRenderer $markdown = null,
    ) {
    }

    public function index(Request $request): Response
    {
        $entries = $this->pages->listPublicByGroup('Rechtliches');

        return new Response(View::render('pages/index', $this->viewData($request, [
            'title' => 'Seiten',
            'entries' => $entries,
        ])));
    }

    public function subRoute(Request $request): Response
    {
        $path = trim($request->path(), '/');
        if ($path === 'pages') {
            return $this->index($request);
        }

        if ($path === 'pages/footer-links') {
            return $this->footerLinksJson();
        }

        if (preg_match('/^pages\/([a-z0-9][a-z0-9\-]*)$/', $path, $matches) !== 1) {
            return new Response(View::render('errors/404', $this->viewData($request, ['title' => '404 Not Found'])), 404);
        }

        $entry = $this->pages->findBySlug((string) $matches[1]);
        if ($entry === null || !$this->canView((string) ($entry['visibility'] ?? 'public'))) {
            return new Response(View::render('errors/404', $this->viewData($request, ['title' => '404 Not Found'])), 404);
        }

        return new Response(View::render('pages/show', $this->viewData($request, [
            'title' => (string) ($entry['title'] ?? 'Seite'),
            'entry' => $entry,
            'content_html' => ($this->markdown ?? new MarkdownRenderer())->render((string) ($entry['content_markdown'] ?? '')),
        ])));
    }

    public function adminIndex(Request $request): Response
    {
        return new Response(View::render('pages/admin', $this->viewData($request, [
            'title' => 'Pages',
            'entries' => $this->pages->listAll(),
            'menu_groups' => $this->pages->listMenuGroups(),
            'message' => $this->session->pullFlash('pages_info'),
            'error' => $this->session->pullFlash('pages_error'),
            'visibilities' => PagesRepository::VISIBILITIES,
        ])));
    }

    public function create(Request $request): Response
    {
        $payload = $this->normalizePayload($request);
        if ($payload['error'] !== null) {
            $this->session->flash('pages_error', $payload['error']);
            return Response::redirect('/admin/pages');
        }

        if ($this->pages->slugExists($payload['data']['slug'])) {
            $this->session->flash('pages_error', 'Slug ist bereits vergeben.');
            return Response::redirect('/admin/pages');
        }

        $this->pages->create($payload['data']);
        $this->session->flash('pages_info', 'Seite erstellt.');

        return Response::redirect('/admin/pages');
    }

    public function update(Request $request): Response
    {
        $entryId = (int) $request->input('entry_id', '0');
        if ($entryId <= 0) {
            $this->session->flash('pages_error', 'Ungültige Eintrags-ID.');
            return Response::redirect('/admin/pages');
        }

        $payload = $this->normalizePayload($request);
        if ($payload['error'] !== null) {
            $this->session->flash('pages_error', $payload['error']);
            return Response::redirect('/admin/pages');
        }

        if ($this->pages->slugExists($payload['data']['slug'], $entryId)) {
            $this->session->flash('pages_error', 'Slug ist bereits vergeben.');
            return Response::redirect('/admin/pages');
        }

        $this->pages->update($entryId, $payload['data']);
        $this->session->flash('pages_info', 'Seite gespeichert.');

        return Response::redirect('/admin/pages');
    }

    public function delete(Request $request): Response
    {
        $entryId = (int) $request->input('entry_id', '0');
        if ($entryId > 0) {
            $this->pages->delete($entryId);
            $this->session->flash('pages_info', 'Seite gelöscht.');
        }

        return Response::redirect('/admin/pages');
    }

    public function toggle(Request $request): Response
    {
        $entryId = (int) $request->input('entry_id', '0');
        $active = $request->input('active') === '1';
        if ($entryId > 0) {
            $this->pages->setActive($entryId, $active);
        }

        return $this->json([
            'success' => true,
            'entry_id' => $entryId,
            'active' => $active,
        ]);
    }

    public function move(Request $request): Response
    {
        $entryId = (int) $request->input('entry_id', '0');
        $direction = strtolower((string) $request->input('direction', ''));
        if ($entryId > 0 && in_array($direction, ['up', 'down'], true)) {
            $this->pages->move($entryId, $direction);
        }

        return $this->json([
            'success' => true,
            'entry_id' => $entryId,
            'direction' => $direction,
        ]);
    }

    /**
     * @return array{data: array{title:string,slug:string,content_markdown:string,visibility:string,menu_group:string,show_in_header:bool,show_in_footer:bool,is_active:bool}, error: ?string}
     */
    private function normalizePayload(Request $request): array
    {
        $title = trim((string) $request->input('title', ''));
        $slugInput = trim((string) $request->input('slug', ''));
        $content = trim((string) $request->input('content_markdown', ''));
        $visibility = strtolower(trim((string) $request->input('visibility', 'public')));
        $menuGroup = trim((string) $request->input('menu_group', ''));
        $showInHeader = $request->input('show_in_header') === '1';
        $showInFooter = $request->input('show_in_footer') === '1';
        $isActive = $request->input('is_active') === '1';

        if ($title === '') {
            return ['data' => [], 'error' => 'Titel ist erforderlich.'];
        }

        if ($content === '') {
            return ['data' => [], 'error' => 'Markdown-Inhalt ist erforderlich.'];
        }

        $slug = $this->slugify($slugInput !== '' ? $slugInput : $title);
        if ($slug === '') {
            return ['data' => [], 'error' => 'Slug ist ungültig.'];
        }

        if (!in_array($visibility, PagesRepository::VISIBILITIES, true)) {
            $visibility = 'public';
        }

        if (!$showInHeader) {
            $menuGroup = '';
        }

        return [
            'data' => [
                'title' => mb_substr($title, 0, 180),
                'slug' => mb_substr($slug, 0, 180),
                'content_markdown' => $content,
                'visibility' => $visibility,
                'menu_group' => mb_substr($menuGroup, 0, 120),
                'show_in_header' => $showInHeader,
                'show_in_footer' => $showInFooter,
                'is_active' => $isActive,
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

    private function canView(string $visibility): bool
    {
        if ($visibility === 'public') {
            return true;
        }

        $user = $this->auth?->currentUser();
        if ($user === null) {
            return false;
        }

        if ($visibility === 'user') {
            return true;
        }

        return $this->auth?->isAdmin() ?? false;
    }

    private function footerLinksJson(): Response
    {
        $links = [];
        foreach ($this->pages->listPublicFooterPages() as $row) {
            $slug = (string) ($row['slug'] ?? '');
            $title = (string) ($row['title'] ?? '');
            if ($slug === '' || $title === '') {
                continue;
            }
            $links[] = [
                'title' => $title,
                'url' => '/pages/' . $slug,
                'slug' => $slug,
            ];
        }

        return $this->json([
            'success' => true,
            'links' => $links,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status = 200): Response
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new Response(
            is_string($json) ? $json : '{}',
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8']
        );
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
