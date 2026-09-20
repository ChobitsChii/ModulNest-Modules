<?php

declare(strict_types=1);

namespace ModulNest\Tools;

use RuntimeException;
use Throwable;

final class ToolsNetworkService
{
    /**
     * @param array<string, mixed> $input
     * @return array{ok:bool,title:string,summary:string,output:string,records:array<int, mixed>}
     */
    public function run(string $tool, array $input): array
    {
        try {
            return match ($tool) {
                'ping' => $this->ping($input),
                'traceroute' => $this->traceroute($input),
                'dns' => $this->dns($input),
                'reverse-dns' => $this->reverseDns($input),
                'http-headers' => $this->httpHeaders($input),
                'ssl-info' => $this->sslInfo($input),
                'port-check' => $this->portCheck($input),
                'mail-dns' => $this->mailDns($input),
                default => throw new RuntimeException('Unbekanntes Admin-Tool.'),
            };
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'title' => 'Fehler',
                'summary' => $exception->getMessage(),
                'output' => '',
                'records' => [],
            ];
        }
    }

    private function ping(array $input): array
    {
        $host = $this->host((string) ($input['host'] ?? ''));
        $binary = $this->firstExecutable(['/usr/bin/ping', '/bin/ping']);
        if ($binary === null) {
            throw new RuntimeException('ping ist auf diesem System nicht verfügbar.');
        }

        $output = $this->runCommand([$binary, '-c', '4', '-W', '2', $host], 10);
        return $this->result(true, 'Ping', 'Ping zu ' . $host, $output);
    }

    private function traceroute(array $input): array
    {
        $host = $this->host((string) ($input['host'] ?? ''));
        $tracepath = $this->firstExecutable(['/usr/bin/tracepath', '/bin/tracepath']);
        if ($tracepath !== null) {
            $output = $this->runCommand([$tracepath, '-m', '20', $host], 15);
            return $this->result(true, 'Tracepath', 'Tracepath zu ' . $host, $output);
        }

        $traceroute = $this->firstExecutable(['/usr/bin/traceroute', '/bin/traceroute']);
        if ($traceroute === null) {
            throw new RuntimeException('tracepath/traceroute ist auf diesem System nicht verfügbar.');
        }

        $output = $this->runCommand([$traceroute, '-m', '20', '-w', '2', $host], 20);
        return $this->result(true, 'Traceroute', 'Traceroute zu ' . $host, $output);
    }

    private function dns(array $input): array
    {
        $host = $this->domain((string) ($input['host'] ?? ''));
        $type = strtoupper((string) ($input['record_type'] ?? 'A'));
        $types = [
            'A' => DNS_A,
            'AAAA' => DNS_AAAA,
            'MX' => DNS_MX,
            'TXT' => DNS_TXT,
            'CNAME' => DNS_CNAME,
            'NS' => DNS_NS,
        ];
        if (!isset($types[$type])) {
            throw new RuntimeException('Nicht erlaubter DNS-Typ.');
        }

        $records = dns_get_record($host, $types[$type]);
        if (!is_array($records)) {
            $records = [];
        }

        return [
            'ok' => true,
            'title' => 'DNS Lookup',
            'summary' => $type . ' Records für ' . $host . ': ' . count($records),
            'output' => $this->json($records),
            'records' => $records,
        ];
    }

    private function reverseDns(array $input): array
    {
        $ip = trim((string) ($input['ip'] ?? ''));
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new RuntimeException('Bitte eine gültige IP-Adresse eingeben.');
        }

        $name = gethostbyaddr($ip);
        return $this->result($name !== false, 'Reverse DNS', 'PTR für ' . $ip, $name !== false ? $name : 'Kein PTR gefunden.');
    }

    private function httpHeaders(array $input): array
    {
        $url = $this->url((string) ($input['url'] ?? ''));
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Die PHP-cURL-Erweiterung ist nicht verfügbar.');
        }

        $headers = [];
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('URL konnte nicht geprüft werden.');
        }

        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_HEADER => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$headers): int {
                $headers[] = trim($header);
                return strlen($header);
            },
        ]);

        curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($error !== '') {
            throw new RuntimeException('HTTP-Check fehlgeschlagen: ' . $error);
        }

        $headers = array_values(array_filter($headers, static fn (string $line): bool => $line !== ''));
        return $this->result($status > 0, 'HTTP Header Check', 'HTTP Status ' . $status, implode("\n", $headers));
    }

    private function sslInfo(array $input): array
    {
        $host = $this->domain((string) ($input['host'] ?? ''));
        $port = (int) ($input['port'] ?? 443);
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Port muss zwischen 1 und 65535 liegen.');
        }

        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $host,
                'SNI_enabled' => true,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $client = @stream_socket_client(
            'ssl://' . $host . ':' . $port,
            $errno,
            $errstr,
            8,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!is_resource($client)) {
            throw new RuntimeException('SSL-Verbindung fehlgeschlagen: ' . ($errstr !== '' ? $errstr : 'unbekannter Fehler'));
        }

        $params = stream_context_get_params($client);
        fclose($client);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if (!is_resource($cert) && !($cert instanceof \OpenSSLCertificate)) {
            throw new RuntimeException('Kein Zertifikat gefunden.');
        }

        $parsed = openssl_x509_parse($cert);
        if (!is_array($parsed)) {
            throw new RuntimeException('Zertifikat konnte nicht gelesen werden.');
        }

        $validTo = isset($parsed['validTo_time_t']) ? date('d.m.Y H:i:s', (int) $parsed['validTo_time_t']) : '';
        $issuer = is_array($parsed['issuer'] ?? null) ? implode(', ', array_map(
            static fn (string $key, mixed $value): string => $key . '=' . (string) $value,
            array_keys($parsed['issuer']),
            array_values($parsed['issuer'])
        )) : '';
        $sans = (string) (($parsed['extensions']['subjectAltName'] ?? '') ?: '');

        return [
            'ok' => true,
            'title' => 'SSL Zertifikat',
            'summary' => 'Zertifikat für ' . $host . ' gültig bis ' . $validTo,
            'output' => "Ablaufdatum: {$validTo}\nIssuer: {$issuer}\nSANs: {$sans}",
            'records' => [
                ['valid_to' => $validTo, 'issuer' => $issuer, 'sans' => $sans],
            ],
        ];
    }

    private function portCheck(array $input): array
    {
        $host = $this->host((string) ($input['host'] ?? ''));
        $port = (int) ($input['port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Port muss zwischen 1 und 65535 liegen.');
        }

        $errno = 0;
        $errstr = '';
        $start = microtime(true);
        $socket = @fsockopen($host, $port, $errno, $errstr, 3);
        $durationMs = (int) round((microtime(true) - $start) * 1000);
        if (is_resource($socket)) {
            fclose($socket);
            return $this->result(true, 'Port Check', "{$host}:{$port} ist erreichbar", 'Antwortzeit: ' . $durationMs . ' ms');
        }

        return $this->result(false, 'Port Check', "{$host}:{$port} ist nicht erreichbar", $errstr !== '' ? $errstr : 'Verbindung fehlgeschlagen.');
    }

    private function mailDns(array $input): array
    {
        $domain = $this->domain((string) ($input['host'] ?? ''));
        $selector = trim((string) ($input['selector'] ?? ''));
        if ($selector !== '' && !preg_match('/^[A-Za-z0-9._-]{1,80}$/', $selector)) {
            throw new RuntimeException('DKIM-Selector enthält nicht erlaubte Zeichen.');
        }

        $spf = $this->txtRecords($domain, 'v=spf1');
        $dmarc = $this->txtRecords('_dmarc.' . $domain, 'v=DMARC1');
        $dkim = $selector !== '' ? $this->txtRecords($selector . '._domainkey.' . $domain, 'v=DKIM1') : [];
        $records = [
            'spf' => $spf,
            'dmarc' => $dmarc,
            'dkim' => $selector !== '' ? $dkim : ['DKIM-Selector nicht angegeben.'],
        ];

        return [
            'ok' => true,
            'title' => 'Mail DNS Check',
            'summary' => 'SPF: ' . count($spf) . ' · DMARC: ' . count($dmarc) . ' · DKIM: ' . ($selector !== '' ? count($dkim) : 'kein Selector'),
            'output' => $this->json($records),
            'records' => [$records],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function txtRecords(string $host, string $prefix): array
    {
        $records = dns_get_record($host, DNS_TXT);
        if (!is_array($records)) {
            return [];
        }

        $values = [];
        foreach ($records as $record) {
            $txt = (string) ($record['txt'] ?? '');
            if (stripos($txt, $prefix) === 0) {
                $values[] = $txt;
            }
        }

        return $values;
    }

    private function host(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 253) {
            throw new RuntimeException('Bitte einen Host oder eine IP-Adresse eingeben.');
        }
        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return $value;
        }

        return $this->domain($value);
    }

    private function domain(string $value): string
    {
        $value = strtolower(trim($value));
        $value = rtrim($value, '.');
        if ($value === '' || strlen($value) > 253) {
            throw new RuntimeException('Bitte eine Domain eingeben.');
        }
        if (!preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])$/', $value)) {
            throw new RuntimeException('Domain enthält nicht erlaubte Zeichen.');
        }

        return $value;
    }

    private function url(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 2048) {
            throw new RuntimeException('Bitte eine URL eingeben.');
        }
        $parts = parse_url($value);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new RuntimeException('Nur http/https URLs sind erlaubt.');
        }
        $this->host($host);

        return $value;
    }

    /**
     * @param array<int, string> $command
     */
    private function runCommand(array $command, int $timeoutSeconds): string
    {
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptor, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Systemkommando konnte nicht gestartet werden.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $error = '';
        $deadline = time() + $timeoutSeconds;
        while (true) {
            $output .= stream_get_contents($pipes[1]) ?: '';
            $error .= stream_get_contents($pipes[2]) ?: '';
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (time() >= $deadline) {
                proc_terminate($process);
                throw new RuntimeException('Kommando wegen Timeout abgebrochen.');
            }
            usleep(100000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $text = trim($output . ($error !== '' ? "\n" . $error : ''));
        if ($exitCode !== 0 && $text === '') {
            throw new RuntimeException('Kommando wurde mit Fehlercode ' . $exitCode . ' beendet.');
        }

        return $text;
    }

    /**
     * @param array<int, string> $paths
     */
    private function firstExecutable(array $paths): ?string
    {
        foreach ($paths as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return array{ok:bool,title:string,summary:string,output:string,records:array<int, mixed>}
     */
    private function result(bool $ok, string $title, string $summary, string $output): array
    {
        return [
            'ok' => $ok,
            'title' => $title,
            'summary' => $summary,
            'output' => $output,
            'records' => [],
        ];
    }

    private function json(mixed $value): string
    {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '';
    }
}
