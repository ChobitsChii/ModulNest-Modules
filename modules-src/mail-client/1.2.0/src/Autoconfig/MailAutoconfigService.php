<?php

declare(strict_types=1);

namespace ModulNest\MailClient\Autoconfig;

/**
 * Autoconfig/Autodiscover-Service – erkennt IMAP/SMTP-Einstellungen automatisch.
 *
 * Lookup-Reihenfolge (wie Thunderbird):
 *  1. Mozilla ISPDB (autoconfig.thunderbird.net)
 *  2. autoconfig.{domain}/mail/config-v1.1.xml
 *  3. {domain}/.well-known/autoconfig/mail/config-v1.1.xml
 *  4. Microsoft Autodiscover (autodiscover.{domain}/autodiscover/autodiscover.xml)
 *  5. DNS SRV Records (_imaps._tcp, _submission._tcp)
 *  6. MX-Record als letzter Fallback
 */
final class MailAutoconfigService
{
    private const TIMEOUT = 3; // Sekunden pro HTTP-Request

    /**
     * Versucht IMAP/SMTP-Einstellungen zu erkennen.
     *
     * @return array{
     *   imap_host: string,
     *   imap_port: int,
     *   imap_encryption: string,
     *   imap_username_format: string,
     *   smtp_host: string,
     *   smtp_port: int,
     *   smtp_encryption: string,
     *   source: string
     * }|null
     */
    public function detect(string $email): ?array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $atPos  = strrpos($email, '@');
        $domain = substr($email, $atPos + 1);
        $user   = substr($email, 0, $atPos);

        // 1. Mozilla ISPDB
        $result = $this->tryMozillaIspdb($domain);
        if ($result !== null) {
            return array_merge($result, ['source' => 'mozilla-ispdb']);
        }

        // 2. autoconfig.{domain}/mail/config-v1.1.xml
        $result = $this->tryMozillaXml("https://autoconfig.{$domain}/mail/config-v1.1.xml", $email);
        if ($result !== null) {
            return array_merge($result, ['source' => 'autoconfig-subdomain']);
        }

        // 3. {domain}/.well-known/autoconfig/mail/config-v1.1.xml
        $result = $this->tryMozillaXml("https://{$domain}/.well-known/autoconfig/mail/config-v1.1.xml", $email);
        if ($result !== null) {
            return array_merge($result, ['source' => 'well-known']);
        }

        // 4. Microsoft Autodiscover
        $result = $this->tryAutodiscover($domain, $email);
        if ($result !== null) {
            return array_merge($result, ['source' => 'autodiscover']);
        }

        // 5. DNS SRV Records
        $result = $this->tryDnsSrv($domain);
        if ($result !== null) {
            return array_merge($result, ['source' => 'dns-srv']);
        }

        // 6. MX-Record Fallback
        $result = $this->tryMxFallback($domain);
        if ($result !== null) {
            return array_merge($result, ['source' => 'mx-fallback']);
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Strategie 1: Mozilla ISPDB
    // ─────────────────────────────────────────────────────────────────────────

    private function tryMozillaIspdb(string $domain): ?array
    {
        $url     = 'https://autoconfig.thunderbird.net/v1.1/' . rawurlencode($domain);
        $content = $this->httpGet($url);
        if ($content === null) {
            return null;
        }
        return $this->parseMozillaXml($content);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Strategie 2 & 3: Mozilla XML von eigenem Server
    // ─────────────────────────────────────────────────────────────────────────

    private function tryMozillaXml(string $url, string $email): ?array
    {
        $content = $this->httpGet($url);
        if ($content === null) {
            return null;
        }
        $result = $this->parseMozillaXml($content);
        if ($result !== null) {
            // %EMAILADDRESS% und %EMAILLOCALPART% ersetzen
            $result['imap_username_format'] = str_replace(
                ['%EMAILADDRESS%', '%EMAILLOCALPART%'],
                [$email, explode('@', $email)[0]],
                $result['imap_username_format'] ?? '%EMAILADDRESS%'
            );
        }
        return $result;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function parseMozillaXml(string $xml): ?array
    {
        $prev = libxml_use_internal_errors(true);
        $dom  = simplexml_load_string($xml);
        libxml_use_internal_errors($prev);
        if ($dom === false) {
            return null;
        }

        $imap = null;
        $smtp = null;

        foreach ($dom->emailProvider->incomingServer ?? [] as $server) {
            $type = strtolower((string) ($server['type'] ?? ''));
            if ($type === 'imap' && $imap === null) {
                $imap = [
                    'host'       => (string) ($server->hostname ?? ''),
                    'port'       => (int) ($server->port ?? 993),
                    'socketType' => strtolower((string) ($server->socketType ?? 'ssl')),
                    'username'   => (string) ($server->username ?? '%EMAILADDRESS%'),
                ];
            }
        }

        foreach ($dom->emailProvider->outgoingServer ?? [] as $server) {
            $type = strtolower((string) ($server['type'] ?? ''));
            if ($type === 'smtp' && $smtp === null) {
                $smtp = [
                    'host'       => (string) ($server->hostname ?? ''),
                    'port'       => (int) ($server->port ?? 587),
                    'socketType' => strtolower((string) ($server->socketType ?? 'starttls')),
                ];
            }
        }

        if ($imap === null || $smtp === null || $imap['host'] === '' || $smtp['host'] === '') {
            return null;
        }

        return [
            'imap_host'            => $imap['host'],
            'imap_port'            => $imap['port'],
            'imap_encryption'      => $this->normalizeSocketType($imap['socketType']),
            'imap_username_format' => $imap['username'],
            'smtp_host'            => $smtp['host'],
            'smtp_port'            => $smtp['port'],
            'smtp_encryption'      => $this->normalizeSocketType($smtp['socketType']),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Strategie 4: Microsoft Autodiscover
    // ─────────────────────────────────────────────────────────────────────────

    private function tryAutodiscover(string $domain, string $email): ?array
    {
        $urls = [
            "https://autodiscover.{$domain}/autodiscover/autodiscover.xml",
            "https://{$domain}/autodiscover/autodiscover.xml",
        ];

        $body = '<?xml version="1.0" encoding="utf-8"?>'
              . '<Autodiscover xmlns="http://schemas.microsoft.com/exchange/autodiscover/outlook/requestschema/2006">'
              . '<Request><EMailAddress>' . htmlspecialchars($email, ENT_XML1) . '</EMailAddress>'
              . '<AcceptableResponseSchema>http://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a</AcceptableResponseSchema>'
              . '</Request></Autodiscover>';

        foreach ($urls as $url) {
            $content = $this->httpPost($url, $body, 'text/xml; charset=utf-8');
            if ($content === null) {
                continue;
            }
            $result = $this->parseAutodiscoverXml($content);
            if ($result !== null) {
                return $result;
            }
        }
        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function parseAutodiscoverXml(string $xml): ?array
    {
        $prev = libxml_use_internal_errors(true);
        $dom  = simplexml_load_string($xml);
        libxml_use_internal_errors($prev);
        if ($dom === false) {
            return null;
        }

        $dom->registerXPathNamespace('a', 'http://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a');

        $imapHost = $imapPort = $imapEnc = null;
        $smtpHost = $smtpPort = $smtpEnc = null;

        foreach ($dom->xpath('//a:Protocol') ?? [] as $proto) {
            $type = strtoupper((string) ($proto->Type ?? ''));
            $ssl  = strtoupper((string) ($proto->SSL ?? 'On'));
            $enc  = $ssl === 'ON' ? 'ssl' : 'tls';
            $port = (int) ($proto->Port ?? 0);
            $host = (string) ($proto->Server ?? '');

            if ($type === 'IMAP' && $imapHost === null) {
                $imapHost = $host;
                $imapPort = $port ?: ($enc === 'ssl' ? 993 : 143);
                $imapEnc  = $enc;
            }
            if ($type === 'SMTP' && $smtpHost === null) {
                $smtpHost = $host;
                $smtpPort = $port ?: 587;
                $smtpEnc  = $enc;
            }
        }

        if ($imapHost === null || $smtpHost === null) {
            return null;
        }

        return [
            'imap_host'            => $imapHost,
            'imap_port'            => $imapPort,
            'imap_encryption'      => $imapEnc,
            'imap_username_format' => '%EMAILADDRESS%',
            'smtp_host'            => $smtpHost,
            'smtp_port'            => $smtpPort,
            'smtp_encryption'      => $smtpEnc,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Strategie 5: DNS SRV Records
    // ─────────────────────────────────────────────────────────────────────────

    private function tryDnsSrv(string $domain): ?array
    {
        if (!function_exists('dns_get_record')) {
            return null;
        }

        $imap = $this->lookupSrv('_imaps._tcp.' . $domain);
        if ($imap === null) {
            $imap = $this->lookupSrv('_imap._tcp.' . $domain);
        }

        $smtp = $this->lookupSrv('_submission._tcp.' . $domain);

        if ($imap === null || $smtp === null) {
            return null;
        }

        return [
            'imap_host'            => $imap['target'],
            'imap_port'            => $imap['port'],
            'imap_encryption'      => $imap['port'] === 993 ? 'ssl' : 'tls',
            'imap_username_format' => '%EMAILADDRESS%',
            'smtp_host'            => $smtp['target'],
            'smtp_port'            => $smtp['port'],
            'smtp_encryption'      => $smtp['port'] === 465 ? 'ssl' : 'tls',
        ];
    }

    /**
     * @return array{target: string, port: int}|null
     */
    private function lookupSrv(string $record): ?array
    {
        try {
            $result = @dns_get_record($record, DNS_SRV);
            if (!is_array($result) || empty($result)) {
                return null;
            }
            usort($result, static function (array $a, array $b): int {
                return ($a['pri'] ?? 0) <=> ($b['pri'] ?? 0);
            });
            $first = $result[0];
            $target = rtrim((string) ($first['target'] ?? ''), '.');
            $port   = (int) ($first['port'] ?? 0);
            if ($target === '' || $port === 0) {
                return null;
            }
            return ['target' => $target, 'port' => $port];
        } catch (\Throwable) {
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Strategie 6: MX-Record → bekannte Provider-Hosts ableiten
    // ─────────────────────────────────────────────────────────────────────────

    private function tryMxFallback(string $domain): ?array
    {
        if (!function_exists('dns_get_record')) {
            return null;
        }
        try {
            $records = @dns_get_record($domain, DNS_MX);
            if (!is_array($records) || empty($records)) {
                return null;
            }
            usort($records, static fn (array $a, array $b): int => ($a['pri'] ?? 0) <=> ($b['pri'] ?? 0));
            $mx = strtolower(rtrim((string) ($records[0]['target'] ?? ''), '.'));
            if ($mx === '') {
                return null;
            }

            // Bekannte Provider aus dem MX-Record ableiten
            return $this->guessFromMxHost($mx);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function guessFromMxHost(string $mxHost): ?array
    {
        $map = [
            'google.com'       => ['imap.gmail.com',         993, 'ssl', 'smtp.gmail.com',         465, 'ssl'],
            'googlemail.com'   => ['imap.gmail.com',         993, 'ssl', 'smtp.gmail.com',         465, 'ssl'],
            'outlook.com'      => ['outlook.office365.com',  993, 'ssl', 'smtp.office365.com',     587, 'tls'],
            'hotmail.com'      => ['outlook.office365.com',  993, 'ssl', 'smtp.office365.com',     587, 'tls'],
            'live.com'         => ['outlook.office365.com',  993, 'ssl', 'smtp.office365.com',     587, 'tls'],
            'yahoo.com'        => ['imap.mail.yahoo.com',    993, 'ssl', 'smtp.mail.yahoo.com',    465, 'ssl'],
            'gmx.net'          => ['imap.gmx.net',           993, 'ssl', 'mail.gmx.net',           587, 'tls'],
            'gmx.de'           => ['imap.gmx.net',           993, 'ssl', 'mail.gmx.net',           587, 'tls'],
            'web.de'           => ['imap.web.de',            993, 'ssl', 'smtp.web.de',            587, 'tls'],
            't-online.de'      => ['secureimap.t-online.de', 993, 'ssl', 'securesmtp.t-online.de', 465, 'ssl'],
            'icloud.com'       => ['imap.mail.me.com',       993, 'ssl', 'smtp.mail.me.com',       587, 'tls'],
            'fastmail.com'     => ['imap.fastmail.com',      993, 'ssl', 'smtp.fastmail.com',      465, 'ssl'],
            'protonmail.ch'    => ['127.0.0.1',              1143,'ssl', '127.0.0.1',             1025, 'tls'],
        ];

        foreach ($map as $suffix => $config) {
            if (str_ends_with($mxHost, $suffix)) {
                return [
                    'imap_host'            => $config[0],
                    'imap_port'            => $config[1],
                    'imap_encryption'      => $config[2],
                    'imap_username_format' => '%EMAILADDRESS%',
                    'smtp_host'            => $config[3],
                    'smtp_port'            => $config[4],
                    'smtp_encryption'      => $config[5],
                ];
            }
        }
        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HTTP-Helfer
    // ─────────────────────────────────────────────────────────────────────────

    private function httpGet(string $url): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => self::TIMEOUT,
                'follow_location' => 1,
                'max_redirects'   => 3,
                'user_agent'      => 'ModulNest-MailClient/1.1',
                'ignore_errors'   => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);
        try {
            $content = @file_get_contents($url, false, $ctx);
            if ($content === false || strlen($content) < 100) {
                return null;
            }
            return $content;
        } catch (\Throwable) {
            return null;
        }
    }

    private function httpPost(string $url, string $body, string $contentType): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'method'          => 'POST',
                'timeout'         => self::TIMEOUT,
                'follow_location' => 1,
                'max_redirects'   => 3,
                'user_agent'      => 'ModulNest-MailClient/1.1',
                'ignore_errors'   => true,
                'header'          => "Content-Type: {$contentType}\r\nContent-Length: " . strlen($body),
                'content'         => $body,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);
        try {
            $content = @file_get_contents($url, false, $ctx);
            if ($content === false || strlen($content) < 50) {
                return null;
            }
            return $content;
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeSocketType(string $type): string
    {
        return match (strtolower($type)) {
            'ssl', 'ssltls' => 'ssl',
            'starttls'      => 'tls',
            default         => 'tls',
        };
    }
}

