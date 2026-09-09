<?php

declare(strict_types=1);

namespace ModulNest\DataPortability;

use Closure;
use Modulon\Core\Modules\DataPortability\DataPortabilityArchiveReader;
use Modulon\Core\Modules\DataPortability\DataPortabilityFileCollector;
use Modulon\Core\Modules\DataPortability\DataPortabilityProviderInterface;
use RuntimeException;
use ZipArchive;

final class DataPortabilityService
{
    private const FORMAT_VERSION = 1;
    private const MAX_IMPORT_SIZE = 512 * 1024 * 1024;

    /** @var Closure():array<string,DataPortabilityProviderInterface> */
    private readonly Closure $providerResolver;

    /** @param array<string,DataPortabilityProviderInterface>|callable():array<string,DataPortabilityProviderInterface> $providers */
    public function __construct(
        private readonly string $basePath,
        private readonly string $appVersion,
        array|callable $providers,
    ) {
        $this->providerResolver = is_array($providers)
            ? static fn (): array => $providers
            : Closure::fromCallable($providers);
    }

    /**
     * @return array<string,DataPortabilityProviderInterface>
     */
    public function providers(): array
    {
        $providers = ($this->providerResolver)();
        return array_filter(
            $providers,
            static fn (mixed $provider): bool => $provider instanceof DataPortabilityProviderInterface,
        );
    }

    /**
     * @return array<string,DataPortabilityProviderInterface>
     */
    public function providersForScope(string $scope): array
    {
        $scope = $this->normalizeScope($scope);
        $providers = [];
        foreach ($this->providers() as $key => $provider) {
            if ($this->supportsScope($provider, $scope)) {
                $providers[$key] = $provider;
            }
        }

        return $providers;
    }

    /**
     * @param array<int,string> $providerKeys
     * @return array{path:string,filename:string,manifest:array<string,mixed>}
     */
    public function createExport(array $providerKeys, int $userId, string $scope = 'admin'): array
    {
        $this->requireZip();
        $scope = $this->normalizeScope($scope);
        $selected = $this->selectedProviders($providerKeys, $scope);
        if ($selected === []) {
            throw new RuntimeException('Bitte mindestens einen Export-Bereich auswählen.');
        }

        $directory = $this->storagePath('exports');
        $filename = 'modulnest-export-' . gmdate('Y-m-d-His') . '.zip';
        $path = $directory . '/' . $filename;

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Export-ZIP konnte nicht erstellt werden.');
        }

        $manifest = [
            'format_version' => self::FORMAT_VERSION,
            'product' => 'ModulNest',
            'core' => 'Modulon',
            'app_version' => $this->appVersion,
            'created_at' => gmdate('c'),
            'scope' => 'module-data',
            'access_scope' => $scope,
            'modules' => [],
        ];

        try {
            foreach ($selected as $provider) {
                $collector = new DataPortabilityFileCollector($provider->key());
                $export = $provider->export($userId, $collector);
                $files = is_array($export['files'] ?? null) ? $export['files'] : [];
                foreach ($files as $name => $payload) {
                    $jsonPath = 'modules/' . $provider->key() . '/' . $this->safeDataFilename((string) $name);
                    $zip->addFromString($jsonPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
                }
                $collector->addToZip($zip);
                $manifest['modules'][] = [
                    'key' => $provider->key(),
                    'label' => $provider->label(),
                    'description' => $provider->description(),
                    'schema_version' => $provider->schemaVersion(),
                    'has_files' => $provider->hasFiles(),
                    'data_files' => array_map(static fn (string $name): string => 'modules/' . $provider->key() . '/' . $name, array_keys($files)),
                    'files' => $collector->manifestFiles(),
                    'file_count' => $collector->count(),
                    'counts' => is_array($export['counts'] ?? null) ? $export['counts'] : [],
                    'warnings' => is_array($export['warnings'] ?? null) ? $export['warnings'] : [],
                ];
            }

            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
        } finally {
            $zip->close();
        }

        return ['path' => $path, 'filename' => $filename, 'manifest' => $manifest];
    }

    /**
     * @param array<string,mixed> $file
     * @return array{token:string,path:string,preview:array<string,mixed>}
     */
    public function previewUpload(array $file, int $targetUserId, string $scope = 'admin', string $importMode = 'merge'): array
    {
        $this->requireZip();
        $path = $this->storeUploadedZip($file);
        $preview = $this->previewArchive($path, $targetUserId, $scope, $importMode);

        return [
            'token' => basename($path, '.zip'),
            'path' => $path,
            'preview' => $preview,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function previewArchive(string $path, int $targetUserId, string $scope = 'admin', string $importMode = 'merge'): array
    {
        $scope = $this->normalizeScope($scope);
        $importMode = $this->normalizeImportMode($importMode);
        $reader = new DataPortabilityArchiveReader($path);
        $manifest = $reader->readJson('manifest.json');
        if ((int) ($manifest['format_version'] ?? 0) !== self::FORMAT_VERSION) {
            throw new RuntimeException('Das Exportformat wird nicht unterstützt.');
        }

        $providers = $this->providers();
        $modules = [];
        foreach (($manifest['modules'] ?? []) as $module) {
            if (!is_array($module)) {
                continue;
            }
            $key = (string) ($module['key'] ?? '');
            $provider = $providers[$key] ?? null;
            if ($provider !== null && !$this->supportsScope($provider, $scope)) {
                continue;
            }
            $payload = $provider !== null ? $this->loadModulePayload($reader, $module) : [];
            $providerPreview = $provider?->previewImport($payload, $module, $reader, $targetUserId);
            $warnings = $providerPreview['warnings'] ?? ($provider === null ? ['Zielmodul ist nicht verfügbar.'] : []);
            $supportsReplace = $provider !== null && $provider->supportsReplaceImport();
            if ($importMode === 'replace') {
                if ($supportsReplace) {
                    $warnings[] = 'Ersetzen-Modus: Bestehende Daten dieses Moduls im Zielbereich werden vor dem Import gelöscht.';
                } elseif ($provider !== null) {
                    $warnings[] = 'Ersetzen-Modus wird von diesem Modul nicht unterstützt.';
                }
            }
            $modules[] = [
                'key' => $key,
                'label' => (string) ($module['label'] ?? $key),
                'schema_version' => (int) ($module['schema_version'] ?? 0),
                'available' => $provider !== null,
                'supports_replace' => $supportsReplace,
                'file_count' => (int) ($module['file_count'] ?? count($module['files'] ?? [])),
                'counts' => $providerPreview['counts'] ?? [],
                'warnings' => $warnings,
                'can_import' => $provider !== null && ($providerPreview['can_import'] ?? false) && ($importMode !== 'replace' || $supportsReplace),
            ];
        }

        return [
            'manifest' => [
                'format_version' => (int) ($manifest['format_version'] ?? 0),
                'product' => (string) ($manifest['product'] ?? ''),
                'app_version' => (string) ($manifest['app_version'] ?? ''),
                'created_at' => (string) ($manifest['created_at'] ?? ''),
            ],
            'import_mode' => $importMode,
            'modules' => $modules,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function importArchive(string $path, int $targetUserId, string $scope = 'admin', string $importMode = 'merge'): array
    {
        $scope = $this->normalizeScope($scope);
        $importMode = $this->normalizeImportMode($importMode);
        $reader = new DataPortabilityArchiveReader($path);
        $manifest = $reader->readJson('manifest.json');
        if ((int) ($manifest['format_version'] ?? 0) !== self::FORMAT_VERSION) {
            throw new RuntimeException('Das Exportformat wird nicht unterstützt.');
        }

        $providers = $this->providers();
        $processable = [];
        foreach (($manifest['modules'] ?? []) as $module) {
            if (!is_array($module)) {
                continue;
            }
            $key = (string) ($module['key'] ?? '');
            $provider = $providers[$key] ?? null;
            if ($provider !== null && !$this->supportsScope($provider, $scope)) {
                continue;
            }
            $processable[] = [$key, $provider, $module];
        }

        if ($importMode === 'replace') {
            foreach ($processable as [$key, $provider]) {
                if ($provider !== null && !$provider->supportsReplaceImport()) {
                    throw new RuntimeException('Ersetzen-Modus wird vom Modul "' . $key . '" nicht unterstützt.');
                }
            }
        }

        $results = [];
        foreach ($processable as [$key, $provider, $module]) {
            if ($provider === null) {
                $results[$key] = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'warnings' => ['Zielmodul ist nicht verfügbar.']];
                continue;
            }
            $payload = $this->loadModulePayload($reader, $module);
            $results[$key] = $provider->import($payload, $module, $reader, $targetUserId, $importMode);
        }

        return ['manifest' => $manifest, 'import_mode' => $importMode, 'results' => $results];
    }

    public function resolveImportPath(string $token): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new RuntimeException('Ungültiger Import-Token.');
        }

        $path = $this->storagePath('imports') . '/' . $token . '.zip';
        if (!is_file($path)) {
            throw new RuntimeException('Vorbereitete Import-Datei wurde nicht gefunden.');
        }

        return $path;
    }

    public function cleanup(string $path): void
    {
        if (is_file($path) && str_starts_with($path, $this->storagePath(''))) {
            @unlink($path);
        }
    }

    /**
     * @param array<int,string> $keys
     * @return array<int,DataPortabilityProviderInterface>
     */
    private function selectedProviders(array $keys, string $scope): array
    {
        $providers = $this->providers();
        $selected = [];
        foreach ($keys as $key) {
            $key = trim((string) $key);
            $provider = $providers[$key] ?? null;
            if ($provider !== null && $this->supportsScope($provider, $scope)) {
                $selected[$key] = $provider;
            }
        }

        return array_values($selected);
    }

    private function supportsScope(DataPortabilityProviderInterface $provider, string $scope): bool
    {
        return in_array($this->normalizeScope($scope), array_map('strtolower', $provider->scopes()), true);
    }

    private function normalizeScope(string $scope): string
    {
        $scope = strtolower(trim($scope));
        return in_array($scope, ['admin', 'user'], true) ? $scope : 'admin';
    }

    private function normalizeImportMode(string $mode): string
    {
        return strtolower(trim($mode)) === 'replace' ? 'replace' : 'merge';
    }

    /**
     * @param array<string,mixed> $module
     * @return array<string,mixed>
     */
    private function loadModulePayload(DataPortabilityArchiveReader $reader, array $module): array
    {
        $payload = [];
        foreach (($module['data_files'] ?? []) as $file) {
            if (!is_string($file) || $file === '') {
                continue;
            }
            $name = basename($file, '.json');
            $payload[$name] = $reader->readJson($file);
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $file
     */
    private function storeUploadedZip(array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('ZIP-Upload ist fehlgeschlagen.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        $name = (string) ($file['name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Upload konnte nicht gelesen werden.');
        }
        if ($size <= 0 || $size > self::MAX_IMPORT_SIZE) {
            throw new RuntimeException('ZIP-Datei ist zu groß oder leer.');
        }
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') {
            throw new RuntimeException('Bitte eine ZIP-Datei hochladen.');
        }

        $token = bin2hex(random_bytes(16));
        $destination = $this->storagePath('imports') . '/' . $token . '.zip';
        if (!@move_uploaded_file($tmp, $destination)) {
            throw new RuntimeException('ZIP-Datei konnte nicht gespeichert werden.');
        }

        return $destination;
    }

    private function storagePath(string $suffix): string
    {
        $path = rtrim($this->basePath, '/') . '/storage/modules/modulnest.data-portability';
        if ($suffix !== '') {
            $path .= '/' . trim($suffix, '/');
        }
        if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Storage-Verzeichnis konnte nicht erstellt werden.');
        }

        return $path;
    }

    private function requireZip(): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Die PHP-Erweiterung ZipArchive ist erforderlich.');
        }
    }

    private function safeDataFilename(string $name): string
    {
        $name = basename($name);
        if (!str_ends_with($name, '.json')) {
            $name .= '.json';
        }

        return preg_replace('/[^a-zA-Z0-9._-]+/', '-', $name) ?: 'data.json';
    }
}
