<?php

declare(strict_types=1);

namespace ModulNest\Logs;

use DateTimeImmutable;
use DateTimeZone;
use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\View;
use Modulon\Modules\Auth\AuthService;
use Throwable;

final class LogsController
{
    private const MAX_LINES = 500;

    public function __construct(
        private readonly string $basePath,
        private readonly ?AuthService $auth = null,
    ) {
    }

    public function index(Request $request): Response
    {
        $files = $this->logFiles();
        $timezone = $this->userTimezone();
        $files = array_map(fn (array $file): array => $this->formatFileMetadata($file, $timezone), $files);
        $requestedFile = (string) ($request->query('file', '') ?? '');
        [$selected, $invalidSelection] = $this->selectedFile($requestedFile, $files);
        $lines = $selected !== null ? $this->readTail($selected['path'], self::MAX_LINES) : [];

        return new Response(View::render('@modulnest.logs/index', [
            'title' => 'Logs',
            'current_path' => $request->path(),
            'auth' => $this->authData(),
            'admin_section' => 'logs',
            'files' => $files,
            'selected_file' => $selected,
            'invalid_selection' => $invalidSelection,
            'lines' => array_map(fn (string $line): array => $this->formatLine($line, $timezone), $lines),
            'max_lines' => self::MAX_LINES,
            'timezone_name' => $timezone->getName(),
        ]));
    }

    /**
     * @return array<int, array{name:string,path:string,size:int,modified:int}>
     */
    private function logFiles(): array
    {
        $directory = $this->logDirectory();
        $paths = array_merge(glob($directory . '/*.log') ?: [], glob($directory . '/*.log.gz') ?: []);
        if (!is_array($paths)) {
            return [];
        }

        $files = [];
        foreach ($paths as $path) {
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }
            $real = realpath($path);
            if (!is_string($real) || !str_starts_with($real, $directory . '/')) {
                continue;
            }
            $files[] = [
                'name' => basename($real),
                'path' => $real,
                'size' => (int) filesize($real),
                'modified' => (int) filemtime($real),
            ];
        }

        usort($files, static fn (array $a, array $b): int => ((int) $b['modified']) <=> ((int) $a['modified']));
        return $files;
    }

    /**
     * @param array<int, array{name:string,path:string,size:int,modified:int}> $files
     * @return array{0:array{name:string,path:string,size:int,modified:int}|null,1:bool}
     */
    private function selectedFile(string $requested, array $files): array
    {
        $rawRequested = trim($requested);
        $requested = basename($rawRequested);
        if ($rawRequested !== '') {
            foreach ($files as $file) {
                if (($file['name'] ?? '') === $requested) {
                    return [$file, false];
                }
            }

            return [$files[0] ?? null, true];
        }

        return [$files[0] ?? null, false];
    }

    /**
     * @return array<int, string>
     */
    private function readTail(string $path, int $maxLines): array
    {
        if (str_ends_with($path, '.gz')) {
            $handle = @gzopen($path, 'rb');
            if ($handle === false) { return []; }
            $lines = [];
            while (!gzeof($handle)) { $line = gzgets($handle); if (is_string($line)) { $lines[] = rtrim($line, "\r\n"); } }
            gzclose($handle);
        } else {
            $lines = @file($path, FILE_IGNORE_NEW_LINES);
        }
        if (!is_array($lines)) {
            return [];
        }

        return array_slice($lines, -$maxLines);
    }

    /**
     * @return array{raw:string,pretty:string,status:string,event:string,timestamp:string,timestamp_local:string,reason:string,data:array<string,mixed>,context:array<string,mixed>,is_json:bool}
     */
    private function formatLine(string $line, DateTimeZone $timezone): array
    {
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            return [
                'raw' => $line,
                'pretty' => $line,
                'status' => '',
                'event' => '',
                'timestamp' => '',
                'timestamp_local' => '',
                'reason' => '',
                'data' => [],
                'context' => [],
                'is_json' => false,
            ];
        }

        $data = $this->eventData($decoded);
        $context = $this->eventContext($decoded, $data);
        $pretty = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        // Einige interne JSONL-Quellen verwenden historisch `at`; beide
        // Zeitfelder durchlaufen bewusst denselben Nutzer-Zeitzonen-Formatter.
        $timestamp = (string) ($decoded['timestamp'] ?? $decoded['at'] ?? '');
        $event = (string) ($decoded['event'] ?? $decoded['type'] ?? 'log_entry');

        return [
            'raw' => $line,
            'pretty' => is_string($pretty) ? $pretty : $line,
            'status' => (string) ($data['status'] ?? ''),
            'event' => $event,
            'timestamp' => $timestamp,
            'timestamp_local' => $this->formatTimestamp($timestamp, $timezone),
            'reason' => (string) ($data['reason'] ?? ''),
            'data' => $data,
            'context' => $context,
            'is_json' => true,
        ];
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

    private function logDirectory(): string
    {
        $real = realpath($this->basePath . '/storage/logs');
        return is_string($real) ? $real : rtrim($this->basePath, '/') . '/storage/logs';
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>
     */
    private function eventData(array $decoded): array
    {
        if (is_array($decoded['data'] ?? null)) {
            return $decoded['data'];
        }

        $excluded = ['timestamp', 'at', 'request', 'trace'];
        $data = [];
        foreach ($decoded as $key => $value) {
            if (in_array((string) $key, $excluded, true)) {
                continue;
            }
            $data[(string) $key] = $value;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $decoded
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function eventContext(array $decoded, array $data): array
    {
        if (is_array($data['context'] ?? null)) {
            return $data['context'];
        }
        if (is_array($decoded['request'] ?? null)) {
            $context = $decoded['request'];
            foreach (['method', 'uri', 'user_id', 'file', 'line'] as $key) {
                if (array_key_exists($key, $decoded)) {
                    $context[$key] = $decoded[$key];
                }
            }
            return $context;
        }

        return [];
    }

    private function userTimezone(): DateTimeZone
    {
        try {
            return $this->auth?->resolveUserTimezone() ?? new DateTimeZone(date_default_timezone_get());
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    private function formatTimestamp(string $timestamp, DateTimeZone $timezone): string
    {
        if (trim($timestamp) === '') {
            return '';
        }

        try {
            return (new DateTimeImmutable($timestamp))->setTimezone($timezone)->format('d.m.Y H:i:s');
        } catch (Throwable) {
            return $timestamp;
        }
    }

    /**
     * @param array{name:string,path:string,size:int,modified:int} $file
     * @return array{name:string,path:string,size:int,modified:int,modified_local:string}
     */
    private function formatFileMetadata(array $file, DateTimeZone $timezone): array
    {
        try {
            $file['modified_local'] = (new DateTimeImmutable('@' . (int) $file['modified']))
                ->setTimezone($timezone)
                ->format('d.m.Y H:i');
        } catch (Throwable) {
            $file['modified_local'] = date('d.m.Y H:i', (int) $file['modified']);
        }

        return $file;
    }
}
