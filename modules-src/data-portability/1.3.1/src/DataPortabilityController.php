<?php

declare(strict_types=1);

namespace ModulNest\DataPortability;

use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Session;
use Modulon\Core\View;
use Modulon\Modules\Auth\AuthService;
use RuntimeException;
use Throwable;

final class DataPortabilityController
{
    private const ADMIN_PREVIEW_KEY = 'data_portability_import_preview';
    private const ADMIN_IMPORT_TOKEN_KEY = 'data_portability_import_token';
    private const USER_PREVIEW_KEY = 'data_portability_user_import_preview';
    private const USER_IMPORT_TOKEN_KEY = 'data_portability_user_import_token';

    public function __construct(
        private readonly DataPortabilityService $service,
        private readonly Session $session,
        private readonly ?AuthService $auth = null,
        private readonly bool $fantasyCardsProfileAvailable = false,
    ) {
    }

    public function index(Request $request): Response
    {
        return new Response(View::render('@modulnest.data-portability/admin', [
            'title' => 'Export / Import',
            'current_path' => $request->path(),
            'admin_section' => 'data-portability',
            'providers' => $this->viewProviders('admin'),
            'message' => $this->session->pullFlash('data_portability_info'),
            'error' => $this->session->pullFlash('data_portability_error'),
            'preview' => $this->session->get(self::ADMIN_PREVIEW_KEY),
            'import_mode' => $this->previewImportMode(self::ADMIN_PREVIEW_KEY),
            'target_user' => $this->currentUser(),
        ]));
    }

    public function userIndex(Request $request): Response
    {
        $user = $this->currentUser();

        return new Response(View::render('user/area', $this->baseViewData($request, [
            'title' => 'Profil / Meine Daten',
            'user_tab' => 'data-portability',
            'profile_user' => $user,
            'fantasy_cards_profile_available' => $this->fantasyCardsProfileAvailable,
            'data_portability_available' => true,
            'data_portability_providers' => $this->viewProviders('user'),
            'data_portability_message' => $this->session->pullFlash('data_portability_user_info'),
            'data_portability_error' => $this->session->pullFlash('data_portability_user_error'),
            'data_portability_preview' => $this->session->get(self::USER_PREVIEW_KEY),
            'data_portability_import_mode' => $this->previewImportMode(self::USER_PREVIEW_KEY),
            'data_portability_target_user' => $user,
        ])));
    }

    public function export(Request $request): Response
    {
        try {
            $keys = $request->inputRaw('providers', []);
            $keys = is_array($keys) ? array_map('strval', $keys) : [];
            $export = $this->service->createExport($keys, $this->currentUserId(), 'admin');
            return Response::downloadFile($export['path'], $export['filename'], 'application/zip', true);
        } catch (Throwable $exception) {
            return $this->redirectError($exception->getMessage(), '/admin/data-portability', 'data_portability_error');
        }
    }

    public function userExport(Request $request): Response
    {
        try {
            $keys = $request->inputRaw('providers', []);
            $keys = is_array($keys) ? array_map('strval', $keys) : [];
            $export = $this->service->createExport($keys, $this->currentUserId(), 'user');
            return Response::downloadFile($export['path'], $export['filename'], 'application/zip', true);
        } catch (Throwable $exception) {
            return $this->redirectError($exception->getMessage(), '/profil/data-portability', 'data_portability_user_error');
        }
    }

    public function previewImport(Request $request): Response
    {
        $oversizedPostMessage = $this->oversizedPostMessage();
        if ($oversizedPostMessage !== null) {
            return $this->redirectError($oversizedPostMessage, '/admin/data-portability', 'data_portability_error');
        }

        try {
            $upload = $_FILES['import_zip'] ?? null;
            if (!is_array($upload)) {
                throw new RuntimeException('Bitte eine Export-ZIP auswählen.');
            }

            $importMode = $this->importModeFromRequest($request);
            $result = $this->service->previewUpload($upload, $this->currentUserId(), 'admin', $importMode);
            $this->session->set(self::ADMIN_IMPORT_TOKEN_KEY, $result['token']);
            $this->session->set(self::ADMIN_PREVIEW_KEY, $result['preview']);
            $this->session->flash('data_portability_info', 'Import-Vorschau wurde erstellt. Bitte prüfen und anschließend bestätigen.');
        } catch (Throwable $exception) {
            $this->session->remove(self::ADMIN_IMPORT_TOKEN_KEY);
            $this->session->remove(self::ADMIN_PREVIEW_KEY);
            $this->session->flash('data_portability_error', $exception->getMessage());
        }

        return Response::redirect('/admin/data-portability');
    }

    public function userPreviewImport(Request $request): Response
    {
        $oversizedPostMessage = $this->oversizedPostMessage();
        if ($oversizedPostMessage !== null) {
            return $this->redirectError($oversizedPostMessage, '/profil/data-portability', 'data_portability_user_error');
        }

        try {
            $upload = $_FILES['import_zip'] ?? null;
            if (!is_array($upload)) {
                throw new RuntimeException('Bitte eine Export-ZIP auswählen.');
            }

            $importMode = $this->importModeFromRequest($request);
            $result = $this->service->previewUpload($upload, $this->currentUserId(), 'user', $importMode);
            $this->session->set(self::USER_IMPORT_TOKEN_KEY, $result['token']);
            $this->session->set(self::USER_PREVIEW_KEY, $result['preview']);
            $this->session->flash('data_portability_user_info', 'Import-Vorschau wurde erstellt. Bitte prüfen und anschließend bestätigen.');
        } catch (Throwable $exception) {
            $this->session->remove(self::USER_IMPORT_TOKEN_KEY);
            $this->session->remove(self::USER_PREVIEW_KEY);
            $this->session->flash('data_portability_user_error', $exception->getMessage());
        }

        return Response::redirect('/profil/data-portability');
    }

    public function runImport(Request $request): Response
    {
        try {
            $token = $this->session->get(self::ADMIN_IMPORT_TOKEN_KEY);
            if (!is_string($token) || $token === '') {
                throw new RuntimeException('Keine vorbereitete Import-Datei gefunden.');
            }
            $importMode = $this->previewImportMode(self::ADMIN_PREVIEW_KEY);

            $path = $this->service->resolveImportPath($token);
            $result = $this->service->importArchive($path, $this->currentUserId(), 'admin', $importMode);
            $this->service->cleanup($path);
            $this->session->remove(self::ADMIN_IMPORT_TOKEN_KEY);
            $this->session->remove(self::ADMIN_PREVIEW_KEY);

            $this->session->flash('data_portability_info', 'Import abgeschlossen. ' . $this->formatResultParts($result));
        } catch (Throwable $exception) {
            $this->session->flash('data_portability_error', $exception->getMessage());
        }

        return Response::redirect('/admin/data-portability');
    }

    public function userRunImport(Request $request): Response
    {
        try {
            $token = $this->session->get(self::USER_IMPORT_TOKEN_KEY);
            if (!is_string($token) || $token === '') {
                throw new RuntimeException('Keine vorbereitete Import-Datei gefunden.');
            }
            $importMode = $this->previewImportMode(self::USER_PREVIEW_KEY);

            $path = $this->service->resolveImportPath($token);
            $result = $this->service->importArchive($path, $this->currentUserId(), 'user', $importMode);
            $this->service->cleanup($path);
            $this->session->remove(self::USER_IMPORT_TOKEN_KEY);
            $this->session->remove(self::USER_PREVIEW_KEY);

            $this->session->flash('data_portability_user_info', 'Import abgeschlossen. ' . $this->formatResultParts($result));
        } catch (Throwable $exception) {
            $this->session->flash('data_portability_user_error', $exception->getMessage());
        }

        return Response::redirect('/profil/data-portability');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function viewProviders(string $scope): array
    {
        $providers = [];
        foreach ($this->service->providersForScope($scope) as $provider) {
            $providers[] = [
                'key' => $provider->key(),
                'label' => $provider->label(),
                'route_prefix' => $provider->routePrefix(),
                'description' => $provider->description(),
                'schema_version' => $provider->schemaVersion(),
                'has_files' => $provider->hasFiles(),
                'sensitivity_note' => $provider->sensitivityNote(),
            ];
        }

        return $providers;
    }

    private function redirectError(string $message, string $path, string $flashKey): Response
    {
        $this->session->flash($flashKey, $message);

        return Response::redirect($path);
    }

    /**
     * @param array<string,mixed> $result
     */
    private function formatResultParts(array $result): string
    {
        $parts = [];
        foreach (($result['results'] ?? []) as $key => $stats) {
            if (!is_array($stats)) {
                continue;
            }
            $summary = trim((string) ($stats['summary'] ?? ''));
            if ($summary !== '') {
                $parts[] = $key . ': ' . $summary;
                continue;
            }
            $parts[] = $key . ': neu ' . (int) ($stats['created'] ?? 0)
                . ', aktualisiert ' . (int) ($stats['updated'] ?? 0)
                . ', übersprungen ' . (int) ($stats['skipped'] ?? 0);
        }

        return implode(' · ', $parts);
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function baseViewData(Request $request, array $extra = []): array
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

    private function currentUserId(): int
    {
        $user = $this->currentUser();
        $id = (int) ($user['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Aktueller Benutzer konnte nicht ermittelt werden.');
        }

        return $id;
    }

    /**
     * @return array<string,mixed>
     */
    private function currentUser(): array
    {
        $user = $this->auth?->currentUser();
        return is_array($user) ? $user : [];
    }

    private function importModeFromRequest(Request $request): string
    {
        return (string) $request->input('import_mode', '') === 'replace' ? 'replace' : 'merge';
    }

    private function previewImportMode(string $previewKey): string
    {
        $preview = $this->session->get($previewKey);
        if (is_array($preview) && (string) ($preview['import_mode'] ?? '') === 'replace') {
            return 'replace';
        }

        return 'merge';
    }

    private function oversizedPostMessage(): ?string
    {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

        return self::buildOversizedPostMessage(
            $contentLength,
            (string) ini_get('post_max_size'),
            (string) ini_get('upload_max_filesize')
        );
    }

    private static function buildOversizedPostMessage(int $contentLength, string $postMaxSize, string $uploadMaxFilesize): ?string
    {
        $postMaxBytes = self::phpSizeToBytes($postMaxSize);
        if ($contentLength <= 0 || $postMaxBytes <= 0 || $contentLength <= $postMaxBytes) {
            return null;
        }

        $uploadMaxBytes = self::phpSizeToBytes($uploadMaxFilesize);
        $uploadText = $uploadMaxBytes > 0 ? self::formatBytes($uploadMaxBytes) : trim($uploadMaxFilesize);

        return 'Die hochgeladene Datei ist zu groß für die aktuelle Serverkonfiguration. Der Server erlaubt aktuell maximal '
            . self::formatBytes($postMaxBytes)
            . ' POST-Daten. upload_max_filesize ist aktuell '
            . $uploadText
            . '. Bitte post_max_size und upload_max_filesize erhöhen oder eine kleinere ZIP-Datei verwenden.';
    }

    private static function phpSizeToBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $unit = strtolower(substr($value, -1));
        $number = (float) $value;
        return match ($unit) {
            'g' => (int) ($number * 1024 * 1024 * 1024),
            'm' => (int) ($number * 1024 * 1024),
            'k' => (int) ($number * 1024),
            default => (int) $number,
        };
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return str_replace('.', ',', rtrim(rtrim(number_format($bytes / (1024 * 1024 * 1024), 1, '.', ''), '0'), '.')) . ' GB';
        }
        if ($bytes >= 1024 * 1024) {
            return str_replace('.', ',', rtrim(rtrim(number_format($bytes / (1024 * 1024), 1, '.', ''), '0'), '.')) . ' MB';
        }
        if ($bytes >= 1024) {
            return str_replace('.', ',', rtrim(rtrim(number_format($bytes / 1024, 1, '.', ''), '0'), '.')) . ' KB';
        }

        return $bytes . ' Bytes';
    }
}
