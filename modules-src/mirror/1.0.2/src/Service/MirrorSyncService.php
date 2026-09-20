<?php

declare(strict_types=1);

namespace ModulNest\Mirror\Service;

use ModulNest\Mirror\DTO\MirrorConfigDTO;
use ModulNest\Mirror\Repository\MirrorConfigRepository;
use Modulon\Core\BackgroundProcessRunner;
use RuntimeException;
use Throwable;

final class MirrorSyncService
{
    public function __construct(
        private readonly string $basePath,
        private readonly MirrorConfigRepository $repository,
        private readonly BackgroundProcessRunner $runner = new BackgroundProcessRunner(),
    ) {
    }

    /**
     * @return array{success:bool,message:string,pid?:int}
     */
    public function triggerSync(int $id, string $trigger = 'manual'): array
    {
        $mirror = $this->repository->findById($id);
        if ($mirror === null) {
            return ['success' => false, 'message' => 'Mirror-Konfiguration nicht gefunden.'];
        }

        if (!$mirror->enabled) {
            return ['success' => false, 'message' => 'Dieser Mirror ist deaktiviert.'];
        }

        $logDir = $this->basePath . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        $logFile = $logDir . '/mirror-sync-' . $id . '.log';
        $scriptPath = $this->basePath . '/tools/release/sync-mirror-job.php';

        $this->repository->updateStatus($id, 'running');

        $res = $this->runner->launchExecutable(
            $scriptPath,
            ['--id=' . $id, '--trigger=' . $trigger],
            $logFile,
            $this->basePath
        );

        return [
            'success' => true,
            'message' => 'Synchronisation für "' . $mirror->name . '" im Hintergrund gestartet.',
            'pid' => $res['pid'] ?? 0,
        ];
    }

    /**
     * Führt die eigentliche Spiegelung synchron aus (wird vom Background-Worker aufgerufen).
     */
    public function executeSync(int $id, string $trigger = 'manual'): array
    {
        $mirror = $this->repository->findById($id);
        if ($mirror === null) {
            throw new RuntimeException('Mirror-Konfiguration #' . $id . ' nicht gefunden.');
        }

        // Schutz vor parallelen Mehrfach-Starts des selben Mirrors
        $lockFile = rtrim($mirror->targetPath, '/') . '/.sync.lock';
        $lockFp = @fopen($lockFile, 'c+');
        if ($lockFp && !@flock($lockFp, LOCK_EX | LOCK_NB)) {
            fclose($lockFp);
            return [
                'success' => false,
                'error' => 'Eine Synchronisation für diesen Mirror läuft bereits.',
                'log' => 'Synchronisation übersprungen: Ein anderer Prozess führt diesen Mirror bereits aus.'
            ];
        }

        $this->repository->updateStatus($id, 'running');
        $log = [];
        $log[] = '[' . gmdate('Y-m-d H:i:s') . ' UTC] Starte Synchronisation für: ' . $mirror->name;
        $log[] = 'Typ: ' . $mirror->type . ' | Quelle: ' . $mirror->sourceUrl . ' | Ziel: ' . $mirror->targetPath;

        try {
            if (!is_dir($mirror->targetPath)) {
                if (!@mkdir($mirror->targetPath, 0775, true) && !is_dir($mirror->targetPath)) {
                    throw new RuntimeException('Zielverzeichnis konnte nicht erstellt werden: ' . $mirror->targetPath);
                }
            }

            if (!is_writable($mirror->targetPath)) {
                throw new RuntimeException('Zielverzeichnis ist nicht beschreibbar: ' . $mirror->targetPath);
            }

            if ($mirror->type === 'updates') {
                $this->syncUpdates($mirror, $log);
            } else {
                $this->syncRepository($mirror, $log);
            }

            $log[] = '[' . gmdate('Y-m-d H:i:s') . ' UTC] Synchronisation erfolgreich abgeschlossen.';
            $fullLog = implode("\n", $log);
            if ($lockFp) { @flock($lockFp, LOCK_UN); @fclose($lockFp); @unlink($lockFile); }
            $this->repository->updateStatus($id, 'success', $fullLog, $trigger);

            return ['success' => true, 'log' => $fullLog];
        } catch (Throwable $e) {
            $log[] = '[' . gmdate('Y-m-d H:i:s') . ' UTC] FEHLER: ' . $e->getMessage();
            $fullLog = implode("\n", $log);
            if ($lockFp) { @flock($lockFp, LOCK_UN); @fclose($lockFp); @unlink($lockFile); }
            $this->repository->updateStatus($id, 'error', $fullLog, $trigger);

            return ['success' => false, 'error' => $e->getMessage(), 'log' => $fullLog];
        }
    }

    private function syncUpdates(MirrorConfigDTO $mirror, array &$log): void
    {
        $log[] = 'Lade Core-Update-Feeds herunter...';

        $sourceUrl = rtrim($mirror->sourceUrl, '/');
        // If raw GitHub or updates URL
        if (str_contains($sourceUrl, 'github.com') && !str_contains($sourceUrl, 'raw.githubusercontent.com')) {
            // e.g. https://github.com/ChobitsChii/ModulNest -> raw URL
            $path = parse_url($sourceUrl, PHP_URL_PATH);
            $sourceUrl = 'https://raw.githubusercontent.com' . $path . '/' . $mirror->sourceBranch . '/build/update';
        }

        $publicBase = !empty($mirror->publicUrl) ? rtrim($mirror->publicUrl, '/') : '';

        $stage = rtrim($mirror->targetPath, '/') . '/.sync-stg-' . bin2hex(random_bytes(4));
        if (!mkdir($stage, 0775, true)) {
            throw new RuntimeException('Staging-Verzeichnis konnte nicht erstellt werden.');
        }

        try {
            foreach (['stable.json', 'prerelease.json'] as $feedName) {
                $url = $sourceUrl . '/' . $feedName;
                $log[] = 'Abrufen: ' . $url;
                $feedContent = $this->httpGet($url);
                $feed = json_decode($feedContent, true);
                if (!is_array($feed) || empty($feed['latest'])) {
                    throw new RuntimeException('Ungültiges Feed-Format von: ' . $url);
                }

                $version = (string) $feed['latest'];
                if (isset($feed['packages']) && is_array($feed['packages'])) {
                    foreach (['source', 'bundled'] as $type) {
                        if (!isset($feed['packages'][$type]) || !is_array($feed['packages'][$type])) {
                            continue;
                        }

                        $pkg = $feed['packages'][$type];
                        $pkgUrl = (string) ($pkg['url'] ?? '');
                        $pkgHash = (string) ($pkg['sha256'] ?? '');

                        if ($pkgUrl !== '') {
                            $filename = basename(parse_url($pkgUrl, PHP_URL_PATH));
                            $destRel = 'releases/' . $version . '/' . $filename;
                            $destFull = $stage . '/' . $destRel;
                            $existingTargetFile = rtrim($mirror->targetPath, '/') . '/' . $destRel;

                            if (!is_dir(dirname($destFull))) {
                                mkdir(dirname($destFull), 0775, true);
                            }

                            if ($pkgHash !== '' && file_exists($existingTargetFile) && hash_file('sha256', $existingTargetFile) === $pkgHash) {
                                $log[] = 'Paket (' . $type . ' v' . $version . ') unverändert (bereits aktuell, Download übersprungen).';
                                // Datei ist bereits am Zielort vorhanden und integer, kein Kopiervorgang nötig
                            } else {
                                $log[] = 'Lade Paket (' . $type . ' v' . $version . '): ' . $pkgUrl;
                                $pkgBytes = $this->httpGet($pkgUrl);

                                if ($pkgHash !== '' && !hash_equals($pkgHash, hash('sha256', $pkgBytes))) {
                                    throw new RuntimeException('SHA256 Prüfsummenfehler für ' . $pkgUrl);
                                }

                                file_put_contents($destFull, $pkgBytes);
                            }

                            if ($publicBase !== '') {
                                $feed['packages'][$type]['url'] = $publicBase . '/' . $destRel;
                            }
                        }
                    }
                }

                file_put_contents($stage . '/' . $feedName, json_encode($feed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            }

            // Atomic switch
            $this->publishStage($stage, $mirror->targetPath);
            $log[] = 'Dateien atomar im Zielverzeichnis veröffentlicht.';
        } catch (Throwable $e) {
            $this->removeDirectory($stage);
            throw $e;
        }
    }

    private function syncRepository(MirrorConfigDTO $mirror, array &$log): void
    {
        $log[] = 'Synchronisiere Modul-Repository...';

        $sourceUrl = rtrim($mirror->sourceUrl, '/');
        if (str_contains($sourceUrl, 'github.com') && !str_contains($sourceUrl, 'raw.githubusercontent.com')) {
            $path = parse_url($sourceUrl, PHP_URL_PATH);
            // Defaulting to ModulNest v2 structure:
            $sourceUrl = 'https://raw.githubusercontent.com' . $path . '/' . $mirror->sourceBranch;
        }

        $stage = rtrim($mirror->targetPath, '/') . '/.sync-stg-' . bin2hex(random_bytes(4));
        if (!mkdir($stage, 0775, true)) {
            throw new RuntimeException('Staging-Verzeichnis konnte nicht erstellt werden.');
        }

        try {
            // Lade Modul-Katalog (v1 API)
            $catalogUrl = $sourceUrl . '/catalog/v1/root.json';
            $log[] = 'Lade Modul-Katalog (v1 API): ' . $catalogUrl;
            // Catch error and check v1 logic
            try {
                $catalogContent = $this->httpGet($catalogUrl);
                $isV2 = true;
            } catch (Throwable $e) {
                // Fallback to ModulNest v1 legacy format (catalog.json)
                $catalogUrl = $sourceUrl . '/dist/catalog.json';
                $log[] = 'v1 API nicht gefunden, versuche Legacy-Katalog (catalog.json): ' . $catalogUrl;
                $catalogContent = $this->httpGet($catalogUrl);
                $isV2 = false;
            }
            $catalog = json_decode($catalogContent, true);
            if (!is_array($catalog)) {
                throw new RuntimeException('Ungültige Katalogdatei von: ' . $catalogUrl);
            }

            if ($isV2) {
                if (!is_dir($stage . '/catalog/v1')) mkdir($stage . '/catalog/v1', 0775, true);
                file_put_contents($stage . '/catalog/v1/root.json', json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            } else {
                file_put_contents($stage . '/catalog.json', json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            }

            // Optional: trust.json (V1)
            if (!$isV2) {
                try {
                    $trustContent = $this->httpGet($sourceUrl . '/trust.json');
                    file_put_contents($stage . '/trust.json', $trustContent);
                } catch (Throwable) {
                    // optional
                }
            }

            // Lade Modulpakete (Support für V1 und V2 Kataloge)
            if ($isV2) {
                // V2: root.json contains modules with a 'path' to their index
                $modules = $catalog['modules'] ?? [];
                $log[] = 'Katalog (v1 API) erkannt, verarbeite ' . count($modules) . ' Modul-Referenzen.';
                
                // Fetch root.json.sig optionally
                try {
                    $sigContent = $this->httpGet($sourceUrl . '/catalog/v1/root.json.sig');
                    if (!is_dir($stage . '/catalog/v1')) mkdir($stage . '/catalog/v1', 0775, true);
                    file_put_contents($stage . '/catalog/v1/root.json.sig', $sigContent);
                } catch (Throwable $e) {}
                
                foreach ($modules as $rootMod) {
                    $modPath = $rootMod['path'] ?? null;
                    if (!$modPath) continue;

                    $modSha = (string) ($rootMod['sha256'] ?? '');
                    $existingModFile = rtrim($mirror->targetPath, '/') . '/' . $modPath;
                    if ($modSha !== '' && file_exists($existingModFile) && hash_file('sha256', $existingModFile) === $modSha) {
                        $log[] = 'Modul-Index ' . ($rootMod['id'] ?? $modPath) . ' unverändert (übersprungen).';
                        continue;
                    }
                    
                    $modUrl = $sourceUrl . '/' . $modPath;
                    $log[] = 'Lade Modul-Index: ' . $modUrl;
                    
                    try {
                        $modBytes = $this->httpGet($modUrl);
                        $modDest = $stage . '/' . $modPath;
                        if (!is_dir(dirname($modDest))) mkdir(dirname($modDest), 0775, true);
                        file_put_contents($modDest, $modBytes);
                        
                        $modData = json_decode($modBytes, true);
                        $releases = $modData['releases'] ?? [];
                        
                        foreach ($releases as $rel) {
                            $pkgLocation = $rel['package']['location'] ?? '';
                            if ($pkgLocation && !str_starts_with($pkgLocation, 'http')) {
                                // Relative path in V2
                                $pkgHash = (string) ($rel['package']['sha256'] ?? '');
                                $downloadUrl = $sourceUrl . '/' . $pkgLocation;
                                $pkgDest = $stage . '/' . $pkgLocation;
                                $existingTargetFile = rtrim($mirror->targetPath, '/') . '/' . $pkgLocation;
                                if (!is_dir(dirname($pkgDest))) mkdir(dirname($pkgDest), 0775, true);

                                if ($pkgHash !== '' && file_exists($existingTargetFile) && hash_file('sha256', $existingTargetFile) === $pkgHash) {
                                    $log[] = 'Paket ' . $modData['id'] . ' ' . $rel['version'] . ' unverändert (bereits aktuell, Download übersprungen).';
                                    // Datei ist bereits am Zielort vorhanden und integer, kein Kopiervorgang nötig
                                } else {
                                    $log[] = 'Lade Paket ' . $modData['id'] . ' ' . $rel['version'];
                                    $pkgBytes = $this->httpGet($downloadUrl);
                                    if ($pkgHash !== '' && !hash_equals($pkgHash, hash('sha256', $pkgBytes))) {
                                        throw new RuntimeException('SHA256 Prüfsummenfehler für ' . $downloadUrl);
                                    }
                                    file_put_contents($pkgDest, $pkgBytes);
                                }
                            }
                        }
                    } catch (Throwable $e) {
                         $log[] = 'Fehler beim Laden von Modul-Index ' . $modPath . ': ' . $e->getMessage();
                    }
                }
            } else {
                $modules = $catalog['modules'] ?? [];
                if (is_array($modules)) {
                    foreach ($modules as $mod) {
                        $releases = $mod['releases'] ?? [];
                        if (is_array($releases)) {
                            foreach ($releases as $rel) {
                                $downloadUrl = (string) ($rel['download_url'] ?? ($rel['package_url'] ?? ''));
                                if ($downloadUrl !== '' && str_starts_with($downloadUrl, 'http')) {
                                    $log[] = 'Lade Modul ' . ($mod['id'] ?? '') . ' (' . ($rel['version'] ?? '') . ')...';
                                    $bytes = $this->httpGet($downloadUrl);
                                    $filename = basename(parse_url($downloadUrl, PHP_URL_PATH));
                                    $dest = $stage . '/packages/' . $filename;
                                    if (!is_dir(dirname($dest))) {
                                        mkdir(dirname($dest), 0775, true);
                                    }
                                    file_put_contents($dest, $bytes);
                                }
                            }
                        }
                    }
                }
            }

            $this->publishStage($stage, $mirror->targetPath);
            $log[] = 'Repository-Dateien atomar im Zielverzeichnis veröffentlicht.';
        } catch (Throwable $e) {
            $this->removeDirectory($stage);
            throw $e;
        }
    }

    private function httpGet(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 45,
                'follow_location' => 1,
                'max_redirects' => 5,
                'user_agent' => 'ModulNest-Mirror-Sync/1.0',
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $content = @file_get_contents($url, false, $context);
        if ($content === false) {
            throw new RuntimeException('Konnte URL nicht abrufen: ' . $url);
        }

        return $content;
    }

    private function atomicSwitch(string $stage, string $target): void
    {
        $backup = dirname($target) . '/.prev-' . basename($target) . '-' . bin2hex(random_bytes(3));
        if (is_dir($target)) {
            @rename($target, $backup);
        }

        if (!@rename($stage, $target)) {
            if (is_dir($backup)) {
                @rename($backup, $target);
            }
            throw new RuntimeException('Atomares Verschieben von Staging ins Zielverzeichnis fehlgeschlagen.');
        }

        if (is_dir($backup)) {
            $this->removeDirectory($backup);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = scandir($dir);
        if ($files === false) {
            return;
        }
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $p = $dir . '/' . $file;
            if (is_dir($p)) {
                $this->removeDirectory($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }

    private function publishStage(string $stage, string $target): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stage, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $deferredFiles = [];
        foreach ($iterator as $item) {
            $rel = $iterator->getSubPathname();
            $dest = $target . '/' . $rel;
            
            if ($item->isDir()) {
                if (!is_dir($dest)) {
                    mkdir($dest, 0777, true); @chmod($dest, 0777);
                }
            } else {
                $base = basename($rel);
                if (in_array($base, ['root.json', 'root.json.sig', 'catalog.json', 'catalog.json.sig', 'trust.json'])) {
                    $deferredFiles[$item->getPathname()] = $dest;
                    continue;
                }
                
                if (file_exists($dest)) {
                    @unlink($dest);
                }
                rename($item->getPathname(), $dest);
            }
        }
        
        foreach ($deferredFiles as $src => $dest) {
            if (file_exists($dest)) {
                @unlink($dest);
            }
            rename($src, $dest);
        }
        
        $this->removeDirectory($stage);
    }
}