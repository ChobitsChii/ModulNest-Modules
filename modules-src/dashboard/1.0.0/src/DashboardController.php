<?php

declare(strict_types=1);

namespace ModulNest\Dashboard;

use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Session;
use Modulon\Core\View;
use Modulon\Modules\Auth\AuthService;
use Modulon\Modules\Auth\UserRepository;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class DashboardController
{
    private const FORM_STATE_KEY = 'dashboard_links_form_state';
    private const MAX_HTML_BYTES = 1200000;
    private const MAX_IMAGE_BYTES = 800000;
    private const MAX_UPLOAD_BYTES = 1048576;
    private const HTTP_TIMEOUT_SECONDS = 6;
    private const HTTP_CONNECT_TIMEOUT_SECONDS = 4;
    private const HTTP_MAX_REDIRECTS = 4;
    private const FAVICON_OUTPUT_SIZE = 64;
    private const DASHBOARD_AUTO_REFRESH_DEFAULT_MINUTES = 30;
    private const DASHBOARD_AUTO_REFRESH_MIN_MINUTES = 5;
    private const DASHBOARD_AUTO_REFRESH_MAX_MINUTES = 240;

    public function __construct(
        private readonly DashboardRepository $dashboard,
        private readonly Session $session,
        private readonly ?AuthService $auth = null,
        private readonly ?UserRepository $users = null,
        private readonly string $basePath = '',
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->auth?->currentUser();
        if (!is_array($user)) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);
        if ($userId > 0) {
            $this->dashboard->ensureDefaultLinksWidgetForUser($userId);
            $this->dashboard->ensureDefaultWidgetForUser($userId, 'tasks', 'Meine Aufgaben', 6);
            $this->dashboard->ensureDefaultWidgetForUser($userId, 'notes', 'Meine Notizen', 6);
            $this->syncRecurringTasks($userId, $this->resolveTaskTimezone($user));
        }

        $widgets = $userId > 0 ? $this->dashboard->listWidgetsForUser($userId) : [];
        $activeWidgets = array_values(array_filter(
            $widgets,
            static fn (array $widget): bool => (int) ($widget['is_active'] ?? 0) === 1
        ));
        $counts = $userId > 0 ? $this->dashboard->countWidgetsByType($userId) : ['links' => 0, 'tasks' => 0, 'notes' => 0];
        $widgetIds = array_values(array_map(static fn (array $widget): int => (int) ($widget['id'] ?? 0), $activeWidgets));

        $foldersByWidget = $this->dashboard->listFoldersByWidgetIds($widgetIds);
        foreach ($activeWidgets as $widget) {
            $widgetId = (int) ($widget['id'] ?? 0);
            $type = strtolower((string) ($widget['widget_type'] ?? ''));
            if ($widgetId > 0 && $type === 'links' && ($foldersByWidget[$widgetId] ?? []) === []) {
                $this->dashboard->ensureDefaultFolderForWidget($widgetId);
            }
        }

        $foldersByWidget = $this->dashboard->listFoldersByWidgetIds($widgetIds);
        $linksByWidget = $this->dashboard->listLinksByWidgetIds($widgetIds);
        $tasksByWidget = $this->dashboard->listTasksByWidgetIds($widgetIds);
        $archivedTasksByWidget = $this->dashboard->listArchivedTasksByWidgetIds($widgetIds);
        $notesByWidget = $this->dashboard->listNotesByWidgetIds($widgetIds);
        $archivedNotesByWidget = $this->dashboard->listArchivedNotesByWidgetIds($widgetIds);
        $dashboardTimezoneName = $this->auth instanceof AuthService
            ? $this->auth->resolveUserTimezoneName($user)
            : 'UTC';
        $autoRefreshEnabled = $this->resolveDashboardAutoRefreshEnabled($user);
        $autoRefreshMinutes = $this->resolveDashboardAutoRefreshIntervalMinutes($user);

        return new Response(View::render('@modulnest.dashboard/index', [
            'title' => 'Dashboard',
            'current_path' => $request->path(),
            'auth' => [
                'is_authenticated' => true,
                'is_admin' => $this->auth?->isAdmin() ?? false,
                'user_name' => (string) ($user['name'] ?? ''),
            ],
            'widget_counts' => $counts,
            'widgets' => $widgets,
            'active_widgets' => $activeWidgets,
            'folders_by_widget' => $foldersByWidget,
            'links_by_widget' => $linksByWidget,
            'tasks_by_widget' => $tasksByWidget,
            'archived_tasks_by_widget' => $archivedTasksByWidget,
            'notes_by_widget' => $notesByWidget,
            'archived_notes_by_widget' => $archivedNotesByWidget,
            'form_state' => $this->pullFormState(),
            'message' => $this->session->pullFlash('dashboard_info'),
            'error' => $this->session->pullFlash('dashboard_error'),
            'dashboard_now_utc' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'dashboard_timezone_name' => $dashboardTimezoneName,
            'dashboard_auto_refresh_enabled' => $autoRefreshEnabled,
            'dashboard_auto_refresh_interval_minutes' => $autoRefreshMinutes,
            'dashboard_auto_refresh_interval_min' => self::DASHBOARD_AUTO_REFRESH_MIN_MINUTES,
            'dashboard_auto_refresh_interval_max' => self::DASHBOARD_AUTO_REFRESH_MAX_MINUTES,
        ]));
    }

    public function updateAutoRefreshSettings(Request $request): Response
    {
        $user = $this->auth?->currentUser();
        if (!is_array($user)) {
            return $this->json(['ok' => false, 'message' => 'Nicht eingeloggt.'], 401);
        }

        if ($this->users === null) {
            return $this->json(['ok' => false, 'message' => 'Service derzeit nicht verfügbar.'], 503);
        }

        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return $this->json(['ok' => false, 'message' => 'Ungültiger Benutzerkontext.'], 422);
        }

        $enabled = $this->toBool($request->inputRaw('enabled', 0));
        $currentInterval = $this->resolveDashboardAutoRefreshIntervalMinutes($user);
        $intervalRaw = $request->inputRaw('interval_minutes', $currentInterval);
        $interval = $this->normalizeDashboardAutoRefreshIntervalMinutes($intervalRaw);
        if ($interval === null) {
            return $this->json([
                'ok' => false,
                'message' => sprintf(
                    'Intervall muss zwischen %d und %d Minuten liegen.',
                    self::DASHBOARD_AUTO_REFRESH_MIN_MINUTES,
                    self::DASHBOARD_AUTO_REFRESH_MAX_MINUTES
                ),
            ], 422);
        }

        $this->users->updateDashboardAutoRefreshSettings($userId, $enabled, $interval);

        return $this->json([
            'ok' => true,
            'enabled' => $enabled ? 1 : 0,
            'interval_minutes' => $interval,
            'message' => $enabled
                ? sprintf('Auto-Aktualisieren aktiviert (%d Minuten).', $interval)
                : 'Auto-Aktualisieren deaktiviert.',
        ]);
    }

    public function createWidget(Request $request): Response
    {
        $user = $this->auth?->currentUser();
        if (!is_array($user)) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);
        $widgetType = strtolower(trim((string) $request->input('widget_type', 'links')));
        if (!in_array($widgetType, ['links', 'tasks', 'notes'], true)) {
            $widgetType = 'links';
        }

        $defaultTitle = match ($widgetType) {
            'tasks' => 'Meine Aufgaben',
            'notes' => 'Meine Notizen',
            default => 'Meine Links',
        };
        $title = trim((string) $request->input('title', $defaultTitle));
        if ($title === '') {
            $title = $defaultTitle;
        }
        $layoutWidth = $this->normalizeWidgetWidth((int) $request->input('layout_width', '6'));

        $widgetId = $this->dashboard->createWidget($userId, $widgetType, mb_substr($title, 0, 120), $layoutWidth);
        if ($widgetType === 'links') {
            $this->dashboard->ensureDefaultFolderForWidget($widgetId);
        }

        $this->session->flash('dashboard_info', 'Widget hinzugefügt.');
        return Response::redirect('/dashboard#widget-' . $widgetId);
    }

    public function updateWidget(Request $request): Response
    {
        $user = $this->auth?->currentUser();
        if (!is_array($user)) {
            return $this->widgetResponse(false, 'Nicht eingeloggt.', 401);
        }

        $userId = (int) ($user['id'] ?? 0);
        $widgetId = (int) $request->inputRaw('widget_id', $request->input('widget_id', '0'));
        $widget = $this->dashboard->findWidgetForUser($widgetId, $userId);
        if (!is_array($widget)) {
            return $this->widgetResponse(false, 'Widget nicht gefunden.', 404);
        }

        $title = null;
        $rawTitle = $request->inputRaw('title', null);
        if (is_string($rawTitle)) {
            $title = trim($rawTitle);
            if ($title === '') {
                return $this->widgetResponse(false, 'Widget-Titel darf nicht leer sein.', 422);
            }
            $title = mb_substr($title, 0, 120);
        }

        $layoutWidth = null;
        $rawLayoutWidth = $request->inputRaw('layout_width', null);
        if ($rawLayoutWidth !== null) {
            $layoutWidth = $this->normalizeWidgetWidth((int) $rawLayoutWidth);
        }

        $isActive = null;
        $rawActive = $request->inputRaw('is_active', null);
        if ($rawActive !== null) {
            $isActive = $this->toBool($rawActive);
        }

        $this->dashboard->updateWidgetForUser($widgetId, $userId, $title, $layoutWidth, $isActive);

        return $this->widgetResponse(true, 'Widget aktualisiert.', 200, [
            'widget_id' => $widgetId,
            'layout_width' => $layoutWidth ?? (int) ($widget['layout_width'] ?? 6),
            'is_active' => $isActive === null ? (int) ($widget['is_active'] ?? 0) : ($isActive ? 1 : 0),
        ]);
    }

    public function reorderWidgets(Request $request): Response
    {
        $user = $this->auth?->currentUser();
        if (!is_array($user)) {
            return $this->json(['ok' => false, 'message' => 'Nicht eingeloggt.'], 401);
        }

        $userId = (int) ($user['id'] ?? 0);
        $rawIds = $request->inputRaw('widget_ids', []);
        if (!is_array($rawIds)) {
            return $this->json(['ok' => false, 'message' => 'Ungültige Widget-Reihenfolge.'], 422);
        }

        $this->dashboard->reorderWidgetsForUser($userId, array_map('intval', $rawIds));
        return $this->json(['ok' => true, 'message' => 'Widget-Reihenfolge gespeichert.']);
    }

    public function deleteWidget(Request $request): Response
    {
        $user = $this->auth?->currentUser();
        if (!is_array($user)) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);
        $widgetId = (int) $request->input('widget_id', '0');
        if ($widgetId <= 0 || !$this->dashboard->deleteWidgetForUser($widgetId, $userId)) {
            $this->session->flash('dashboard_error', 'Widget konnte nicht gelöscht werden.');
            return Response::redirect('/dashboard');
        }

        $this->session->flash('dashboard_info', 'Widget endgültig gelöscht.');
        return Response::redirect('/dashboard');
    }

    public function createTask(Request $request): Response
    {
        $user = $this->auth?->currentUser();
        if (!is_array($user)) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);
        $widgetId = (int) $request->input('widget_id', '0');
        $widget = $this->dashboard->findWidgetForUser($widgetId, $userId);
        if (!is_array($widget) || strtolower((string) ($widget['widget_type'] ?? '')) !== 'tasks') {
            $this->session->flash('dashboard_error', 'Ungültiges Aufgaben-Widget.');
            return Response::redirect('/dashboard');
        }

        $payload = $this->taskPayloadFromRequest($request);
        if ($payload === null) {
            return Response::redirect('/dashboard#widget-' . $widgetId);
        }

        $this->dashboard->createTask($widgetId, $payload);

        $this->session->flash('dashboard_info', 'Aufgabe gespeichert.');
        return Response::redirect('/dashboard#widget-' . $widgetId);
    }

    public function updateTask(Request $request): Response
    {
        $user = $this->auth?->currentUser();
        if (!is_array($user)) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);
        $taskId = (int) $request->input('task_id', '0');
        if ($taskId <= 0) {
            $this->session->flash('dashboard_error', 'Ungültige Task-ID.');
            return Response::redirect('/dashboard');
        }

        $task = $this->dashboard->findTaskForUser($taskId, $userId);
        if (!is_array($task)) {
            $this->session->flash('dashboard_error', 'Aufgabe nicht gefunden.');
            return Response::redirect('/dashboard');
        }

        $widgetId = (int) ($task['widget_id'] ?? 0);
        if ($widgetId <= 0) {
            $this->session->flash('dashboard_error', 'Ungültiger Aufgaben-Kontext.');
            return Response::redirect('/dashboard');
        }

        $payload = $this->taskPayloadFromRequest($request);
        if ($payload === null) {
            return Response::redirect('/dashboard#widget-' . $widgetId);
        }

        $this->dashboard->updateTask($taskId, $payload);
        $this->session->flash('dashboard_info', 'Aufgabe aktualisiert.');
        return Response::redirect('/dashboard#widget-' . $widgetId);
    }

    public function deleteTask(Request $request): Response
    {
        $user = $this->auth?->currentUser();
        if (!is_array($user)) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);
        $taskId = (int) $request->input('task_id', '0');
        if ($taskId <= 0) {
            $this->session->flash('dashboard_error', 'Ungültige Task-ID.');
            return Response::redirect('/dashboard');
        }

        $task = $this->dashboard->findTaskForUser($taskId, $userId);
        if (!is_array($task)) {
            $this->session->flash('dashboard_error', 'Aufgabe nicht gefunden.');
            return Response::redirect('/dashboard');
        }

        $widgetId = (int) ($task['widget_id'] ?? 0);
        $this->dashboard->deleteTask($taskId);
        $this->session->flash('dashboard_info', 'Aufgabe gelöscht.');
        return Response::redirect('/dashboard#widget-' . $widgetId);
    }

    public function toggleTask(Request $request): Response
    {
        $wantsJson = $this->requestWantsJson();
        $user = $this->auth?->currentUser();
        if (!is_array($user)) {
            if (!$wantsJson) {
                $this->session->flash('dashboard_error', 'Bitte erneut einloggen.');
                return Response::redirect('/login');
            }

            return $this->json(['ok' => false, 'message' => 'Nicht eingeloggt.'], 401);
        }

        $userId = (int) ($user['id'] ?? 0);
        $taskId = (int) $request->inputRaw('task_id', 0);
        $isDone = $this->toBool($request->inputRaw('is_done', 0));
        if ($taskId <= 0) {
            if (!$wantsJson) {
                $this->session->flash('dashboard_error', 'Ungültige Task-ID.');
                return Response::redirect('/dashboard');
            }

            return $this->json(['ok' => false, 'message' => 'Ungültige Task-ID.'], 422);
        }

        $task = $this->dashboard->findTaskForUser($taskId, $userId);
        if (!is_array($task)) {
            if (!$wantsJson) {
                $this->session->flash('dashboard_error', 'Aufgabe nicht gefunden.');
                return Response::redirect('/dashboard');
            }

            return $this->json(['ok' => false, 'message' => 'Aufgabe nicht gefunden.'], 404);
        }

        $this->dashboard->setTaskDone($taskId, $isDone);

        if (!$wantsJson) {
            $this->session->flash('dashboard_info', $isDone ? 'Aufgabe als erledigt markiert.' : 'Aufgabe wieder geöffnet.');
            return Response::redirect('/dashboard#widget-' . (int) ($task['widget_id'] ?? 0));
        }

        return $this->json([
            'ok' => true,
            'task_id' => $taskId,
            'is_done' => $isDone ? 1 : 0,
            'message' => $isDone ? 'Als erledigt markiert.' : 'Als offen markiert.',
        ]);
    }

    public function archiveTask(Request $request): Response
    {
        $wantsJson = $this->requestWantsJson();
        $user = $this->auth?->currentUser();
        if (!is_array($user)) {
            return $this->archiveAuthResponse($wantsJson);
        }

        $userId = (int) ($user['id'] ?? 0);
        $taskId = (int) $request->inputRaw('task_id', 0);
        $archived = $this->toBool($request->inputRaw('archived', 1));
        if ($taskId <= 0) {
            return $this->archiveErrorResponse($wantsJson, 'Ungültige Task-ID.', 422);
        }

        $task = $this->dashboard->findTaskForUser($taskId, $userId);
        if (!is_array($task)) {
            return $this->archiveErrorResponse($wantsJson, 'Aufgabe nicht gefunden.', 404);
        }

        $this->dashboard->setTaskArchived($taskId, $archived);
        $message = $archived ? 'Aufgabe archiviert.' : 'Aufgabe wiederhergestellt.';
        $widgetId = (int) ($task['widget_id'] ?? 0);

        if (!$wantsJson) {
            $this->session->flash('dashboard_info', $message);
            return Response::redirect('/dashboard#widget-' . $widgetId);
        }

        return $this->json([
            'ok' => true,
            'type' => 'task',
            'id' => $taskId,
            'widget_id' => $widgetId,
            'archived' => $archived ? 1 : 0,
            'message' => $message,
        ]);
    }

    public function createNote(Request $request): Response
    {
        $user = $this->auth?->currentUser();
        if (!is_array($user)) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);
        $widgetId = (int) $request->input('widget_id', '0');
        $widget = $this->dashboard->findWidgetForUser($widgetId, $userId);
        if (!is_array($widget) || strtolower((string) ($widget['widget_type'] ?? '')) !== 'notes') {
            $this->session->flash('dashboard_error', 'Ungültiges Notizen-Widget.');
            return Response::redirect('/dashboard');
        }

        $title = trim((string) $request->input('title', ''));
        $content = trim((string) $request->input('content', ''));
        if ($content === '') {
            $this->session->flash('dashboard_error', 'Notizinhalt ist erforderlich.');
            return Response::redirect('/dashboard#widget-' . $widgetId);
        }

        $this->dashboard->createNote(
            $widgetId,
            $title !== '' ? mb_substr($title, 0, 180) : null,
            mb_substr($content, 0, 20000),
            $this->normalizeTextareaHeight($request->inputRaw('textarea_height', null))
        );

        $this->session->flash('dashboard_info', 'Notiz erstellt.');
        return Response::redirect('/dashboard#widget-' . $widgetId);
    }

    public function updateNote(Request $request): Response
    {
        $user = $this->auth?->currentUser();
        if (!is_array($user)) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);
        $noteId = (int) $request->input('note_id', '0');
        if ($noteId <= 0) {
            $this->session->flash('dashboard_error', 'Ungültige Notiz-ID.');
            return Response::redirect('/dashboard');
        }

        $note = $this->dashboard->findNoteForUser($noteId, $userId);
        if (!is_array($note)) {
            $this->session->flash('dashboard_error', 'Notiz nicht gefunden.');
            return Response::redirect('/dashboard');
        }

        $title = trim((string) $request->input('title', ''));
        $content = trim((string) $request->input('content', ''));
        if ($content === '') {
            $this->session->flash('dashboard_error', 'Notizinhalt ist erforderlich.');
            return Response::redirect('/dashboard#widget-' . (int) ($note['widget_id'] ?? 0));
        }

        $this->dashboard->updateNote(
            $noteId,
            $title !== '' ? mb_substr($title, 0, 180) : null,
            mb_substr($content, 0, 20000),
            $this->normalizeTextareaHeight($request->inputRaw('textarea_height', null))
        );

        $this->session->flash('dashboard_info', 'Notiz aktualisiert.');
        return Response::redirect('/dashboard#widget-' . (int) ($note['widget_id'] ?? 0));
    }

    public function deleteNote(Request $request): Response
    {
        $user = $this->auth?->currentUser();
        if (!is_array($user)) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);
        $noteId = (int) $request->input('note_id', '0');
        if ($noteId <= 0) {
            $this->session->flash('dashboard_error', 'Ungültige Notiz-ID.');
            return Response::redirect('/dashboard');
        }

        $note = $this->dashboard->findNoteForUser($noteId, $userId);
        if (!is_array($note)) {
            $this->session->flash('dashboard_error', 'Notiz nicht gefunden.');
            return Response::redirect('/dashboard');
        }

        $this->dashboard->deleteNote($noteId);
        $this->session->flash('dashboard_info', 'Notiz gelöscht.');
        return Response::redirect('/dashboard#widget-' . (int) ($note['widget_id'] ?? 0));
    }

    public function archiveNote(Request $request): Response
    {
        $wantsJson = $this->requestWantsJson();
        $user = $this->auth?->currentUser();
        if (!is_array($user)) {
            return $this->archiveAuthResponse($wantsJson);
        }

        $userId = (int) ($user['id'] ?? 0);
        $noteId = (int) $request->inputRaw('note_id', 0);
        $archived = $this->toBool($request->inputRaw('archived', 1));
        if ($noteId <= 0) {
            return $this->archiveErrorResponse($wantsJson, 'Ungültige Notiz-ID.', 422);
        }

        $note = $this->dashboard->findNoteForUser($noteId, $userId);
        if (!is_array($note)) {
            return $this->archiveErrorResponse($wantsJson, 'Notiz nicht gefunden.', 404);
        }

        $this->dashboard->setNoteArchived($noteId, $archived);
        $message = $archived ? 'Notiz archiviert.' : 'Notiz wiederhergestellt.';
        $widgetId = (int) ($note['widget_id'] ?? 0);

        if (!$wantsJson) {
            $this->session->flash('dashboard_info', $message);
            return Response::redirect('/dashboard#widget-' . $widgetId);
        }

        return $this->json([
            'ok' => true,
            'type' => 'note',
            'id' => $noteId,
            'widget_id' => $widgetId,
            'archived' => $archived ? 1 : 0,
            'message' => $message,
        ]);
    }

    public function analyzeLink(Request $request): Response
    {
        $user = $this->auth?->currentUser();
        if (!is_array($user)) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);
        $widgetId = (int) $request->input('widget_id', '0');
        $folderId = (int) $request->input('folder_id', '0');
        $rawUrl = trim((string) $request->input('url', ''));
        $manualTitle = trim((string) $request->input('title', ''));
        $manualFavicon = trim((string) $request->input('favicon_url', ''));

        $widget = $this->dashboard->findWidgetForUser($widgetId, $userId);
        if (!is_array($widget) || strtolower((string) ($widget['widget_type'] ?? '')) !== 'links') {
            $this->session->flash('dashboard_error', 'Ungültiges Links-Widget.');
            return Response::redirect('/dashboard');
        }

        $normalizedUrl = $this->normalizeHttpUrl($rawUrl);
        if ($normalizedUrl === null) {
            $this->session->flash('dashboard_error', 'Bitte eine gültige URL mit http/https angeben.');
            return Response::redirect('/dashboard#widget-' . $widgetId);
        }

        $metadata = $this->extractUrlMetadata($normalizedUrl);
        $title = $manualTitle !== '' ? $this->sanitizeTitle($manualTitle) : $metadata['title'];
        if ($title === '') {
            $title = 'Neuer Link';
        }

        $faviconUrl = '';
        if ($manualFavicon !== '') {
            $manualNormalized = $this->normalizeHttpUrl($manualFavicon);
            if ($manualNormalized === null) {
                if ($this->isAjaxRequest()) {
                    return $this->json(['ok' => false, 'message' => 'Favicon-URL muss eine gültige absolute URL (http/https) sein.'], 422);
                }
                $this->session->flash('dashboard_error', 'Favicon-URL muss eine gültige absolute URL (http/https) sein.');
                return Response::redirect('/dashboard#widget-' . $widgetId);
            }
            $faviconUrl = $manualNormalized;
        } else {
            $detected = (string) ($metadata['favicon_url'] ?? '');
            $faviconUrl = $this->normalizeHttpUrl($detected) ?? '';
        }

        if ($folderId > 0 && !$this->dashboard->folderBelongsToWidget($folderId, $widgetId)) {
            $folderId = 0;
        }

        if ($this->isAjaxRequest()) {
            return $this->json([
                'ok' => true,
                'widget_id' => $widgetId,
                'folder_id' => $folderId > 0 ? $folderId : null,
                'url' => $normalizedUrl,
                'title' => $title,
                'favicon_url' => $faviconUrl,
                'message' => 'URL analysiert.',
            ]);
        }

        $state = $this->getFormState();
        $state[(string) $widgetId] = [
            'widget_id' => $widgetId,
            'folder_id' => $folderId > 0 ? $folderId : null,
            'url' => $normalizedUrl,
            'title' => $title,
            'favicon_url' => $faviconUrl,
        ];
        $this->session->set(self::FORM_STATE_KEY, $state);

        $this->session->flash('dashboard_info', 'URL analysiert. Titel/Favicon können vor dem Speichern angepasst werden.');
        return Response::redirect('/dashboard#widget-' . $widgetId);
    }

    public function createFolder(Request $request): Response
    {
        $user = $this->auth?->currentUser();
        if (!is_array($user)) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);
        $widgetId = (int) $request->input('widget_id', '0');
        $name = trim((string) $request->input('folder_name', ''));

        $widget = $this->dashboard->findWidgetForUser($widgetId, $userId);
        if (!is_array($widget) || strtolower((string) ($widget['widget_type'] ?? '')) !== 'links') {
            $this->session->flash('dashboard_error', 'Ungültiges Links-Widget.');
            return Response::redirect('/dashboard');
        }

        if ($name === '') {
            $this->session->flash('dashboard_error', 'Ordnername ist erforderlich.');
            return Response::redirect('/dashboard#widget-' . $widgetId);
        }

        $name = mb_substr($name, 0, 120);
        $this->dashboard->createFolder($widgetId, $name);
        $this->session->flash('dashboard_info', 'Ordner erstellt.');
        return Response::redirect('/dashboard#widget-' . $widgetId);
    }

    public function storeLink(Request $request): Response
    {
        $user = $this->auth?->currentUser();
        if (!is_array($user)) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);
        $widgetId = (int) $request->input('widget_id', '0');
        $folderId = (int) $request->input('folder_id', '0');
        $rawUrl = trim((string) $request->input('url', ''));
        $rawTitle = trim((string) $request->input('title', ''));
        $rawFavicon = trim((string) $request->input('favicon_url', ''));

        $widget = $this->dashboard->findWidgetForUser($widgetId, $userId);
        if (!is_array($widget) || strtolower((string) ($widget['widget_type'] ?? '')) !== 'links') {
            $this->session->flash('dashboard_error', 'Ungültiges Links-Widget.');
            return Response::redirect('/dashboard');
        }

        $url = $this->normalizeHttpUrl($rawUrl);
        if ($url === null) {
            $this->session->flash('dashboard_error', 'Bitte eine gültige URL mit http/https angeben.');
            return Response::redirect('/dashboard#widget-' . $widgetId);
        }

        if ($rawTitle === '') {
            $this->session->flash('dashboard_error', 'Titel ist erforderlich.');
            return Response::redirect('/dashboard#widget-' . $widgetId);
        }

        $title = $this->sanitizeTitle($rawTitle);
        if ($title === '') {
            $this->session->flash('dashboard_error', 'Titel ist ungültig.');
            return Response::redirect('/dashboard#widget-' . $widgetId);
        }

        $faviconUrl = null;
        if ($rawFavicon !== '') {
            $faviconNormalized = $this->normalizeHttpUrl($rawFavicon);
            if ($faviconNormalized === null) {
                $this->session->flash('dashboard_error', 'Favicon-URL muss eine gültige absolute URL (http/https) sein.');
                return Response::redirect('/dashboard#widget-' . $widgetId);
            }
            $faviconUrl = $this->downloadAndStoreFavicon($faviconNormalized, $this->hostFromUrl($url));
        }

        $uploadAttempted = $this->wasUploadAttempted('favicon_file');
        $uploadedFaviconUrl = $this->handleFaviconUpload($userId);
        if ($uploadedFaviconUrl !== null) {
            $faviconUrl = $uploadedFaviconUrl;
        } elseif ($uploadAttempted) {
            // Detaillierte Fehlermeldung wurde bereits in handleFaviconUpload() gesetzt.
            return Response::redirect('/dashboard#widget-' . $widgetId);
        }

        if ($faviconUrl === null) {
            $detectedRemoteFavicon = $this->detectFaviconUrlForForm($url, $this->fetchHtml($url));
            if ($detectedRemoteFavicon !== null) {
                $faviconUrl = $this->downloadAndStoreFavicon($detectedRemoteFavicon, $this->hostFromUrl($url));
            }
        }

        if ($folderId > 0 && !$this->dashboard->folderBelongsToWidget($folderId, $widgetId)) {
            $folderId = 0;
        }
        $folder = $folderId > 0 ? $folderId : $this->dashboard->ensureDefaultFolderForWidget($widgetId);
        $faviconHost = null;
        if (is_string($faviconUrl) && $faviconUrl !== '') {
            $parsedHost = parse_url($faviconUrl, PHP_URL_HOST);
            if (is_string($parsedHost) && $parsedHost !== '') {
                $faviconHost = $parsedHost;
            }
        }

        $this->dashboard->createLink(
            $widgetId,
            $folder,
            mb_substr($title, 0, 180),
            mb_substr($url, 0, 2048),
            $faviconUrl !== null ? mb_substr($faviconUrl, 0, 2048) : null,
            $faviconHost !== null ? mb_substr($faviconHost, 0, 190) : null
        );

        $state = $this->getFormState();
        unset($state[(string) $widgetId]);
        $this->session->set(self::FORM_STATE_KEY, $state);

        $this->session->flash('dashboard_info', 'Link gespeichert.');
        return Response::redirect('/dashboard#widget-' . $widgetId);
    }

    public function updateLink(Request $request): Response
    {
        $user = $this->auth?->currentUser();
        if (!is_array($user)) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);
        $linkId = (int) $request->input('link_id', '0');
        if ($linkId <= 0) {
            $this->session->flash('dashboard_error', 'Ungültige Link-ID.');
            return Response::redirect('/dashboard');
        }

        $link = $this->dashboard->findLinkForUser($linkId, $userId);
        if (!is_array($link)) {
            $this->session->flash('dashboard_error', 'Link nicht gefunden.');
            return Response::redirect('/dashboard');
        }

        $widgetId = (int) ($link['widget_id'] ?? 0);
        if ($widgetId <= 0) {
            $this->session->flash('dashboard_error', 'Ungültiger Link-Kontext.');
            return Response::redirect('/dashboard');
        }

        $folderId = (int) $request->input('folder_id', '0');
        $rawUrl = trim((string) $request->input('url', ''));
        $rawTitle = trim((string) $request->input('title', ''));
        $rawFavicon = trim((string) $request->input('favicon_url', ''));
        $removeFavicon = $this->toBool($request->inputRaw('remove_favicon', '0'));

        $url = $this->normalizeHttpUrl($rawUrl);
        if ($url === null) {
            $this->session->flash('dashboard_error', 'Bitte eine gültige URL mit http/https angeben.');
            return Response::redirect('/dashboard#widget-' . $widgetId);
        }

        if ($rawTitle === '') {
            $this->session->flash('dashboard_error', 'Titel ist erforderlich.');
            return Response::redirect('/dashboard#widget-' . $widgetId);
        }

        $title = $this->sanitizeTitle($rawTitle);
        if ($title === '') {
            $this->session->flash('dashboard_error', 'Titel ist ungültig.');
            return Response::redirect('/dashboard#widget-' . $widgetId);
        }

        $existingFavicon = trim((string) ($link['favicon_url'] ?? ''));
        $faviconUrl = $removeFavicon ? null : ($existingFavicon !== '' ? $existingFavicon : null);

        if ($rawFavicon !== '') {
            $faviconNormalized = $this->normalizeHttpUrl($rawFavicon);
            if ($faviconNormalized === null) {
                $this->session->flash('dashboard_error', 'Favicon-URL muss eine gültige absolute URL (http/https) sein.');
                return Response::redirect('/dashboard#widget-' . $widgetId);
            }
            $downloadedFavicon = $this->downloadAndStoreFavicon($faviconNormalized, $this->hostFromUrl($url));
            if ($downloadedFavicon === null) {
                $this->session->flash('dashboard_error', 'Favicon konnte nicht geladen werden.');
                return Response::redirect('/dashboard#widget-' . $widgetId);
            }
            $faviconUrl = $downloadedFavicon;
        }

        $uploadAttempted = $this->wasUploadAttempted('favicon_file');
        $uploadedFaviconUrl = $this->handleFaviconUpload($userId);
        if ($uploadedFaviconUrl !== null) {
            $faviconUrl = $uploadedFaviconUrl;
        } elseif ($uploadAttempted) {
            return Response::redirect('/dashboard#widget-' . $widgetId);
        }

        if ($folderId > 0 && !$this->dashboard->folderBelongsToWidget($folderId, $widgetId)) {
            $folderId = 0;
        }
        $folder = $folderId > 0 ? $folderId : $this->dashboard->ensureDefaultFolderForWidget($widgetId);

        $faviconHost = null;
        if (is_string($faviconUrl) && $faviconUrl !== '') {
            $parsedHost = parse_url($faviconUrl, PHP_URL_HOST);
            if (is_string($parsedHost) && $parsedHost !== '') {
                $faviconHost = $parsedHost;
            }
        }

        $this->dashboard->updateLink(
            $linkId,
            $folder,
            mb_substr($title, 0, 180),
            mb_substr($url, 0, 2048),
            $faviconUrl !== null ? mb_substr($faviconUrl, 0, 2048) : null,
            $faviconHost !== null ? mb_substr($faviconHost, 0, 190) : null
        );

        $this->session->flash('dashboard_info', 'Link aktualisiert.');
        return Response::redirect('/dashboard#widget-' . $widgetId);
    }

    public function deleteLink(Request $request): Response
    {
        $user = $this->auth?->currentUser();
        if (!is_array($user)) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);
        $linkId = (int) $request->input('link_id', '0');
        if ($linkId <= 0) {
            $this->session->flash('dashboard_error', 'Ungültige Link-ID.');
            return Response::redirect('/dashboard');
        }

        $link = $this->dashboard->findLinkForUser($linkId, $userId);
        if (!is_array($link)) {
            $this->session->flash('dashboard_error', 'Link nicht gefunden.');
            return Response::redirect('/dashboard');
        }

        $widgetId = (int) ($link['widget_id'] ?? 0);
        $this->dashboard->deleteLink($linkId);
        $this->session->flash('dashboard_info', 'Link gelöscht.');
        return Response::redirect('/dashboard#widget-' . $widgetId);
    }

    public function serveFavicon(Request $request): Response
    {
        $user = $this->auth?->currentUser();
        if (!is_array($user)) {
            return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $path = $request->path();
        $prefix = '/dashboard/favicons/';
        if (!str_starts_with($path, $prefix)) {
            return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $fileName = trim(substr($path, strlen($prefix)));
        if (!preg_match('/^fav-[a-f0-9]{32}\.(png|ico)$/', $fileName)) {
            return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $storagePath = $this->storageFaviconDirPath() . '/' . $fileName;
        $tempPath = $this->tempFaviconDirPath() . '/' . $fileName;
        $publicPath = $this->publicFaviconDirPath() . '/' . $fileName;
        $sourcePath = null;
        if (is_file($storagePath) && is_readable($storagePath)) {
            $sourcePath = $storagePath;
        } elseif (is_file($tempPath) && is_readable($tempPath)) {
            $sourcePath = $tempPath;
        } elseif (is_file($publicPath) && is_readable($publicPath)) {
            // Kompatibilität: historisch konnten /dashboard-URLs auf Dateien zeigen,
            // die effektiv unter /public/assets/favicons lagen.
            $sourcePath = $publicPath;
        }

        if (!is_string($sourcePath)) {
            $this->logFaviconIssue('serve_favicon_not_found', [
                'file' => $fileName,
                'checked' => [$storagePath, $tempPath, $publicPath],
            ]);
            return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $binary = @file_get_contents($sourcePath);
        if (!is_string($binary) || $binary === '') {
            return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $contentType = str_ends_with($fileName, '.ico')
            ? 'image/x-icon'
            : 'image/png';

        return new Response($binary, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    private function normalizeWidgetWidth(int $width): int
    {
        return $width === 12 ? 12 : 6;
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function widgetResponse(bool $ok, string $message, int $status = 200, array $extra = []): Response
    {
        if ($this->isAjaxRequest()) {
            return $this->json(array_merge([
                'ok' => $ok,
                'message' => $message,
            ], $extra), $status);
        }

        $this->session->flash($ok ? 'dashboard_info' : 'dashboard_error', $message);
        return Response::redirect('/dashboard');
    }

    private function archiveAuthResponse(bool $wantsJson): Response
    {
        if ($wantsJson) {
            return $this->json(['ok' => false, 'message' => 'Nicht eingeloggt. Bitte Seite neu laden.'], 401);
        }

        $this->session->flash('dashboard_error', 'Bitte erneut einloggen.');
        return Response::redirect('/login');
    }

    private function archiveErrorResponse(bool $wantsJson, string $message, int $status): Response
    {
        if ($wantsJson) {
            return $this->json(['ok' => false, 'message' => $message], $status);
        }

        $this->session->flash('dashboard_error', $message);
        return Response::redirect('/dashboard');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getFormState(): array
    {
        $state = $this->session->get(self::FORM_STATE_KEY, []);
        return is_array($state) ? $state : [];
    }

    /**
     * Formularwerte nur einmalig anzeigen und danach wieder leeren.
     *
     * @return array<string, array<string, mixed>>
     */
    private function pullFormState(): array
    {
        $state = $this->getFormState();
        $this->session->remove(self::FORM_STATE_KEY);
        return $state;
    }

    private function json(array $payload, int $status = 200): Response
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            $json = '{"ok":false,"message":"JSON encoding failed."}';
            $status = 500;
        }

        return new Response($json, $status, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    private function requestWantsJson(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

        return str_contains($accept, 'application/json')
            || $requestedWith === 'xmlhttprequest'
            || str_contains($contentType, 'application/json');
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    private function normalizeDashboardAutoRefreshIntervalMinutes(mixed $value): ?int
    {
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '' || !preg_match('/^\d+$/', $value)) {
                return null;
            }
            $value = (int) $value;
        }

        if (!is_int($value)) {
            return null;
        }

        if ($value < self::DASHBOARD_AUTO_REFRESH_MIN_MINUTES || $value > self::DASHBOARD_AUTO_REFRESH_MAX_MINUTES) {
            return null;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function resolveDashboardAutoRefreshEnabled(array $user): bool
    {
        return (int) ($user['dashboard_auto_refresh_enabled'] ?? 1) === 1;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function resolveDashboardAutoRefreshIntervalMinutes(array $user): int
    {
        return $this->normalizeDashboardAutoRefreshIntervalMinutes($user['dashboard_auto_refresh_interval_minutes'] ?? null)
            ?? self::DASHBOARD_AUTO_REFRESH_DEFAULT_MINUTES;
    }

    private function isValidTime(string $time): bool
    {
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) === 1;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function taskPayloadFromRequest(Request $request): ?array
    {
        $title = trim((string) $request->input('title', ''));
        if ($title === '') {
            $this->session->flash('dashboard_error', 'Aufgabentitel ist erforderlich.');
            return null;
        }

        $details = trim((string) $request->input('details', ''));
        $details = $details === '' ? null : mb_substr($details, 0, 4000);

        $rawLink = trim((string) $request->input('link_url', ''));
        $linkUrl = null;
        if ($rawLink !== '') {
            $linkUrl = $this->normalizeHttpUrl($rawLink);
            if ($linkUrl === null) {
                $this->session->flash('dashboard_error', 'Aufgaben-Link muss eine gültige http/https URL sein.');
                return null;
            }
        }

        $isActive = $request->input('is_active', '0') === '1';
        $repeatType = strtolower(trim((string) $request->input('repeat_type', 'none')));
        if (!in_array($repeatType, ['none', 'daily', 'weekly', 'monthly'], true)) {
            $repeatType = 'none';
        }

        $repeatTime = trim((string) $request->input('repeat_time', ''));
        if ($repeatTime === '' || !$this->isValidTime($repeatTime)) {
            $repeatTime = '00:00';
        }

        $repeatWeekday = null;
        $repeatMonthMode = null;
        $repeatMonthDay = null;
        $repeatMonthOrdinal = null;
        $repeatMonthWeekday = null;

        if ($repeatType === 'weekly') {
            $repeatWeekdayRaw = (int) $request->input('repeat_weekday', '1');
            if ($repeatWeekdayRaw < 1 || $repeatWeekdayRaw > 7) {
                $repeatWeekdayRaw = 1;
            }
            $repeatWeekday = $repeatWeekdayRaw;
        }

        if ($repeatType === 'monthly') {
            $mode = strtolower(trim((string) $request->input('repeat_month_mode', 'first_day')));
            if (!in_array($mode, ['first_day', 'middle_day', 'last_day', 'fixed_day', 'ordinal_weekday'], true)) {
                $mode = 'first_day';
            }
            $repeatMonthMode = $mode;

            if ($mode === 'fixed_day') {
                $day = (int) $request->input('repeat_month_day', '1');
                if ($day < 1 || $day > 31) {
                    $day = 1;
                }
                $repeatMonthDay = $day;
            }

            if ($mode === 'ordinal_weekday') {
                $ordinal = (int) $request->input('repeat_month_ordinal', '1');
                if ($ordinal < 1 || $ordinal > 5) {
                    $ordinal = 1;
                }
                $weekday = (int) $request->input('repeat_month_weekday', '1');
                if ($weekday < 1 || $weekday > 7) {
                    $weekday = 1;
                }
                $repeatMonthOrdinal = $ordinal;
                $repeatMonthWeekday = $weekday;
            }
        }

        return [
            'title' => mb_substr($title, 0, 255),
            'details' => $details,
            'link_url' => $linkUrl,
            'priority' => 0,
            'due_at' => null,
            'is_active' => $isActive ? 1 : 0,
            'repeat_type' => $repeatType,
            'repeat_time' => $repeatType === 'none' ? null : $repeatTime,
            'repeat_weekday' => $repeatType === 'weekly' ? $repeatWeekday : null,
            'repeat_month_mode' => $repeatType === 'monthly' ? $repeatMonthMode : null,
            'repeat_month_day' => $repeatType === 'monthly' && $repeatMonthMode === 'fixed_day' ? $repeatMonthDay : null,
            'repeat_month_ordinal' => $repeatType === 'monthly' && $repeatMonthMode === 'ordinal_weekday' ? $repeatMonthOrdinal : null,
            'repeat_month_weekday' => $repeatType === 'monthly' && $repeatMonthMode === 'ordinal_weekday' ? $repeatMonthWeekday : null,
        ];
    }

    private function syncRecurringTasks(int $userId, DateTimeZone $taskTimezone): void
    {
        $rows = $this->dashboard->listRecurringDoneTasksForUser($userId);
        if ($rows === []) {
            return;
        }

        $now = new DateTimeImmutable('now', $taskTimezone);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $taskId = (int) ($row['id'] ?? 0);
            $doneAtRaw = (string) ($row['done_at'] ?? '');
            if ($taskId <= 0 || $doneAtRaw === '') {
                continue;
            }

            $doneAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $doneAtRaw, $this->appTimezone());
            if (!$doneAt instanceof DateTimeImmutable) {
                $doneAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $doneAtRaw, $taskTimezone);
            }
            if (!$doneAt instanceof DateTimeImmutable) {
                continue;
            }

            $nextReopenAt = $this->nextReopenAt($doneAt->setTimezone($taskTimezone), $row);
            if ($nextReopenAt !== null && $nextReopenAt <= $now) {
                $this->dashboard->reopenTask($taskId);
            }
        }
    }

    /**
     * @param array<string, mixed> $task
     */
    private function nextReopenAt(DateTimeImmutable $doneAt, array $task): ?DateTimeImmutable
    {
        $repeatType = strtolower((string) ($task['repeat_type'] ?? 'none'));
        if ($repeatType === 'none') {
            return null;
        }

        [$hour, $minute] = $this->parseRepeatTimeParts((string) ($task['repeat_time'] ?? '00:00'));

        if ($repeatType === 'daily') {
            $candidate = $doneAt->setTime($hour, $minute, 0);
            if ($candidate <= $doneAt) {
                $candidate = $candidate->modify('+1 day');
            }
            return $candidate;
        }

        if ($repeatType === 'weekly') {
            $targetWeekday = (int) ($task['repeat_weekday'] ?? 1);
            if ($targetWeekday < 1 || $targetWeekday > 7) {
                $targetWeekday = 1;
            }
            $currentWeekday = (int) $doneAt->format('N');
            $daysAhead = ($targetWeekday - $currentWeekday + 7) % 7;
            $candidate = $doneAt->setTime($hour, $minute, 0);
            if ($daysAhead > 0) {
                $candidate = $candidate->modify('+' . $daysAhead . ' day');
            }
            if ($candidate <= $doneAt) {
                $candidate = $candidate->modify('+7 day');
            }
            return $candidate;
        }

        if ($repeatType === 'monthly') {
            $mode = strtolower((string) ($task['repeat_month_mode'] ?? 'first_day'));
            $fixedDay = (int) ($task['repeat_month_day'] ?? 1);
            $ordinal = (int) ($task['repeat_month_ordinal'] ?? 1);
            $monthWeekday = (int) ($task['repeat_month_weekday'] ?? 1);
            $year = (int) $doneAt->format('Y');
            $month = (int) $doneAt->format('n');

            $candidate = $this->monthlyDate($year, $month, $mode, $fixedDay, $ordinal, $monthWeekday, $hour, $minute, $doneAt->getTimezone());
            if ($candidate === null) {
                return null;
            }

            if ($candidate <= $doneAt) {
                $nextMonth = $doneAt->modify('first day of next month');
                $candidate = $this->monthlyDate(
                    (int) $nextMonth->format('Y'),
                    (int) $nextMonth->format('n'),
                    $mode,
                    $fixedDay,
                    $ordinal,
                    $monthWeekday,
                    $hour,
                    $minute,
                    $doneAt->getTimezone()
                );
            }

            return $candidate;
        }

        return null;
    }

    /**
     * MySQL TIME wird oft als HH:MM:SS geliefert, Formulareingaben dagegen als HH:MM.
     *
     * @return array{0:int,1:int}
     */
    private function parseRepeatTimeParts(string $value): array
    {
        $raw = trim($value);
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $raw) !== 1) {
            return [0, 0];
        }

        $parts = explode(':', $raw);
        return [(int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0)];
    }

    private function monthlyDate(
        int $year,
        int $month,
        string $mode,
        int $fixedDay,
        int $ordinal,
        int $weekday,
        int $hour,
        int $minute,
        DateTimeZone $timezone
    ): ?DateTimeImmutable {
        if (function_exists('cal_days_in_month')) {
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        } else {
            $daysInMonth = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), $timezone))->format('t');
        }
        if ($mode === 'ordinal_weekday') {
            $day = $this->ordinalWeekdayDayOfMonth($year, $month, $ordinal, $weekday, $timezone);
        } else {
            $day = match ($mode) {
                'first_day' => 1,
                'middle_day' => 15,
                'last_day' => $daysInMonth,
                'fixed_day' => max(1, min($fixedDay, $daysInMonth)),
                default => 1,
            };
        }

        $date = sprintf('%04d-%02d-%02d %02d:%02d:00', $year, $month, $day, $hour, $minute);
        $result = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date, $timezone);

        return $result instanceof DateTimeImmutable ? $result : null;
    }

    private function ordinalWeekdayDayOfMonth(
        int $year,
        int $month,
        int $ordinal,
        int $weekday,
        DateTimeZone $timezone
    ): int {
        $ordinal = max(1, min($ordinal, 5));
        $weekday = max(1, min($weekday, 7));
        $first = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), $timezone);
        $firstWeekday = (int) $first->format('N');
        $offset = ($weekday - $firstWeekday + 7) % 7;
        $day = 1 + $offset + (($ordinal - 1) * 7);
        $daysInMonth = (int) $first->format('t');

        if ($day > $daysInMonth) {
            $day -= 7;
        }

        return max(1, $day);
    }

    private function appTimezone(): DateTimeZone
    {
        $name = (string) date_default_timezone_get();
        try {
            return new DateTimeZone($name);
        } catch (\Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    private function resolveTaskTimezone(array $user): DateTimeZone
    {
        if ($this->auth instanceof AuthService) {
            return $this->auth->resolveUserTimezone($user);
        }

        return $this->appTimezone();
    }

    private function normalizeHttpUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (!preg_match('#^[a-z][a-z0-9+\-.]*://#i', $url)) {
            $url = 'https://' . $url;
        }

        $parsed = parse_url($url);
        if (!is_array($parsed)) {
            return null;
        }

        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = (string) ($parsed['host'] ?? '');
        if ($host === '' || $this->isBlockedHost($host)) {
            return null;
        }

        $validated = filter_var($url, FILTER_VALIDATE_URL);
        if (!is_string($validated)) {
            return null;
        }

        return $validated;
    }

    private function isBlockedHost(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return true;
        }

        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        $resolved = gethostbynamel($host);
        if ($resolved === false || $resolved === []) {
            return false;
        }

        foreach ($resolved as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{title:string,favicon_url:string}
     */
    private function extractUrlMetadata(string $url): array
    {
        $html = $this->fetchHtml($url);
        $title = 'Neuer Link';
        $favicon = $this->detectFaviconUrlForForm($url, $html) ?? '';

        if ($html !== null) {
            $detectedTitle = $this->extractTitleFromHtml($html);
            if ($detectedTitle !== '') {
                $title = $detectedTitle;
            }
        }

        return [
            'title' => $this->sanitizeTitle($title),
            'favicon_url' => $favicon,
        ];
    }

    private function fetchHtml(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => self::HTTP_MAX_REDIRECTS,
                    CURLOPT_CONNECTTIMEOUT => self::HTTP_CONNECT_TIMEOUT_SECONDS,
                    CURLOPT_TIMEOUT => self::HTTP_TIMEOUT_SECONDS,
                    CURLOPT_USERAGENT => 'Modulon-Dashboard-LinkBot/1.0',
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);
                $body = curl_exec($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

                if (is_string($body) && $httpCode >= 200 && $httpCode < 400 && str_contains(strtolower($contentType), 'text/html')) {
                    return mb_substr($body, 0, self::MAX_HTML_BYTES);
                }
            }
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::HTTP_TIMEOUT_SECONDS,
                'follow_location' => 1,
                'max_redirects' => self::HTTP_MAX_REDIRECTS,
                'header' => "User-Agent: Modulon-Dashboard-LinkBot/1.0\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if (!is_string($body) || $body === '') {
            return null;
        }

        return mb_substr($body, 0, self::MAX_HTML_BYTES);
    }

    private function extractTitleFromHtml(string $html): string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches) !== 1) {
            return '';
        }

        $raw = html_entity_decode(strip_tags((string) ($matches[1] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $this->sanitizeTitle($raw);
    }

    private function sanitizeTitle(string $title): string
    {
        $title = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = preg_replace('/\s+/u', ' ', trim($title));
        if (!is_string($title) || $title === '') {
            return '';
        }

        return mb_substr($title, 0, 180);
    }

    private function detectFaviconUrlForForm(string $pageUrl, ?string $html): ?string
    {
        $candidates = $html !== null ? $this->extractFaviconCandidates($html, $pageUrl) : [];

        foreach ($candidates as $candidate) {
            $candidateUrl = (string) ($candidate['url'] ?? '');
            if ($candidateUrl === '') {
                continue;
            }
            $absolute = $this->normalizeHttpUrl($candidateUrl);
            if ($absolute !== null) {
                return $absolute;
            }
        }

        $fallback = $this->fallbackFaviconUrl($pageUrl);
        if ($fallback !== null) {
            return $fallback;
        }

        return null;
    }

    /**
     * @return array<int, array{url:string,priority:int,size:int}>
     */
    private function extractFaviconCandidates(string $html, string $pageUrl): array
    {
        $candidates = [];
        if (preg_match_all('/<link[^>]+>/i', $html, $matches) > 0) {
            foreach ($matches[0] as $tag) {
                if (!is_string($tag)) {
                    continue;
                }

                $rel = $this->extractHtmlAttribute($tag, 'rel');
                $href = $this->extractHtmlAttribute($tag, 'href');
                if ($href === null || $rel === null) {
                    continue;
                }

                $relLower = strtolower($rel);
                $isIconRel = str_contains($relLower, 'icon')
                    || str_contains($relLower, 'shortcut icon')
                    || str_contains($relLower, 'apple-touch-icon')
                    || str_contains($relLower, 'apple-touch-icon-precomposed');
                if (!$isIconRel) {
                    continue;
                }

                $absoluteHref = $this->toAbsoluteUrl($href, $pageUrl);
                if ($absoluteHref === null) {
                    continue;
                }

                $type = strtolower((string) ($this->extractHtmlAttribute($tag, 'type') ?? ''));
                $size = $this->extractIconSize((string) ($this->extractHtmlAttribute($tag, 'sizes') ?? ''));
                $isTouch = str_contains($relLower, 'apple-touch');

                $priority = 4;
                if (str_contains($type, 'png') || str_ends_with(strtolower((string) parse_url($absoluteHref, PHP_URL_PATH)), '.png')) {
                    $priority = $size >= 128 * 128 ? 1 : 2;
                } elseif (str_contains($type, 'svg') || str_ends_with(strtolower((string) parse_url($absoluteHref, PHP_URL_PATH)), '.svg')) {
                    $priority = 2;
                } elseif ($isTouch || $size >= 144 * 144) {
                    $priority = 2;
                } elseif (str_contains($type, 'icon') || str_ends_with(strtolower((string) parse_url($absoluteHref, PHP_URL_PATH)), '.ico')) {
                    $priority = 5;
                }

                $candidates[] = [
                    'url' => $absoluteHref,
                    'priority' => $priority,
                    'size' => $size,
                ];
            }
        }

        if (preg_match_all('/<meta[^>]+>/i', $html, $metaMatches) > 0) {
            foreach ($metaMatches[0] as $metaTag) {
                if (!is_string($metaTag)) {
                    continue;
                }
                $name = strtolower((string) ($this->extractHtmlAttribute($metaTag, 'name') ?? ''));
                if ($name !== 'msapplication-tileimage') {
                    continue;
                }

                $content = $this->extractHtmlAttribute($metaTag, 'content');
                if ($content === null) {
                    continue;
                }
                $absoluteHref = $this->toAbsoluteUrl($content, $pageUrl);
                if ($absoluteHref === null) {
                    continue;
                }

                $size = 270 * 270;
                $priority = 2;
                $candidates[] = [
                    'url' => $absoluteHref,
                    'priority' => $priority,
                    'size' => $size,
                ];
            }
        }

        $dedup = [];
        $normalized = [];
        foreach ($candidates as $candidate) {
            $url = (string) ($candidate['url'] ?? '');
            if ($url === '' || isset($dedup[$url])) {
                continue;
            }
            $dedup[$url] = true;
            $normalized[] = $candidate;
        }
        $candidates = $normalized;

        usort($candidates, static function (array $a, array $b): int {
            $priorityCompare = ((int) $a['priority']) <=> ((int) $b['priority']);
            if ($priorityCompare !== 0) {
                return $priorityCompare;
            }

            return ((int) $b['size']) <=> ((int) $a['size']);
        });

        return $candidates;
    }

    private function fallbackFaviconUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = (string) ($parts['scheme'] ?? '');
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        if ($scheme === '' || $host === '') {
            return null;
        }

        return $this->normalizeHttpUrl($scheme . '://' . $host . $port . '/favicon.ico');
    }

    private function extractIconSize(string $sizes): int
    {
        if ($sizes === '') {
            return 0;
        }

        if (preg_match_all('/(\d+)\s*x\s*(\d+)/i', $sizes, $matches) < 1) {
            return 0;
        }

        $max = 0;
        $count = count($matches[1] ?? []);
        for ($i = 0; $i < $count; $i++) {
            $w = (int) ($matches[1][$i] ?? 0);
            $h = (int) ($matches[2][$i] ?? 0);
            $max = max($max, $w * $h);
        }

        return $max;
    }

    private function extractHtmlAttribute(string $tag, string $attribute): ?string
    {
        $pattern = '/\b' . preg_quote($attribute, '/') . '\s*=\s*([\'"])(.*?)\1/i';
        if (preg_match($pattern, $tag, $matches) !== 1) {
            return null;
        }

        $value = html_entity_decode((string) ($matches[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function toAbsoluteUrl(string $candidate, string $baseUrl): ?string
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return null;
        }

        if (str_starts_with($candidate, '//')) {
            $scheme = (string) parse_url($baseUrl, PHP_URL_SCHEME);
            return $this->normalizeHttpUrl($scheme . ':' . $candidate);
        }

        if (preg_match('#^[a-z][a-z0-9+\-.]*://#i', $candidate)) {
            return $this->normalizeHttpUrl($candidate);
        }

        $base = parse_url($baseUrl);
        if (!is_array($base)) {
            return null;
        }

        $scheme = (string) ($base['scheme'] ?? '');
        $host = (string) ($base['host'] ?? '');
        $port = isset($base['port']) ? ':' . (int) $base['port'] : '';
        if ($scheme === '' || $host === '') {
            return null;
        }

        if (str_starts_with($candidate, '/')) {
            return $this->normalizeHttpUrl($scheme . '://' . $host . $port . $candidate);
        }

        $path = (string) ($base['path'] ?? '/');
        $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
        $dir = $dir === '' ? '' : $dir;
        $absolutePath = $dir . '/' . ltrim($candidate, '/');

        return $this->normalizeHttpUrl($scheme . '://' . $host . $port . $absolutePath);
    }

    private function downloadAndStoreFavicon(string $sourceUrl, ?string $originHost = null): ?string
    {
        $safeUrl = $this->normalizeHttpUrl($sourceUrl);
        if ($safeUrl === null) {
            $this->logFaviconIssue('invalid_source_url', ['source_url' => $sourceUrl]);
            return null;
        }

        $originHost = is_string($originHost) ? strtolower(trim($originHost)) : '';
        $download = $this->fetchBinary($safeUrl, self::MAX_IMAGE_BYTES);
        if (!is_array($download)) {
            $this->logFaviconIssue('download_failed', ['source_url' => $safeUrl]);
            return null;
        }

        $body = (string) ($download['body'] ?? '');
        $finalUrl = (string) ($download['final_url'] ?? $safeUrl);
        $contentType = strtolower((string) ($download['content_type'] ?? ''));
        if ($body === '') {
            $this->logFaviconIssue('empty_body', ['source_url' => $safeUrl, 'final_url' => $finalUrl]);
            return null;
        }

        $finalHost = strtolower((string) parse_url($finalUrl, PHP_URL_HOST));
        if ($finalHost === '' || $this->isBlockedHost($finalHost)) {
            $this->logFaviconIssue('blocked_final_host', ['final_url' => $finalUrl, 'final_host' => $finalHost]);
            return null;
        }

        $pngData = $this->reencodeToPng($body);
        if ($pngData !== null) {
            $localPng = $this->storeFaviconPng($pngData, $finalUrl);
            if ($localPng !== null) {
                return $localPng;
            }

            $this->logFaviconIssue('png_store_failed', ['final_url' => $finalUrl]);
        }

        if ($this->isIcoPayload($body, $contentType, $finalUrl)) {
            $localIco = $this->storeRawFavicon($body, $finalUrl, 'ico');
            if ($localIco !== null) {
                return $localIco;
            }
            $this->logFaviconIssue('ico_store_failed', ['final_url' => $finalUrl]);
        }

        $this->logFaviconIssue('unsupported_or_invalid_favicon', [
            'final_url' => $finalUrl,
            'content_type' => $contentType,
            'bytes' => strlen($body),
            'origin_host' => $originHost,
        ]);
        return null;
    }

    private function hostFromUrl(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * @return array{body:string,final_url:string,content_type:string}|null
     */
    private function fetchBinary(string $url, int $maxBytes): ?array
    {
        $currentUrl = $url;
        for ($redirect = 0; $redirect <= self::HTTP_MAX_REDIRECTS; $redirect++) {
            $result = $this->fetchBinaryOnce($currentUrl, $maxBytes);
            if (!is_array($result)) {
                return null;
            }

            $status = (int) ($result['status'] ?? 0);
            $location = (string) ($result['location'] ?? '');
            if ($status >= 300 && $status < 400 && $location !== '') {
                $nextUrl = $this->toAbsoluteUrl($location, $currentUrl);
                if ($nextUrl === null) {
                    return null;
                }
                $currentUrl = $nextUrl;
                continue;
            }

            if ($status < 200 || $status >= 300) {
                return null;
            }

            return [
                'body' => (string) ($result['body'] ?? ''),
                'final_url' => $currentUrl,
                'content_type' => (string) ($result['content_type'] ?? ''),
            ];
        }

        return null;
    }

    private function isAjaxRequest(): bool
    {
        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        if ($requestedWith === 'xmlhttprequest') {
            return true;
        }

        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        return str_contains($accept, 'application/json');
    }

    /**
     * @return array{status:int,body:string,content_type:string,location:string}|null
     */
    private function fetchBinaryOnce(string $url, int $maxBytes): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        $headers = [];
        $body = '';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => self::HTTP_CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::HTTP_TIMEOUT_SECONDS,
            CURLOPT_USERAGENT => 'Modulon-FaviconFetcher/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$headers): int {
                $len = strlen($headerLine);
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $len;
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, $maxBytes): int {
                $newLen = strlen($body) + strlen($chunk);
                if ($newLen > $maxBytes) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        if ($ok === false) {
            return null;
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

        return [
            'status' => $status,
            'body' => $body,
            'content_type' => $contentType,
            'location' => (string) ($headers['location'] ?? ''),
        ];
    }

    private function reencodeToPng(string $binary): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }

        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return null;
        }

        $target = imagecreatetruecolor(self::FAVICON_OUTPUT_SIZE, self::FAVICON_OUTPUT_SIZE);
        if ($target === false) {
            imagedestroy($image);
            return null;
        }

        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
        imagefilledrectangle($target, 0, 0, self::FAVICON_OUTPUT_SIZE, self::FAVICON_OUTPUT_SIZE, $transparent);

        $srcW = imagesx($image);
        $srcH = imagesy($image);
        if ($srcW <= 0 || $srcH <= 0) {
            imagedestroy($image);
            imagedestroy($target);
            return null;
        }

        imagecopyresampled($target, $image, 0, 0, 0, 0, self::FAVICON_OUTPUT_SIZE, self::FAVICON_OUTPUT_SIZE, $srcW, $srcH);
        imagedestroy($image);

        ob_start();
        imagepng($target, null, 6);
        $png = ob_get_clean();
        imagedestroy($target);

        return is_string($png) && $png !== '' ? $png : null;
    }

    private function storeFaviconPng(string $pngData, string $sourceUrl): ?string
    {
        $hash = hash('sha256', $sourceUrl . '|' . $pngData);
        $fileName = 'fav-' . substr($hash, 0, 32) . '.png';
        $targets = $this->faviconTargetsForFile($fileName);
        $storedUrl = null;
        foreach ($targets as $target) {
            $path = (string) ($target['path'] ?? '');
            $url = (string) ($target['url'] ?? '');
            if ($path === '' || $url === '') {
                continue;
            }
            if ($this->storeBinaryFile($path, $pngData)) {
                $storedUrl ??= $url;
            }
        }

        if (is_string($storedUrl)) {
            return $storedUrl;
        }

        $this->logFaviconIssue('favicon_store_all_targets_failed', ['file_name' => $fileName, 'targets' => $targets]);
        return null;
    }

    private function storeRawFavicon(string $binaryData, string $sourceUrl, string $extension): ?string
    {
        $extension = strtolower(trim($extension));
        if (!in_array($extension, ['ico'], true)) {
            return null;
        }

        $hash = hash('sha256', $sourceUrl . '|' . $binaryData);
        $fileName = 'fav-' . substr($hash, 0, 32) . '.' . $extension;
        $targets = $this->faviconTargetsForFile($fileName);
        $storedUrl = null;
        foreach ($targets as $target) {
            $path = (string) ($target['path'] ?? '');
            $url = (string) ($target['url'] ?? '');
            if ($path === '' || $url === '') {
                continue;
            }
            if ($this->storeBinaryFile($path, $binaryData)) {
                $storedUrl ??= $url;
            }
        }

        if (is_string($storedUrl)) {
            return $storedUrl;
        }

        $this->logFaviconIssue('favicon_raw_store_all_targets_failed', ['file_name' => $fileName, 'targets' => $targets]);
        return null;
    }

    /**
     * @return array<int, array{path:string,url:string}>
     */
    private function faviconTargetsForFile(string $fileName): array
    {
        return [
            [
                'path' => $this->storageFaviconDirPath() . '/' . $fileName,
                'url' => '/dashboard/favicons/' . $fileName,
            ],
        ];
    }

    private function storeBinaryFile(string $targetPath, string $binaryData): bool
    {
        $directory = dirname($targetPath);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            $this->logFaviconIssue('favicon_store_mkdir_failed', ['directory' => $directory]);
            return false;
        }

        if (is_file($targetPath)) {
            $size = @filesize($targetPath);
            if (is_readable($targetPath) && is_int($size) && $size > 0) {
                return true;
            }

            $this->logFaviconIssue('favicon_store_existing_invalid', [
                'target' => $targetPath,
                'size' => $size,
                'readable' => is_readable($targetPath),
            ]);
        }

        if (!is_writable($directory)) {
            $this->logFaviconIssue('favicon_store_directory_not_writable', ['directory' => $directory]);
            return false;
        }

        $bytes = @file_put_contents($targetPath, $binaryData, LOCK_EX);
        if (!is_int($bytes) || $bytes <= 0) {
            $this->logFaviconIssue('favicon_store_write_failed', [
                'target' => $targetPath,
                'bytes' => $bytes,
            ]);
            return false;
        }

        return true;
    }

    private function publicFaviconDirPath(): string
    {
        return rtrim($this->basePath, '/') . '/public/assets/favicons';
    }

    private function storageFaviconDirPath(): string
    {
        return rtrim($this->basePath, '/') . '/storage/modules/modulnest.dashboard/favicons';
    }

    private function tempFaviconDirPath(): string
    {
        return rtrim((string) sys_get_temp_dir(), '/\\') . '/modulon-favicons';
    }

    private function isIcoPayload(string $binaryData, string $contentType, string $sourceUrl): bool
    {
        if (strlen($binaryData) < 6) {
            return false;
        }

        $icoHeader = substr($binaryData, 0, 4);
        $looksLikeIco = $icoHeader === "\x00\x00\x01\x00" || $icoHeader === "\x00\x00\x02\x00";
        if (!$looksLikeIco) {
            return false;
        }

        if (str_contains($contentType, 'icon') || str_contains($contentType, 'ico')) {
            return true;
        }

        $path = strtolower((string) parse_url($sourceUrl, PHP_URL_PATH));
        return str_ends_with($path, '.ico');
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logFaviconIssue(string $reason, array $context = []): void
    {
        $record = [
            'timestamp' => date('c'),
            'reason' => $reason,
            // Query-Parameter können Tokens enthalten; für Favicon-Diagnosen
            // genügt der Pfad vollständig.
            'request_uri' => (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: ''),
            'context' => $context,
        ];

        (new \Modulon\Core\RotatingFileLogger($this->basePath))->write('favicon', $record);
    }

    private function handleFaviconUpload(int $userId): ?string
    {
        $file = $_FILES['favicon_file'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_OK);
        if ($uploadError !== UPLOAD_ERR_OK) {
            $this->session->flash('dashboard_error', 'Favicon-Upload fehlgeschlagen: ' . $this->uploadErrorMessage($uploadError));
            $this->logFaviconIssue('upload_error_code', [
                'error_code' => $uploadError,
                'name' => (string) ($file['name'] ?? ''),
                'size' => (int) ($file['size'] ?? 0),
            ]);
            return null;
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '') {
            $this->session->flash('dashboard_error', 'Favicon-Upload fehlgeschlagen: Temporaere Datei fehlt.');
            $this->logFaviconIssue('upload_tmp_missing', ['name' => (string) ($file['name'] ?? '')]);
            return null;
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            $this->session->flash('dashboard_error', 'Favicon-Datei ist zu groß (max. 1 MB).');
            $this->logFaviconIssue('upload_size_invalid', ['size' => $size]);
            return null;
        }

        if (!is_file($tmpPath) || !is_readable($tmpPath)) {
            $this->session->flash('dashboard_error', 'Favicon-Upload fehlgeschlagen: Upload-Datei ist nicht lesbar.');
            $this->logFaviconIssue('upload_tmp_unreadable', ['tmp_name' => $tmpPath]);
            return null;
        }

        $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
        $mimeType = $finfo ? (string) finfo_file($finfo, $tmpPath) : '';
        if ($finfo) {
            finfo_close($finfo);
        }

        $imageInfo = @getimagesize($tmpPath);
        $imageType = is_array($imageInfo) ? (int) ($imageInfo[2] ?? 0) : 0;
        $allowedMime = ['image/jpeg', 'image/png'];
        $allowedType = [IMAGETYPE_JPEG, IMAGETYPE_PNG];
        if (!in_array(strtolower($mimeType), $allowedMime, true) || !in_array($imageType, $allowedType, true)) {
            $this->session->flash('dashboard_error', 'Nur gültige JPEG- oder PNG-Dateien sind als Upload erlaubt.');
            $this->logFaviconIssue('upload_type_invalid', [
                'mime' => $mimeType,
                'image_type' => $imageType,
                'name' => (string) ($file['name'] ?? ''),
            ]);
            return null;
        }

        $binary = @file_get_contents($tmpPath);
        if (!is_string($binary) || $binary === '') {
            $this->session->flash('dashboard_error', 'Favicon-Datei konnte nicht gelesen werden.');
            $this->logFaviconIssue('upload_read_failed', ['tmp_name' => $tmpPath, 'mime' => $mimeType]);
            return null;
        }

        $pngData = $this->reencodeToPng($binary);
        if ($pngData === null) {
            $this->session->flash('dashboard_error', 'Favicon konnte nicht verarbeitet werden. Bitte JPEG oder PNG verwenden.');
            $this->logFaviconIssue('upload_reencode_failed', [
                'mime' => $mimeType,
                'name' => (string) ($file['name'] ?? ''),
            ]);
            return null;
        }

        $local = $this->storeFaviconPng($pngData, 'upload:' . $userId . ':' . hash('sha256', $pngData));
        if ($local === null) {
            $this->session->flash('dashboard_error', 'Favicon-Datei konnte nicht gespeichert werden.');
            $this->logFaviconIssue('upload_store_failed', [
                'name' => (string) ($file['name'] ?? ''),
                'mime' => $mimeType,
            ]);
            return null;
        }

        return $local;
    }

    private function wasUploadAttempted(string $field): bool
    {
        $file = $_FILES[$field] ?? null;
        if (!is_array($file)) {
            return false;
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        return $error !== UPLOAD_ERR_NO_FILE;
    }

    private function normalizeTextareaHeight(mixed $value): ?int
    {
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '' || !preg_match('/^\d+$/', $value)) {
                return null;
            }
            $value = (int) $value;
        }
        if (!is_int($value)) {
            return null;
        }

        return max(120, min(1400, $value));
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Datei ist zu groß.',
            UPLOAD_ERR_PARTIAL => 'Datei wurde nur teilweise hochgeladen.',
            UPLOAD_ERR_NO_TMP_DIR => 'Temporaeres Verzeichnis fehlt.',
            UPLOAD_ERR_CANT_WRITE => 'Datei konnte nicht geschrieben werden.',
            UPLOAD_ERR_EXTENSION => 'Upload wurde durch eine PHP-Erweiterung gestoppt.',
            default => 'Unbekannter Upload-Fehler.',
        };
    }
}
