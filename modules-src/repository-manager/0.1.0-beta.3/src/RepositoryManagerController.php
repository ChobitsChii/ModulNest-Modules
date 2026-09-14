<?php

declare(strict_types=1);

namespace ModulNest\RepositoryManager;

use InvalidArgumentException;
use Modulon\Core\DateTimeFormatter;
use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Session;
use Modulon\Core\View;
use Modulon\Core\Modules\Catalog\CatalogCache;
use Modulon\Core\Modules\Catalog\CatalogLoader;
use Modulon\Core\Modules\Catalog\CatalogSourceFactory;
use Modulon\Core\Modules\Catalog\CatalogSourceRegistry;
use Modulon\Core\Modules\Catalog\CatalogTrustStore;
use Modulon\Core\Modules\ModulePackageInspector;
use Modulon\Modules\Auth\AuthService;
use Throwable;

/**
 * UI und Orchestrierung über Modul-Katalogquellen sowie den Repository-Mirror.
 *
 * Sämtliche Validierung, Trust-Bindung, Konfliktauflösung und Persistenz laufen
 * über die öffentliche Core-API (CatalogSourceRegistry). Die Spiegelung des
 * offiziellen Repositories erfolgt sicher über den RepositoryMirrorService.
 */
final class RepositoryManagerController
{
    private const ACTOR_FALLBACK = 'repository-manager-ui';

    public function __construct(
        private readonly Session $session,
        private readonly CatalogSourceRegistry $registry,
        private readonly RepositoryMirrorService $mirrorService,
        private readonly ?AuthService $auth = null,
    ) {
    }

    public function index(Request $request): Response
    {
        $sources = $this->withEnrichedData($this->registry->list());
        $editRaw = trim((string) $request->query('edit', ''));
        $editSource = null;
        if ($editRaw !== '') {
            try {
                $found = $this->registry->get($editRaw);
                if ($found === null) {
                    $this->session->flash('repository_manager_error', "Katalogquelle '{$editRaw}' wurde nicht gefunden.");
                } else {
                    $enriched = $this->withEnrichedData([$found]);
                    $editSource = $enriched[0] ?? $found;
                }
            } catch (Throwable $error) {
                $this->session->flash('repository_manager_error', 'Fehler beim Laden der Katalogquelle: ' . $this->message($error));
            }
        }

        $mirrorStatus = $this->mirrorService->status();

        return new Response(View::render('@modulnest.repository-manager/admin', [
            'title' => 'Repository Manager',
            'current_path' => $request->path(),
            'sources' => $sources,
            'edit_source' => $editSource,
            'mirror_status' => $mirrorStatus,
            'installed_version' => $this->installedVersion(),
            'message' => $this->session->pullFlash('repository_manager_info'),
            'error' => $this->session->pullFlash('repository_manager_error'),
        ]));
    }

    public function sync(Request $request): Response
    {
        $actor = $this->actor();
        $result = $this->mirrorService->sync($actor);
        $isAjax = $this->isAjax($request);

        if ($isAjax) {
            return new Response(
                json_encode($result, JSON_THROW_ON_ERROR),
                $result['ok'] ? 200 : 400,
                ['Content-Type' => 'application/json; charset=UTF-8'],
            );
        }

        if ($result['ok']) {
            $this->session->flash('repository_manager_info', $result['message']);
        } else {
            $this->session->flash('repository_manager_error', $result['message']);
        }

        return Response::redirect('/admin/repository-manager');
    }

    public function mirrorStatus(Request $request): Response
    {
        $status = $this->mirrorService->status();

        return new Response(
            json_encode($status, JSON_THROW_ON_ERROR),
            200,
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }

    public function add(Request $request): Response
    {
        try {
            $trust = $this->extractTrust($request);
            $this->registry->add(
                (string) $request->input('id', ''),
                (string) $request->input('name', ''),
                (string) $request->input('source_type', 'https'),
                (string) $request->input('location', ''),
                (string) $request->input('enabled', '') === '1',
                (int) $request->input('priority', '0'),
                $trust['trusted_keys'],
                $trust['root_key_ids'],
                $this->actor(),
            );
            $this->session->flash('repository_manager_info', 'Katalogquelle hinzugefügt.');
        } catch (Throwable $error) {
            $this->session->flash('repository_manager_error', $this->message($error));
        }

        return Response::redirect('/admin/repository-manager');
    }

    public function update(Request $request): Response
    {
        try {
            $id = (string) $request->input('id', '');
            $source = $this->registry->get($id);
            if ($source === null) {
                throw new InvalidArgumentException('Katalogquelle wurde nicht gefunden.');
            }

            $changes = $this->collectChanges($source, $request);

            if ($this->isOfficial($source) && $changes !== []) {
                throw new InvalidArgumentException('Die offizielle ModulNest-Katalogquelle darf nicht verändert werden.');
            }

            if ($changes === []) {
                $this->session->flash('repository_manager_info', 'Keine Änderungen an der Katalogquelle vorgenommen.');
            } else {
                $this->registry->update($id, $changes, $this->actor());
                $this->session->flash('repository_manager_info', 'Katalogquelle aktualisiert.');
            }
        } catch (Throwable $error) {
            $this->session->flash('repository_manager_error', $this->message($error));
        }

        return Response::redirect('/admin/repository-manager');
    }

    public function enable(Request $request): Response
    {
        try {
            $this->registry->enable((string) $request->input('id', ''), $this->actor());
            $this->session->flash('repository_manager_info', 'Katalogquelle aktiviert.');
        } catch (Throwable $error) {
            $this->session->flash('repository_manager_error', $this->message($error));
        }

        return Response::redirect('/admin/repository-manager');
    }

    public function disable(Request $request): Response
    {
        try {
            $id = (string) $request->input('id', '');
            $source = $this->registry->get($id);
            if ($source === null) {
                throw new InvalidArgumentException('Katalogquelle wurde nicht gefunden.');
            }
            if ($this->isOfficial($source)) {
                throw new InvalidArgumentException('Die offizielle ModulNest-Katalogquelle kann nicht deaktiviert werden.');
            }
            $this->registry->disable($id, $this->actor());
            $this->session->flash('repository_manager_info', 'Katalogquelle deaktiviert.');
        } catch (Throwable $error) {
            $this->session->flash('repository_manager_error', $this->message($error));
        }

        return Response::redirect('/admin/repository-manager');
    }

    public function test(Request $request): Response
    {
        $id = trim((string) $request->input('id', ''));
        $isAjax = $this->isAjax($request);

        try {
            if ($id === '') {
                throw new InvalidArgumentException('Keine Quellen-ID angegeben.');
            }
            $sourceRecord = $this->registry->get($id);
            if ($sourceRecord === null) {
                throw new InvalidArgumentException("Katalogquelle '{$id}' wurde nicht gefunden.");
            }

            $tempCacheDir = sys_get_temp_dir() . '/modulnest-test-catalog-' . bin2hex(random_bytes(6));
            $tempCache = new CatalogCache($tempCacheDir);

            try {
                $factory = new CatalogSourceFactory();
                $source = $factory->source($sourceRecord);
                $trust = $factory->trust($sourceRecord);
                $loader = new CatalogLoader($trust, $tempCache);
                $snapshot = $loader->refresh($source);

                $moduleCount = count($snapshot->modules);
                $sequence = (int) ($snapshot->root['sequence'] ?? 0);
                $expiresAt = (string) ($snapshot->root['expires_at'] ?? '');
                $expiresLocal = $expiresAt !== '' ? DateTimeFormatter::formatUserDateTime($expiresAt) : $expiresAt;

                $message = "Verbindung und Signaturprüfung erfolgreich! (Katalog-Sequenz: {$sequence}, {$moduleCount} Modul(e), gültig bis: {$expiresLocal})";

                if ($isAjax) {
                    return new Response(json_encode([
                        'success' => true,
                        'message' => $message,
                        'sequence' => $sequence,
                        'modules_count' => $moduleCount,
                        'expires_at' => $expiresAt,
                    ], JSON_THROW_ON_ERROR), 200, ['Content-Type' => 'application/json; charset=UTF-8']);
                }

                $this->session->flash('repository_manager_info', $message);
            } finally {
                if (is_dir($tempCacheDir)) {
                    ModulePackageInspector::removeTree($tempCacheDir);
                }
            }
        } catch (Throwable $error) {
            $errorMessage = $this->message($error);
            if ($isAjax) {
                return new Response(json_encode([
                    'success' => false,
                    'error' => $errorMessage,
                ], JSON_THROW_ON_ERROR), 400, ['Content-Type' => 'application/json; charset=UTF-8']);
            }
            $this->session->flash('repository_manager_error', "Prüfung fehlgeschlagen: {$errorMessage}");
        }

        return Response::redirect('/admin/repository-manager');
    }

    private function installedVersion(): string
    {
        $manifestPath = dirname(__DIR__) . '/module.json';
        if (is_file($manifestPath)) {
            try {
                $manifest = json_decode((string) file_get_contents($manifestPath), true, 16, JSON_THROW_ON_ERROR);
                if (!empty($manifest['version'])) {
                    return (string) $manifest['version'];
                }
            } catch (Throwable) {
            }
        }

        return '0.1.0-beta.3';
    }

    /**
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private function collectChanges(array $source, Request $request): array
    {
        $changes = [];

        foreach (['name', 'source_type', 'location'] as $field) {
            $value = trim((string) $request->input($field, ''));
            if ($value !== '' && $value !== (string) $source[$field]) {
                $changes[$field] = $value;
            }
        }

        $priority = (int) $request->input('priority', (string) $source['priority']);
        if ($priority !== (int) $source['priority']) {
            $changes['priority'] = $priority;
        }

        $trustSubmitted = $request->inputRaw('trust_key_id') !== null
            || $request->input('trusted_keys_json') !== null
            || $request->input('root_key_ids') !== null;

        if ($trustSubmitted) {
            $trust = $this->extractTrust($request);
            if ($trust['trusted_keys'] != $source['trusted_keys']) {
                $changes['trusted_keys'] = $trust['trusted_keys'];
            }
            if ($trust['root_key_ids'] != $source['root_key_ids']) {
                $changes['root_key_ids'] = $trust['root_key_ids'];
            }
        }

        return $changes;
    }

    /**
     * Extrahiert strukturierte Key-Zeilen oder parst Fallback-JSON.
     *
     * @return array{trusted_keys: array<string,string>, root_key_ids: list<string>}
     */
    private function extractTrust(Request $request): array
    {
        $keyIds = $request->inputRaw('trust_key_id');
        $publicKeys = $request->inputRaw('trust_public_key');
        $rootSelections = $request->inputRaw('trust_is_root');

        if (is_array($keyIds) && is_array($publicKeys)) {
            $trustedKeys = [];
            $rootKeyIds = [];

            $count = max(count($keyIds), count($publicKeys));
            for ($i = 0; $i < $count; $i++) {
                $keyId = trim((string) ($keyIds[$i] ?? ''));
                $pubKey = trim((string) ($publicKeys[$i] ?? ''));

                if ($keyId === '' && $pubKey === '') {
                    continue;
                }

                if ($keyId === '' || $pubKey === '') {
                    throw new InvalidArgumentException("Unvollständiger Key in Zeile " . ($i + 1) . ": Sowohl Key-ID als auch Public-Key sind erforderlich.");
                }

                $trustedKeys[$keyId] = $pubKey;

                $isRootSelected = false;
                if (is_array($rootSelections)) {
                    $isRootSelected = in_array((string) $i, $rootSelections, true)
                        || in_array($keyId, $rootSelections, true)
                        || isset($rootSelections[$i]);
                }

                if ($isRootSelected) {
                    $rootKeyIds[] = $keyId;
                }
            }

            if ($trustedKeys !== []) {
                return [
                    'trusted_keys' => $trustedKeys,
                    'root_key_ids' => array_values(array_unique($rootKeyIds)),
                ];
            }
        }

        $rawJson = trim((string) $request->input('trusted_keys_json', ''));
        $trustedKeys = [];
        if ($rawJson !== '') {
            $decoded = json_decode($rawJson, true);
            if (!is_array($decoded)) {
                throw new InvalidArgumentException('Ungültiges JSON-Format für Vertrauenswürdige Schlüssel.');
            }
            foreach ($decoded as $key => $val) {
                if (is_string($key) && is_string($val)) {
                    $trustedKeys[$key] = $val;
                }
            }
        }

        $rootKeysRaw = (string) $request->input('root_key_ids', '');
        $rootKeyIds = [];
        if ($rootKeysRaw !== '') {
            $parts = explode(',', $rootKeysRaw);
            foreach ($parts as $p) {
                $trimmed = trim($p);
                if ($trimmed !== '') {
                    $rootKeyIds[] = $trimmed;
                }
            }
        }

        return [
            'trusted_keys' => $trustedKeys,
            'root_key_ids' => array_values(array_unique($rootKeyIds)),
        ];
    }

    /**
     * @param list<array<string,mixed>> $sources
     * @return list<array<string,mixed>>
     */
    private function withEnrichedData(array $sources): array
    {
        foreach ($sources as &$source) {
            $source['is_official'] = $this->isOfficial($source);
            $source['last_success_local'] = DateTimeFormatter::formatUserDateTime($source['last_success_at'] ?? '');
            $source['last_error_local'] = DateTimeFormatter::formatUserDateTime($source['last_error_at'] ?? '');
            $source['created_at_local'] = DateTimeFormatter::formatUserDateTime($source['created_at'] ?? '');
            $source['updated_at_local'] = DateTimeFormatter::formatUserDateTime($source['updated_at'] ?? '');

            $keys = is_array($source['trusted_keys'] ?? null) ? $source['trusted_keys'] : [];
            $roots = is_array($source['root_key_ids'] ?? null) ? array_values(array_map('strval', $source['root_key_ids'])) : [];
            $keyDetails = [];

            if ($keys !== []) {
                try {
                    $trustStore = new CatalogTrustStore($keys, $roots);
                    foreach ($keys as $keyId => $pubKey) {
                        $keyIdStr = (string) $keyId;
                        $fp = '';
                        try {
                            $fp = $trustStore->fingerprint($keyIdStr);
                        } catch (Throwable) {
                            $fp = 'Ungültiger Schlüssel';
                        }
                        $keyDetails[] = [
                            'key_id' => $keyIdStr,
                            'public_key' => (string) $pubKey,
                            'is_root' => in_array($keyIdStr, $roots, true),
                            'fingerprint' => $fp,
                        ];
                    }
                } catch (Throwable) {
                    foreach ($keys as $keyId => $pubKey) {
                        $keyIdStr = (string) $keyId;
                        $keyDetails[] = [
                            'key_id' => $keyIdStr,
                            'public_key' => (string) $pubKey,
                            'is_root' => in_array($keyIdStr, $roots, true),
                            'fingerprint' => 'Ungültige Trust-Konfiguration',
                        ];
                    }
                }
            }

            $source['key_details'] = $keyDetails;
        }
        unset($source);

        return $sources;
    }

    private function isOfficial(array $source): bool
    {
        return (string) ($source['id'] ?? '') === 'modulnest.official';
    }

    private function actor(): string
    {
        $user = $this->auth?->currentUser();
        $username = is_array($user) ? trim((string) ($user['username'] ?? '')) : '';
        if ($username !== '' && preg_match('/^[A-Za-z0-9@._:-]+$/D', $username)) {
            return $username;
        }

        return self::ACTOR_FALLBACK;
    }

    private function isAjax(Request $request): bool
    {
        return $request->expectsJson()
            || str_contains(strtolower((string) $request->header('Accept')), 'application/json')
            || strcasecmp((string) $request->header('X-Requested-With'), 'XMLHttpRequest') === 0
            || (string) $request->input('format', '') === 'json';
    }

    private function message(Throwable $error): string
    {
        $msg = trim($error->getMessage());
        return $msg !== '' ? $msg : 'Ein unerwarteter Fehler ist aufgetreten.';
    }
}
