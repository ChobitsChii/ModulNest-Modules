<?php

declare(strict_types=1);

namespace ModulNest\RepositoryManager;

use DateTimeImmutable;
use Modulon\Core\BackgroundProcessRunner;
use Throwable;

/**
 * Administriert und steuert die vorhandene ModulNest-Distributionsinfrastruktur.
 *
 * Die eigentliche Spiegelungslogik (Download, Ed25519-Signaturprüfung, Replay-Schutz,
 * Hash-Validierung, Snapshot-Bau und atomare Symlink-Umschaltung) liegt ausschließlich
 * in den bestehenden, gehärteten Skripten unter /srv/http/modulnest-distribution/bin/.
 *
 * Dieser Service liest vorhandene Statusdateien und triggert den Hintergrundprozess
 * sicher über den Core-BackgroundProcessRunner.
 */
final class RepositoryMirrorService
{
    private const DEFAULT_DISTRIBUTION_BASE = '/srv/http/modulnest-distribution';
    private const DEFAULT_CONFIG_FILE = '/srv/http/modulnest-distribution/config.env';

    public function __construct(
        private readonly string $basePath,
        private readonly BackgroundProcessRunner $runner = new BackgroundProcessRunner(),
        private readonly ?string $configPath = null,
    ) {
    }

    /**
     * @return array{
     *     configured: bool,
     *     distribution_base: string,
     *     repository_root: string,
     *     updates_root: string,
     *     script_path: string,
     *     script_executable: bool,
     *     config_file: string
     * }
     */
    public function config(): array
    {
        $configFile = $this->configPath
            ?: (getenv('MODULNEST_DISTRIBUTION_CONFIG') ?: self::DEFAULT_CONFIG_FILE);

        $distributionBase = self::DEFAULT_DISTRIBUTION_BASE;
        $updatesRoot = $distributionBase . '/updates';
        $repositoryRoot = $distributionBase . '/repository';

        if (is_file($configFile) && is_readable($configFile)) {
            $lines = file($configFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#')) {
                        continue;
                    }
                    if (preg_match('/^(DISTRIBUTION_BASE|UPDATES_ROOT|REPOSITORY_ROOT)=(.*)$/', $line, $matches) === 1) {
                        $key = $matches[1];
                        $val = trim($matches[2], " \t\n\r\0\x0B\"'");
                        if ($key === 'DISTRIBUTION_BASE') {
                            $distributionBase = $val;
                        } elseif ($key === 'UPDATES_ROOT') {
                            $updatesRoot = $val;
                        } elseif ($key === 'REPOSITORY_ROOT') {
                            $repositoryRoot = $val;
                        }
                    }
                }
            }
        }

        $scriptPath = $distributionBase . '/bin/sync-repository.sh';

        return [
            'configured' => is_dir($distributionBase) && is_file($configFile),
            'distribution_base' => $distributionBase,
            'repository_root' => $repositoryRoot,
            'updates_root' => $updatesRoot,
            'script_path' => $scriptPath,
            'script_executable' => is_file($scriptPath) && is_executable($scriptPath),
            'config_file' => $configFile,
        ];
    }

    /**
     * Ermittelt den aktuellen Zustand des Repository-Spiegels aus den bestehenden State-Dateien.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $config = $this->config();
        $repoRoot = $config['repository_root'];
        $isConfigured = $config['configured'];

        $currentSymlink = $repoRoot . '/current';
        $hasCurrent = is_link($currentSymlink) || is_dir($currentSymlink);
        $snapshotName = null;
        $snapshotTimestamp = null;

        if ($hasCurrent) {
            $target = is_link($currentSymlink) ? (string) readlink($currentSymlink) : $currentSymlink;
            $snapshotName = basename($target);
            if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z/', $snapshotName, $m) === 1) {
                $snapshotTimestamp = "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]} UTC";
            }
        }

        $sequenceFile = $repoRoot . '/state/sequence';
        $sequence = is_file($sequenceFile) ? (int) trim((string) file_get_contents($sequenceFile)) : null;

        $sourceShaFile = $repoRoot . '/state/source.sha256';
        $fingerprint = is_file($sourceShaFile) ? trim((string) file_get_contents($sourceShaFile)) : null;

        $isRunning = $this->isSyncRunning($repoRoot);

        $moduleCount = null;
        $catalogId = null;
        $rootJsonPath = $currentSymlink . '/catalog/v1/root.json';
        if (is_file($rootJsonPath)) {
            try {
                $rootData = json_decode((string) file_get_contents($rootJsonPath), true, 16, JSON_THROW_ON_ERROR);
                $moduleCount = is_array($rootData['modules'] ?? null) ? count($rootData['modules']) : null;
                $catalogId = (string) ($rootData['catalog_id'] ?? '');
            } catch (Throwable) {
            }
        }

        $logSnippet = $this->readRecentLogSnippet();

        return [
            'available' => $isConfigured && is_dir($repoRoot),
            'distribution_base' => $config['distribution_base'],
            'repository_root' => $repoRoot,
            'script_path' => $config['script_path'],
            'script_executable' => $config['script_executable'],
            'current_snapshot' => $snapshotName,
            'snapshot_timestamp' => $snapshotTimestamp,
            'sequence' => $sequence,
            'fingerprint' => $fingerprint,
            'module_count' => $moduleCount,
            'catalog_id' => $catalogId,
            'is_running' => $isRunning,
            'last_log' => $logSnippet,
            'cron_example' => '5-59/15 * * * * ' . $config['script_path'] . ' --cron 2>&1 | /usr/bin/logger -t modulnest-sync-repository',
        ];
    }

    /**
     * Prüft, ob aktuell ein Sync-Prozess das flock auf state/sync.lock hält.
     */
    public function isSyncRunning(string $repoRoot): bool
    {
        $lockFile = $repoRoot . '/state/sync.lock';
        if (!is_file($lockFile)) {
            return false;
        }

        $handle = @fopen($lockFile, 'r+');
        if ($handle === false) {
            return false;
        }

        $running = false;
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                $running = true;
            } else {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }

        return $running;
    }

    /**
     * Startet den Repository-Sync asynchron im Hintergrund.
     *
     * @return array{ok:bool,status:string,message:string,pid?:int}
     */
    public function sync(string $actor = 'admin'): array
    {
        $config = $this->config();
        if (!$config['configured'] || !$config['script_executable']) {
            return [
                'ok' => false,
                'status' => 'unavailable',
                'message' => 'Das ModulNest-Distributionsskript ist nicht verfügbar oder nicht ausführbar: ' . $config['script_path'],
            ];
        }

        if ($this->isSyncRunning($config['repository_root'])) {
            return [
                'ok' => true,
                'status' => 'already_running',
                'message' => 'Ein Synchronisationsprozess läuft bereits im Hintergrund.',
            ];
        }

        $logFile = $this->basePath . '/storage/logs/repository-sync.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0770, true);
        }

        $timestamp = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        @file_put_contents($logFile, "[{$timestamp}] [sync] Sync triggered by actor '{$actor}'\n", FILE_APPEND | LOCK_EX);

        try {
            $launch = $this->runner->launchExecutable(
                $config['script_path'],
                [],
                $logFile,
                $config['distribution_base'],
                ['MODULNEST_DISTRIBUTION_CONFIG' => $config['config_file']],
            );

            $status = (string) ($launch['status'] ?? 'running');
            $pid = (int) ($launch['pid'] ?? 0);

            if ($status === 'already_running') {
                return [
                    'ok' => true,
                    'status' => 'already_running',
                    'message' => 'Ein Synchronisationsprozess läuft bereits.',
                ];
            }

            if ($status === 'completed') {
                return [
                    'ok' => true,
                    'status' => 'completed',
                    'message' => 'Repository-Synchronisation erfolgreich abgeschlossen (Spiegel ist unverändert und verifiziert).',
                ];
            }

            return [
                'ok' => true,
                'status' => 'started',
                'pid' => $pid,
                'message' => "Repository-Synchronisation im Hintergrund gestartet (PID {$pid}).",
            ];
        } catch (Throwable $error) {
            @file_put_contents($logFile, "[{$timestamp}] [error] Sync launch failed: " . $error->getMessage() . "\n", FILE_APPEND | LOCK_EX);

            return [
                'ok' => false,
                'status' => 'failed',
                'message' => 'Hintergrundprozess konnte nicht gestartet werden: ' . $error->getMessage(),
            ];
        }
    }

    private function readRecentLogSnippet(): ?string
    {
        $logFile = $this->basePath . '/storage/logs/repository-sync.log';
        if (!is_file($logFile) || !is_readable($logFile)) {
            return null;
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines) || $lines === []) {
            return null;
        }

        $recent = array_slice($lines, -6);
        return implode("\n", $recent);
    }
}
