<?php

declare(strict_types=1);

namespace ModulNest\Homepage;

use Modulon\Core\Request;
use Modulon\Core\NativeModuleMigrationService;
use Modulon\Core\Response;
use Modulon\Core\Session;
use Modulon\Core\View;
use Modulon\Modules\Modules\ModuleRepository;
use Throwable;

final class HomepageController
{
    public function __construct(
        private readonly HomepageRepository $repository,
        private readonly Session $session,
        private readonly HomepageRenderer $renderer,
        private readonly ?ModuleRepository $modules = null,
        private readonly ?NativeModuleMigrationService $moduleMigrations = null,
    ) {
    }

    public function adminIndex(Request $request): Response
    {
        $error = $this->session->pullFlash('homepage_error');
        $isPublished = false;
        $blocks = [];
        $editBlock = null;
        $previewBlocks = [
            'all' => [],
            'guest' => [],
            'user' => [],
            'admin' => [],
        ];
        $previewMode = (string) ($request->query('preview', 'all') ?? 'all');
        if (!in_array($previewMode, ['all', 'guest', 'user', 'admin'], true)) {
            $previewMode = 'all';
        }

        try {
            if ($this->moduleMigrations !== null) {
                $this->moduleMigrations->runForRoutePrefix('homepage');
            }
            [$isPublished, $blocks, $editBlock, $previewBlocks, $error] = $this->adminState($request, $error);
        } catch (Throwable $throwable) {
            if ($this->moduleMigrations !== null && $this->looksLikeMissingHomepageSchema($throwable)) {
                try {
                    $this->moduleMigrations->runForRoutePrefix('homepage');
                    [$isPublished, $blocks, $editBlock, $previewBlocks, $error] = $this->adminState($request, $error);
                    $this->session->flash('homepage_info', 'Homepage-Datenbankstruktur wurde vorbereitet.');
                } catch (Throwable $migrationError) {
                    $error = trim($error . ' ' . 'Die Homepage-Datenbankstruktur ist noch nicht verfügbar. Bitte Modul-Migrationen ausführen. Details: ' . $migrationError->getMessage());
                }
            } else {
                $error = trim($error . ' ' . 'Die Homepage-Datenbankstruktur ist noch nicht verfügbar. Bitte Modul-Migrationen ausführen. Details: ' . $throwable->getMessage());
            }
        }

        return new Response(View::render('homepage/admin', [
            'title' => 'Startseite',
            'current_path' => $request->path(),
            'admin_section' => 'homepage',
            'message' => $this->session->pullFlash('homepage_info'),
            'error' => $error,
            'is_published' => $isPublished,
            'blocks' => $blocks,
            'edit_block' => $editBlock,
            'preview_blocks' => $previewBlocks,
            'preview_mode' => $previewMode,
        ]));
    }

    /**
     * @return array{0:bool,1:array<int,array<string,mixed>>,2:?array<string,mixed>,3:array<string,array<int,array<string,mixed>>>,4:string}
     */
    private function adminState(Request $request, string $error): array
    {
        $isPublished = $this->repository->isPublished();
        $blocks = $this->repository->listBlocks();
        $editBlock = null;
        $editId = max(0, (int) ($request->query('edit', '0') ?? '0'));
        if ($editId > 0) {
            $editBlock = $this->repository->findBlock($editId);
            if ($editBlock === null) {
                $error = trim($error . ' ' . 'Der angeforderte Block wurde nicht gefunden.');
            }
        }

        $previewBlocks = [
            'all' => $this->renderer->prepareBlocks($blocks, $this->availableModulesForAudience('admin'), true),
            'guest' => $this->renderer->prepareBlocks($this->previewBlocks($blocks, 'guest'), $this->availableModulesForAudience('guest')),
            'user' => $this->renderer->prepareBlocks($this->previewBlocks($blocks, 'user'), $this->availableModulesForAudience('user')),
            'admin' => $this->renderer->prepareBlocks($this->previewBlocks($blocks, 'admin'), $this->availableModulesForAudience('admin')),
        ];

        return [$isPublished, $blocks, $editBlock, $previewBlocks, $error];
    }

    private function looksLikeMissingHomepageSchema(Throwable $throwable): bool
    {
        $message = strtolower($throwable->getMessage());

        return str_contains($message, 'homepage_blocks')
            || str_contains($message, 'homepage_config')
            || str_contains($message, 'homepage.is_published');
    }

    public function togglePublished(Request $request): Response
    {
        try {
            $published = (string) $request->input('is_published', '0') === '1';
            $this->repository->setPublished($published);
            $message = $published
                ? 'Die konfigurierte Startseite wurde veröffentlicht.'
                : 'Die konfigurierte Startseite wurde deaktiviert. Die Standard-Startseite bleibt aktiv.';
            if ($this->wantsJson($request)) {
                return $this->json([
                    'ok' => true,
                    'message' => $message,
                    'is_published' => $published,
                    'label' => $published ? 'Konfigurierte Homepage veröffentlicht' : 'Standard-Startseite aktiv',
                    'button_label' => $published ? 'Standard-Startseite verwenden' : 'Konfigurierte Startseite veröffentlichen',
                ]);
            }
            $this->session->flash('homepage_info', $message);
        } catch (Throwable $throwable) {
            if ($this->wantsJson($request)) {
                return $this->json(['ok' => false, 'message' => $throwable->getMessage()], 500);
            }
            $this->session->flash('homepage_error', $throwable->getMessage());
        }

        return Response::redirect('/admin/homepage');
    }

    public function createBlock(Request $request): Response
    {
        try {
            $data = $this->blockDataFromRequest($request);
            $this->repository->createBlock($data);
            $this->session->flash('homepage_info', 'Homepage-Block wurde erstellt.');
        } catch (Throwable $throwable) {
            $this->session->flash('homepage_error', $throwable->getMessage());
        }

        return Response::redirect('/admin/homepage');
    }

    public function updateBlock(Request $request): Response
    {
        $id = max(0, (int) $request->input('block_id', '0'));
        try {
            if ($id <= 0 || $this->repository->findBlock($id) === null) {
                throw new \RuntimeException('Homepage-Block wurde nicht gefunden.');
            }

            $data = $this->blockDataFromRequest($request);
            $this->repository->updateBlock($id, $data);
            $this->session->flash('homepage_info', 'Homepage-Block wurde gespeichert.');
        } catch (Throwable $throwable) {
            $this->session->flash('homepage_error', $throwable->getMessage());
            return Response::redirect('/admin/homepage?edit=' . $id);
        }

        return Response::redirect('/admin/homepage');
    }

    public function toggleBlock(Request $request): Response
    {
        try {
            $id = max(0, (int) $request->input('block_id', '0'));
            if ($id <= 0 || $this->repository->findBlock($id) === null) {
                throw new \RuntimeException('Homepage-Block wurde nicht gefunden.');
            }

            $enabled = (string) $request->input('is_enabled', '0') === '1';
            $this->repository->setBlockEnabled($id, $enabled);
            $message = $enabled ? 'Block wurde aktiviert.' : 'Block wurde deaktiviert.';
            if ($this->wantsJson($request)) {
                return $this->json([
                    'ok' => true,
                    'message' => $message,
                    'block_id' => $id,
                    'is_enabled' => $enabled,
                    'status_label' => $enabled ? 'Aktiv' : 'Inaktiv',
                    'button_label' => $enabled ? 'Deaktivieren' : 'Aktivieren',
                ]);
            }
            $this->session->flash('homepage_info', $message);
        } catch (Throwable $throwable) {
            if ($this->wantsJson($request)) {
                return $this->json(['ok' => false, 'message' => $throwable->getMessage()], 500);
            }
            $this->session->flash('homepage_error', $throwable->getMessage());
        }

        return Response::redirect('/admin/homepage');
    }

    public function toggleVisibility(Request $request): Response
    {
        try {
            $id = max(0, (int) $request->input('block_id', '0'));
            $field = (string) $request->input('field', '');
            if ($id <= 0 || $this->repository->findBlock($id) === null) {
                throw new \RuntimeException('Homepage-Block wurde nicht gefunden.');
            }
            if (!in_array($field, ['visibility_guest', 'visibility_user', 'visibility_admin'], true)) {
                throw new \RuntimeException('Ungültige Sichtbarkeit.');
            }

            $visible = (string) $request->input('visible', '0') === '1';
            $this->repository->setBlockVisibility($id, $field, $visible);
            $message = 'Sichtbarkeit wurde aktualisiert.';
            if ($this->wantsJson($request)) {
                return $this->json([
                    'ok' => true,
                    'message' => $message,
                    'block_id' => $id,
                    'field' => $field,
                    'visible' => $visible,
                ]);
            }
            $this->session->flash('homepage_info', $message);
        } catch (Throwable $throwable) {
            if ($this->wantsJson($request)) {
                return $this->json(['ok' => false, 'message' => $throwable->getMessage()], 500);
            }
            $this->session->flash('homepage_error', $throwable->getMessage());
        }

        return Response::redirect('/admin/homepage');
    }

    public function deleteBlock(Request $request): Response
    {
        try {
            $id = max(0, (int) $request->input('block_id', '0'));
            if ($id <= 0 || $this->repository->findBlock($id) === null) {
                throw new \RuntimeException('Homepage-Block wurde nicht gefunden.');
            }

            $this->repository->deleteBlock($id);
            if ($this->wantsJson($request)) {
                return $this->json([
                    'ok' => true,
                    'message' => 'Homepage-Block wurde gelöscht.',
                    'block_id' => $id,
                ]);
            }
            $this->session->flash('homepage_info', 'Homepage-Block wurde gelöscht.');
        } catch (Throwable $throwable) {
            if ($this->wantsJson($request)) {
                return $this->json(['ok' => false, 'message' => $throwable->getMessage()], 500);
            }
            $this->session->flash('homepage_error', $throwable->getMessage());
        }

        return Response::redirect('/admin/homepage');
    }

    public function moveBlock(Request $request): Response
    {
        try {
            $id = max(0, (int) $request->input('block_id', '0'));
            $direction = (string) $request->input('direction', '');
            if ($id <= 0 || !in_array($direction, ['up', 'down'], true)) {
                throw new \RuntimeException('Ungültige Sortieraktion.');
            }

            $this->repository->moveBlock($id, $direction);
            if ($this->wantsJson($request)) {
                return $this->json([
                    'ok' => true,
                    'message' => 'Sortierung wurde aktualisiert.',
                    'block_id' => $id,
                    'direction' => $direction,
                ]);
            }
            $this->session->flash('homepage_info', 'Sortierung wurde aktualisiert.');
        } catch (Throwable $throwable) {
            if ($this->wantsJson($request)) {
                return $this->json(['ok' => false, 'message' => $throwable->getMessage()], 500);
            }
            $this->session->flash('homepage_error', $throwable->getMessage());
        }

        return Response::redirect('/admin/homepage');
    }

    private function jsonOrRedirectError(Request $request, string $message): Response
    {
        if ($this->wantsJson($request)) {
            return $this->json(['ok' => false, 'message' => $message], 422);
        }

        return $this->redirectError($message);
    }

    private function redirectError(string $message): Response
    {
        $this->session->flash('homepage_error', $message);

        return Response::redirect('/admin/homepage');
    }

    private function wantsJson(Request $request): bool
    {
        return (string) $request->input('ajax', '0') === '1';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function json(array $payload, int $status = 200): Response
    {
        return new Response(
            json_encode($payload, JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }

    /**
     * @return array{
     *   type:string,title:string,show_title:bool,content_markdown:?string,button_label:?string,button_url:?string,
     *   visibility_guest:bool,visibility_user:bool,visibility_admin:bool,column_span:string,button_layout:string,is_enabled:bool,
     *   buttons:array<int,array{label:string,url:string,variant:string}>,
     *   items:array<int,array{title:string,content_markdown:?string}>
     * }
     */
    private function blockDataFromRequest(Request $request): array
    {
        $type = (string) $request->input('type', 'custom_content');
        if (!in_array($type, HomepageRepository::TYPES, true)) {
            throw new \RuntimeException('Ungültiger Blocktyp.');
        }

        $title = trim((string) $request->input('title', ''));
        if ($title === '') {
            throw new \RuntimeException('Titel darf nicht leer sein.');
        }

        $content = trim((string) $request->input('content_markdown', ''));
        $buttonLabel = trim((string) $request->input('button_label', ''));
        $buttonUrlRaw = trim((string) $request->input('button_url', ''));
        $buttonUrl = $this->safeButtonUrl($buttonUrlRaw);
        if ($buttonUrlRaw !== '' && $buttonUrl === '') {
            throw new \RuntimeException('Button-URL ist nicht erlaubt. Erlaubt sind relative URLs sowie http/https.');
        }
        if ($buttonUrl === '') {
            $buttonLabel = '';
        }
        if ($type === 'module_list') {
            $buttonLabel = '';
            $buttonUrl = '';
        }

        $buttons = $type === 'custom_content' ? $this->buttonsFromRequest($request) : [];
        if ($buttons !== []) {
            $buttonLabel = $buttons[0]['label'];
            $buttonUrl = $buttons[0]['url'];
        }

        if ($type === 'custom_content' && $content === '' && $buttons === [] && $buttonUrl === '') {
            throw new \RuntimeException('Ein Inhaltsblock braucht Markdown-Inhalt oder einen gültigen Button.');
        }
        $items = $type === 'feature_list' ? $this->itemsFromRequest($request) : [];
        if ($type === 'feature_list') {
            $buttonLabel = '';
            $buttonUrl = '';
            if ($content === '' && $items === []) {
                throw new \RuntimeException('Eine Feature-Liste braucht eine Einleitung oder mindestens ein Feature-Item.');
            }
        }

        $columnSpan = (string) $request->input('column_span', 'full');
        if (!in_array($columnSpan, HomepageRepository::COLUMN_SPANS, true)) {
            $columnSpan = 'full';
        }
        $buttonLayout = (string) $request->input('button_layout', 'below_text');
        if ($type !== 'custom_content' || !in_array($buttonLayout, HomepageRepository::BUTTON_LAYOUTS, true)) {
            $buttonLayout = 'below_text';
        }

        return [
            'type' => $type,
            'title' => mb_substr($title, 0, 190),
            'show_title' => (string) $request->input('show_title', '1') === '1',
            'content_markdown' => $content !== '' ? $content : null,
            'button_label' => $buttonLabel !== '' ? mb_substr($buttonLabel, 0, 120) : null,
            'button_url' => $buttonUrl !== '' ? $buttonUrl : null,
            'visibility_guest' => (string) $request->input('visibility_guest', '0') === '1',
            'visibility_user' => (string) $request->input('visibility_user', '0') === '1',
            'visibility_admin' => (string) $request->input('visibility_admin', '0') === '1',
            'column_span' => $columnSpan,
            'button_layout' => $buttonLayout,
            'is_enabled' => (string) $request->input('is_enabled', '0') === '1',
            'buttons' => $buttons,
            'items' => $items,
        ];
    }

    /**
     * @return array<int,array{label:string,url:string,variant:string}>
     */
    private function buttonsFromRequest(Request $request): array
    {
        $buttonsRaw = $request->inputRaw('buttons', []);
        if (!is_array($buttonsRaw)) {
            return [];
        }

        $buttons = [];
        foreach ($buttonsRaw as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $label = trim((string) ($raw['label'] ?? ''));
            $urlRaw = trim((string) ($raw['url'] ?? ''));
            if ($label === '' && $urlRaw === '') {
                continue;
            }
            $url = $this->safeButtonUrl($urlRaw);
            if ($label === '' || $url === '') {
                throw new \RuntimeException('Button-Zeilen brauchen Text und eine erlaubte URL.');
            }
            $variant = (string) ($raw['variant'] ?? 'primary');
            $buttons[] = [
                'label' => mb_substr($label, 0, 120),
                'url' => $url,
                'variant' => $variant === 'secondary' ? 'secondary' : 'primary',
            ];
        }

        return $buttons;
    }

    /**
     * @return array<int,array{title:string,content_markdown:?string}>
     */
    private function itemsFromRequest(Request $request): array
    {
        $itemsRaw = $request->inputRaw('items', []);
        if (!is_array($itemsRaw)) {
            return [];
        }

        $items = [];
        foreach ($itemsRaw as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $title = trim((string) ($raw['title'] ?? ''));
            $content = trim((string) ($raw['content_markdown'] ?? ''));
            if ($title === '' && $content === '') {
                continue;
            }
            if ($title === '') {
                throw new \RuntimeException('Feature-Items brauchen einen Titel.');
            }
            $items[] = [
                'title' => mb_substr($title, 0, 190),
                'content_markdown' => $content !== '' ? $content : null,
            ];
        }

        return $items;
    }

    private function safeButtonUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (str_starts_with($url, '/')) {
            return preg_match('~^/[^\s<>"]*$~', $url) === 1 ? $url : '';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (in_array($scheme, ['http', 'https'], true) && filter_var($url, FILTER_VALIDATE_URL) !== false) {
            return $url;
        }

        return '';
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    private function previewBlocks(array $blocks, string $audience): array
    {
        $column = match ($audience) {
            'admin' => 'visibility_admin',
            'user' => 'visibility_user',
            default => 'visibility_guest',
        };

        return array_values(array_filter($blocks, static fn (array $block): bool => (int) ($block['is_enabled'] ?? 0) === 1 && (int) ($block[$column] ?? 0) === 1));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function availableModulesForAudience(string $audience): array
    {
        if ($this->modules === null) {
            return [];
        }

        $isAdmin = $audience === 'admin';
        $isUser = $audience === 'user' || $isAdmin;
        $items = [];
        foreach ($this->modules->listActive() as $module) {
            $prefix = trim((string) ($module['route_prefix'] ?? ''), '/');
            if ($prefix === '' || (int) ($module['show_on_home'] ?? 1) !== 1) {
                continue;
            }

            $access = strtolower((string) ($module['access_level'] ?? 'public'));
            if ($access === 'admin' && !$isAdmin) {
                continue;
            }
            if ($access === 'user' && !$isUser) {
                continue;
            }

            $items[] = [
                'name' => (string) ($module['name'] ?? $prefix),
                'description' => (string) ($module['description'] ?? ''),
                'prefix' => $prefix,
                'access' => $access,
                'url' => '/' . $prefix . '/',
            ];
        }

        return $items;
    }
}
