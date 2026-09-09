<?php

declare(strict_types=1);

namespace ModulNest\Tools;

use DateTimeImmutable;
use DateTimeZone;
use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Session;
use Modulon\Core\View;
use Modulon\Modules\Auth\AuthService;
use Throwable;

final class ToolsController
{
    public function __construct(
        private readonly ToolsRegistry $registry,
        private readonly ToolsSubnavigationProvider $navigation,
        private readonly ToolsNetworkService $network,
        private readonly ToolsSpeechService $speech,
        private readonly Session $session,
        private readonly ?AuthService $auth = null,
    ) {
    }

    public function index(Request $request): Response
    {
        $tools = $this->registry->publicTools();
        $path = rtrim($request->path(), '/') ?: '/';
        $selectedTool = null;

        if ($path !== '/tools') {
            if (!preg_match('#^/tools/([^/]+)$#', $path, $matches)) {
                return $this->publicNotFound($request);
            }

            foreach ($tools as $tool) {
                if (($tool['key'] ?? '') === $matches[1]) {
                    $selectedTool = $tool;
                    break;
                }
            }

            if ($selectedTool === null) {
                return $this->publicNotFound($request);
            }
        }

        return new Response(View::render('@modulnest.tools/index', [
            'title' => $selectedTool !== null ? (string) ($selectedTool['label'] ?? 'Tool') : 'Tools',
            'current_path' => $request->path(),
            'module_nav_items' => $this->navigation->items($request->path()),
            'module_nav_label' => 'Tools-Navigation',
            'tools' => $tools,
            'tool_groups' => $this->registry->grouped($tools),
            'selected_tool' => $selectedTool,
            'auth' => $this->authData(),
        ]));
    }

    public function adminIndex(Request $request): Response
    {
        $tools = $this->registry->adminTools();
        $requirements = $this->speech->requirements();
        $speechJobs = [];
        $speechError = '';
        try {
            $speechJobs = $this->formatSpeechJobs($this->speech->listJobs());
        } catch (Throwable $exception) {
            $speechError = $exception->getMessage();
        }

        return new Response(View::render('@modulnest.tools/admin', [
            'title' => 'Admin-Tools',
            'current_path' => $request->path(),
            'admin_section' => 'tools',
            'tools' => $tools,
            'tool_groups' => $this->registry->grouped($tools),
            'speech_requirements' => $requirements,
            'speech_models' => $this->speech->availableModels(),
            'speech_jobs' => $speechJobs,
            'speech_error' => $speechError,
            'tools_message' => $this->session->pullFlash('tools_info'),
            'tools_error' => $this->session->pullFlash('tools_error'),
            'speech_languages' => $this->speech->supportedLanguages(),
            'auth' => $this->authData(),
        ]));
    }

    public function networkAction(Request $request): Response
    {
        $tool = (string) $request->input('tool', '');
        if (!$this->consumeRateLimit('network:' . $tool, 30, 60)) {
            if (!$this->wantsJson()) {
                $this->session->flash('tools_error', 'Rate-Limit erreicht. Bitte kurz warten.');
                return Response::redirect('/admin/tools');
            }
            return $this->json(['ok' => false, 'summary' => 'Rate-Limit erreicht. Bitte kurz warten.'], 429);
        }

        $input = [
            'host' => $request->input('host', ''),
            'ip' => $request->input('ip', ''),
            'url' => $request->input('url', ''),
            'port' => $request->input('port', ''),
            'record_type' => $request->input('record_type', 'A'),
            'selector' => $request->input('selector', ''),
        ];

        $result = $this->network->run($tool, $input);
        if (!$this->wantsJson()) {
            $this->session->flash($result['ok'] ? 'tools_info' : 'tools_error', (string) ($result['summary'] ?? 'Tool ausgeführt.'));
            return Response::redirect('/admin/tools');
        }

        return $this->json($result);
    }

    public function speechUpload(Request $request): Response
    {
        if (!$this->consumeRateLimit('speech-upload', 5, 60)) {
            if (!$this->wantsJson()) {
                $this->session->flash('tools_error', 'Rate-Limit erreicht. Bitte kurz warten.');
                return Response::redirect('/admin/tools');
            }
            return $this->json(['ok' => false, 'summary' => 'Rate-Limit erreicht. Bitte kurz warten.'], 429);
        }

        try {
            $file = is_array($_FILES['audio_file'] ?? null) ? $_FILES['audio_file'] : [];
            $result = $this->speech->createUploadJob($file, [
                'language' => (string) $request->input('language', 'de'),
                'model_path' => (string) $request->input('model_path', ''),
            ]);
            if (!$this->wantsJson()) {
                $this->session->flash($result['ok'] ? 'tools_info' : 'tools_error', (string) ($result['summary'] ?? 'Speech-to-Text gestartet.'));
                return Response::redirect('/admin/tools');
            }

            return $this->json($result);
        } catch (Throwable $exception) {
            if (!$this->wantsJson()) {
                $this->session->flash('tools_error', $exception->getMessage());
                return Response::redirect('/admin/tools');
            }
            return $this->json([
                'ok' => false,
                'title' => 'Speech-to-Text',
                'summary' => $exception->getMessage(),
            ], 400);
        }
    }

    public function speechStatus(Request $request): Response
    {
        $jobId = (string) $request->query('job', '');
        if ($jobId !== '') {
            $job = $this->formatSpeechJob($this->speech->loadPublicJob($jobId));
            return $this->json([
                'ok' => $job !== [],
                'job' => $job,
                'jobs' => $this->formatSpeechJobs($this->speech->listJobs()),
            ], $job !== [] ? 200 : 404);
        }

        return $this->json([
            'ok' => true,
            'jobs' => $this->formatSpeechJobs($this->speech->listJobs()),
            'requirements' => $this->speech->requirements(),
        ]);
    }

    public function speechDelete(Request $request): Response
    {
        $jobId = (string) $request->input('job_id', '');
        try {
            $deleted = $this->speech->deleteJob($jobId);
            $this->session->flash(
                $deleted ? 'tools_info' : 'tools_error',
                $deleted ? 'Speech-to-Text-Job wurde gelöscht.' : 'Speech-to-Text-Job wurde nicht gefunden.'
            );
        } catch (Throwable $exception) {
            $this->session->flash('tools_error', $exception->getMessage());
        }

        return Response::redirect('/admin/tools');
    }

    public function speechDownload(Request $request): Response
    {
        try {
            $file = $this->speech->downloadFile(
                (string) $request->query('job', ''),
                (string) $request->query('format', '')
            );
            $content = file_get_contents($file['path']);
            if (!is_string($content)) {
                throw new \RuntimeException('Datei konnte nicht gelesen werden.');
            }

            return new Response($content, 200, [
                'Content-Type' => $file['content_type'],
                'Content-Disposition' => 'attachment; filename="' . str_replace('"', '', $file['filename']) . '"',
            ]);
        } catch (Throwable) {
            return new Response(View::render('errors/404', [
                'title' => '404 Not Found',
                'current_path' => $request->path(),
                'auth' => $this->authData(),
            ]), 404);
        }
    }

    private function publicNotFound(Request $request): Response
    {
        return new Response(View::render('errors/404', [
            'title' => '404 Not Found',
            'current_path' => $request->path(),
            'auth' => $this->authData(),
        ]), 404);
    }

    private function consumeRateLimit(string $key, int $limit, int $windowSeconds): bool
    {
        $now = time();
        $bucketKey = 'tools_rate_limit';
        $bucket = $this->session->get($bucketKey, []);
        $bucket = is_array($bucket) ? $bucket : [];
        $entries = is_array($bucket[$key] ?? null) ? $bucket[$key] : [];
        $entries = array_values(array_filter($entries, static fn (mixed $timestamp): bool => is_int($timestamp) && $timestamp >= ($now - $windowSeconds)));
        if (count($entries) >= $limit) {
            $bucket[$key] = $entries;
            $this->session->set($bucketKey, $bucket);
            return false;
        }

        $entries[] = $now;
        $bucket[$key] = $entries;
        $this->session->set($bucketKey, $bucket);

        return true;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200): Response
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return new Response(is_string($json) ? $json : '{}', $status, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    private function wantsJson(): bool
    {
        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));

        return $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');
    }

    /**
     * @param array<int, array<string, mixed>> $jobs
     * @return array<int, array<string, mixed>>
     */
    private function formatSpeechJobs(array $jobs): array
    {
        return array_map(fn (array $job): array => $this->formatSpeechJob($job), $jobs);
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function formatSpeechJob(array $job): array
    {
        if ($job === []) {
            return [];
        }

        foreach (['created_at', 'started_at', 'finished_at', 'updated_at'] as $field) {
            $job[$field . '_local'] = $this->formatTimestamp((string) ($job[$field] ?? ''));
        }
        $job['timezone_name'] = $this->userTimezone()->getName();

        return $job;
    }

    private function formatTimestamp(string $timestamp): string
    {
        if (trim($timestamp) === '') {
            return '';
        }

        try {
            return (new DateTimeImmutable($timestamp))->setTimezone($this->userTimezone())->format('d.m.Y H:i:s');
        } catch (Throwable) {
            return $timestamp;
        }
    }

    private function userTimezone(): DateTimeZone
    {
        try {
            return $this->auth?->resolveUserTimezone() ?? new DateTimeZone(date_default_timezone_get());
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    /**
     * @return array{is_authenticated:bool,is_admin:bool,user_name:string}
     */
    private function authData(): array
    {
        $user = $this->auth?->currentUser();

        return [
            'is_authenticated' => $user !== null,
            'is_admin' => $this->auth?->isAdmin() ?? false,
            'user_name' => (string) ($user['name'] ?? ''),
        ];
    }
}
