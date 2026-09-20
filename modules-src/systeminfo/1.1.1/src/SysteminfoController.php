<?php

declare(strict_types=1);

namespace ModulNest\Systeminfo;

use Modulon\Core\Env;
use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\SystemHealthCheck;
use Modulon\Core\View;
use Modulon\Modules\Admin\AppSettingRepository;
use Modulon\Modules\Auth\AuthService;
use Modulon\Modules\Modules\ModuleRepository;
use PDO;

final class SysteminfoController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ModuleRepository $modules,
        private readonly AppSettingRepository $settings,
        private readonly SystemHealthCheck $healthCheck,
        private readonly ?AuthService $auth = null,
        private readonly array $authConfig = [],
        private readonly array $versionConfig = [],
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->auth?->currentUser();
        $effectiveTimezone = $this->auth?->resolveUserTimezoneName($user);
        if (!is_string($effectiveTimezone) || trim($effectiveTimezone) === '') {
            $effectiveTimezone = date_default_timezone_get();
        }
        $serverTimezone = (string) date_default_timezone_get();

        $activeModules = $this->modules->listActive();
        $activeCount = count($activeModules);
        $nativeCount = 0;
        $legacyCount = 0;
        foreach ($activeModules as $module) {
            $handler = strtolower((string) ($module['handler'] ?? 'placeholder'));
            if ($handler === 'native') {
                $nativeCount++;
            }
            if ($handler === 'legacy') {
                $legacyCount++;
            }
        }

        $userCount = $this->countUsers();
        $publicRegistration = $this->settings->getBool('public_registration_enabled', (bool) ($this->authConfig['public_registration_enabled'] ?? true));

        $appInfo = [
            'App Version' => (string) ($this->versionConfig['version'] ?? 'unbekannt'),
            'Release Channel' => (string) ($this->versionConfig['channel'] ?? 'unbekannt'),
            'Produktname' => (string) ($this->versionConfig['product_name'] ?? 'Modulon'),
            'Umgebung' => (string) Env::get('APP_ENV', 'production'),
            'Aktive Module' => (string) $activeCount,
            'Native Module (aktiv)' => (string) $nativeCount,
            'Legacy Module (aktiv)' => (string) $legacyCount,
            'Benutzer gesamt' => (string) $userCount,
            'Öffentliche Registrierung' => $publicRegistration ? 'Aktiv' : 'Inaktiv',
        ];

        $phpInfo = [
            'PHP Version' => PHP_VERSION,
            'SAPI' => PHP_SAPI,
            'memory_limit' => (string) ini_get('memory_limit'),
            'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
            'post_max_size' => (string) ini_get('post_max_size'),
            'max_execution_time' => (string) ini_get('max_execution_time'),
            'date.timezone' => (string) ini_get('date.timezone'),
            'Serverzeit' => date('Y-m-d H:i:s'),
            'Zeitzone (effektiv)' => $effectiveTimezone,
            'Zeitzone (Server/PHP)' => $serverTimezone,
            'Geladene Extensions' => implode(', ', $this->loadedExtensions()),
        ];

        $webInfo = [
            'Server Software' => $this->serverValue('SERVER_SOFTWARE'),
            'Document Root' => $this->serverValue('DOCUMENT_ROOT'),
            'Server Name' => $this->serverValue('SERVER_NAME'),
            'HTTP Host' => $this->serverValue('HTTP_HOST'),
            'HTTPS' => $this->isHttps() ? 'Ja' : 'Nein',
            'Request Scheme' => $this->serverValue('REQUEST_SCHEME'),
        ];

        $osInfo = [
            'OS Family' => PHP_OS_FAMILY,
            'OS / Distro' => $this->detectOsName(),
            'Kernel' => php_uname('r'),
            'Hostname' => (string) (gethostname() ?: 'nicht verfügbar'),
            'Architektur' => php_uname('m'),
        ];

        [$ramTotal, $ramAvailable] = $this->readMemoryInfo();
        $systemInfo = [
            'Uptime' => $this->readUptime(),
            'Load Average (1/5/15m)' => $this->readLoadAverage(),
            'CPU' => $this->readCpuModel(),
            'RAM gesamt' => $ramTotal,
            'RAM verfügbar' => $ramAvailable,
            'Disk gesamt (/)' => $this->diskTotal('/'),
            'Disk frei (/)' => $this->diskFree('/'),
        ];

        $dbInfo = [
            'Treiber' => (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
            'Server Version' => (string) $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
            'Datenbankname' => $this->currentDatabase(),
            'Verbindung' => 'Verbunden',
        ];

        $securityInfo = [
            'Session Name' => (string) session_name(),
            'session.cookie_secure' => (string) ini_get('session.cookie_secure'),
            'session.cookie_samesite' => (string) (ini_get('session.cookie_samesite') ?: 'nicht gesetzt'),
            'Remember Cookie Name' => (string) ($this->authConfig['remember_cookie_name'] ?? 'modulon_remember'),
            'Remember Cookie Secure' => ((bool) ($this->authConfig['remember_cookie_secure'] ?? false)) ? 'Ja' : 'Nein',
            'Remember SameSite' => (string) ($this->authConfig['remember_cookie_samesite'] ?? 'Lax'),
            'WebAuthn RP-ID' => (string) ($this->authConfig['webauthn_rp_id'] ?? ''),
            'TOTP Issuer' => (string) ($this->authConfig['totp_issuer'] ?? 'Modulon'),
        ];

        $health = $this->healthCheck->run($effectiveTimezone);
        $healthSummary = is_array($health['summary'] ?? null) ? $health['summary'] : [];
        $healthChecks = is_array($health['checks'] ?? null) ? $health['checks'] : [];
        $healthInfo = [
            'Ergebnis' => (string) ($healthSummary['text'] ?? ''),
            'Status' => strtoupper((string) ($healthSummary['status'] ?? '')),
            'OK' => (string) ((int) ($healthSummary['ok'] ?? 0)),
            'Warnungen' => (string) ((int) ($healthSummary['warning'] ?? 0)),
            'Fehler' => (string) ((int) ($healthSummary['error'] ?? 0)),
        ];
        foreach ($healthChecks as $check) {
            if (!is_array($check)) {
                continue;
            }
            $label = (string) ($check['label'] ?? 'Check');
            $value = (string) ($check['value'] ?? '');
            $status = strtoupper((string) ($check['status'] ?? ''));
            $details = trim((string) ($check['details'] ?? ''));
            $healthInfo[$label] = trim($value . ' [' . $status . ']' . ($details !== '' ? ' - ' . $details : ''));
        }

        return new Response(View::render('@modulnest.systeminfo/index', $this->viewData($request, [
            'title' => 'Systeminfo',
            'sections' => [
                'Anwendung / Modulon' => $appInfo,
                'Systemcheck' => $healthInfo,
                'Webserver' => $webInfo,
                'Betriebssystem' => $osInfo,
                'Linux / System' => $systemInfo,
                'Datenbank' => $dbInfo,
                'Sicherheit / Umgebung' => $securityInfo,
                'PHP' => $phpInfo,
            ],
        ])));
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

    private function countUsers(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) AS cnt FROM users');
        $row = $statement->fetch();
        return is_array($row) ? (int) ($row['cnt'] ?? 0) : 0;
    }

    /**
     * @return array<int, string>
     */
    private function loadedExtensions(): array
    {
        $extensions = get_loaded_extensions();
        sort($extensions);
        $extensions = array_values(array_filter($extensions, static fn ($value): bool => is_string($value) && $value !== ''));
        return $extensions;
    }

    private function serverValue(string $key): string
    {
        $value = $_SERVER[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            return 'nicht verfügbar';
        }

        return trim($value);
    }

    private function isHttps(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if (in_array($https, ['on', '1', 'true'], true)) {
            return true;
        }

        $forwarded = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        return $forwarded === 'https';
    }

    private function detectOsName(): string
    {
        $osRelease = '/etc/os-release';
        if (!is_file($osRelease)) {
            return php_uname('s');
        }

        $content = @file_get_contents($osRelease);
        if (!is_string($content) || trim($content) === '') {
            return php_uname('s');
        }

        if (preg_match('/^PRETTY_NAME=(.+)$/m', $content, $matches) !== 1) {
            return php_uname('s');
        }

        return trim($matches[1], "\"' ");
    }

    /**
     * @return array{0:string,1:string}
     */
    private function readMemoryInfo(): array
    {
        $meminfo = '/proc/meminfo';
        if (!is_file($meminfo)) {
            return ['nicht verfügbar', 'nicht verfügbar'];
        }

        $content = @file_get_contents($meminfo);
        if (!is_string($content) || trim($content) === '') {
            return ['nicht verfügbar', 'nicht verfügbar'];
        }

        preg_match('/^MemTotal:\s+(\d+)\s+kB$/m', $content, $totalMatches);
        preg_match('/^MemAvailable:\s+(\d+)\s+kB$/m', $content, $availableMatches);

        $total = isset($totalMatches[1]) ? $this->formatBytes((int) $totalMatches[1] * 1024) : 'nicht verfügbar';
        $available = isset($availableMatches[1]) ? $this->formatBytes((int) $availableMatches[1] * 1024) : 'nicht verfügbar';

        return [$total, $available];
    }

    private function readUptime(): string
    {
        $uptimeFile = '/proc/uptime';
        if (!is_file($uptimeFile)) {
            return 'nicht verfügbar';
        }

        $content = @file_get_contents($uptimeFile);
        if (!is_string($content) || trim($content) === '') {
            return 'nicht verfügbar';
        }

        $parts = preg_split('/\s+/', trim($content));
        if (!is_array($parts) || !isset($parts[0]) || !is_numeric($parts[0])) {
            return 'nicht verfügbar';
        }

        return $this->formatDuration((int) floor((float) $parts[0]));
    }

    private function readLoadAverage(): string
    {
        $load = sys_getloadavg();
        if (!is_array($load) || count($load) < 3) {
            return 'nicht verfügbar';
        }

        return sprintf('%.2f / %.2f / %.2f', (float) $load[0], (float) $load[1], (float) $load[2]);
    }

    private function readCpuModel(): string
    {
        $cpuInfo = '/proc/cpuinfo';
        if (!is_file($cpuInfo)) {
            return 'nicht verfügbar';
        }

        $content = @file_get_contents($cpuInfo);
        if (!is_string($content) || trim($content) === '') {
            return 'nicht verfügbar';
        }

        if (preg_match('/^model name\s*:\s*(.+)$/mi', $content, $matches) === 1) {
            return trim($matches[1]);
        }
        if (preg_match('/^Hardware\s*:\s*(.+)$/mi', $content, $matches) === 1) {
            return trim($matches[1]);
        }

        return 'nicht verfügbar';
    }

    private function diskTotal(string $path): string
    {
        $value = @disk_total_space($path);
        if (!is_float($value) && !is_int($value)) {
            return 'nicht verfügbar';
        }

        return $this->formatBytes((float) $value);
    }

    private function diskFree(string $path): string
    {
        $value = @disk_free_space($path);
        if (!is_float($value) && !is_int($value)) {
            return 'nicht verfügbar';
        }

        return $this->formatBytes((float) $value);
    }

    private function currentDatabase(): string
    {
        $statement = $this->pdo->query('SELECT DATABASE() AS db_name');
        $row = $statement->fetch();
        if (!is_array($row)) {
            return 'nicht verfügbar';
        }

        $name = $row['db_name'] ?? null;
        return is_string($name) && $name !== '' ? $name : 'nicht verfügbar';
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 0) {
            return 'nicht verfügbar';
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        $parts = [];
        if ($days > 0) {
            $parts[] = $days . 'd';
        }
        if ($hours > 0 || $days > 0) {
            $parts[] = $hours . 'h';
        }
        $parts[] = $minutes . 'm';

        return implode(' ', $parts);
    }

    private function formatBytes(float $bytes): string
    {
        if ($bytes < 0) {
            return 'nicht verfügbar';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = $bytes;
        $unitIndex = 0;
        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value /= 1024;
            $unitIndex++;
        }

        return number_format($value, 2, '.', '') . ' ' . $units[$unitIndex];
    }
}
