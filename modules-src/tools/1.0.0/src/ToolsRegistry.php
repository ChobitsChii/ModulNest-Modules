<?php

declare(strict_types=1);

namespace ModulNest\Tools;

final class ToolsRegistry
{
    /**
     * @return array<int, array{key:string,label:string,category:string,visibility:string,server_side:bool,enabled:bool,rate_limit:string,logging:bool,description:string}>
     */
    public function publicTools(): array
    {
        return [
            $this->tool('text-counter', 'Textzähler', 'Text', 'user', false, 'Zeichen, Wörter, Zeilen und Absätze live zählen.'),
            $this->tool('text-cleaner', 'Textbereinigung', 'Text', 'user', false, 'Leerzeichen, Leerzeilen und Ränder bereinigen.'),
            $this->tool('base64', 'Base64 Encode/Decode', 'Konverter', 'user', false, 'Text lokal in Base64 umwandeln oder dekodieren.'),
            $this->tool('url-codec', 'URL Encode/Decode', 'Konverter', 'user', false, 'URL-Komponenten kodieren und dekodieren.'),
            $this->tool('json-formatter', 'JSON Formatter + Validator', 'Entwickler', 'user', false, 'JSON prüfen und lesbar formatieren.'),
            $this->tool('uuid-generator', 'UUID Generator', 'Entwickler', 'user', false, 'UUID v4 im Browser erzeugen.'),
            $this->tool('password-generator', 'Passwort Generator', 'Sicherheit', 'user', false, 'Lokale Passwörter mit WebCrypto-Zufall erzeugen.'),
            $this->tool('timestamp-converter', 'Unix Timestamp ↔ Datum', 'Konverter', 'user', false, 'Unix-Zeit und lokales Datum umrechnen.'),
            $this->tool('hash-generator', 'Hash Generator', 'Sicherheit', 'user', false, 'SHA-256 und SHA-512 lokal erzeugen.'),
            $this->tool('qr-code', 'QR-Code Generator', 'Entwickler', 'user', false, 'QR-Code im Browser erzeugen.'),
            $this->tool('markdown-preview', 'Markdown Preview', 'Text', 'user', false, 'Markdown lokal als Vorschau darstellen.'),
            $this->tool('regex-tester', 'Regex Tester', 'Entwickler', 'user', false, 'Reguläre Ausdrücke gegen Text testen.'),
        ];
    }

    /**
     * @return array<int, array{key:string,label:string,category:string,visibility:string,server_side:bool,enabled:bool,rate_limit:string,logging:bool,description:string}>
     */
    public function adminTools(): array
    {
        return [
            $this->tool('ping', 'Ping', 'Netzwerk', 'admin', true, 'ICMP-Erreichbarkeit per ping prüfen.', '20/min'),
            $this->tool('traceroute', 'Traceroute / tracepath', 'Netzwerk', 'admin', true, 'Netzwerkpfad zu einem Host prüfen.', '10/min'),
            $this->tool('dns', 'DNS Lookup', 'Netzwerk', 'admin', true, 'A, AAAA, MX, TXT, CNAME und NS abfragen.', '60/min'),
            $this->tool('reverse-dns', 'Reverse DNS', 'Netzwerk', 'admin', true, 'PTR-Namen zu einer IP-Adresse prüfen.', '60/min'),
            $this->tool('http-headers', 'HTTP Header Check', 'Entwickler', 'admin', true, 'HTTP-Status und Header einer URL prüfen.', '30/min'),
            $this->tool('ssl-info', 'SSL Zertifikats-Info', 'Sicherheit', 'admin', true, 'Ablaufdatum, Issuer und SANs eines Zertifikats anzeigen.', '30/min'),
            $this->tool('port-check', 'Port Check', 'Netzwerk', 'admin', true, 'TCP-Port auf einem Host testen.', '60/min'),
            $this->tool('mail-dns', 'Mail DNS Check', 'Sicherheit', 'admin', true, 'SPF, DKIM und DMARC für eine Domain prüfen.', '30/min'),
            $this->tool('speech-to-text', 'Speech-to-Text', 'Speech-to-Text', 'admin', true, 'Audio/Video lokal per ffmpeg und whisper.cpp transkribieren.', '5/min'),
        ];
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function grouped(array $tools): array
    {
        $groups = [];
        foreach ($tools as $tool) {
            $category = (string) ($tool['category'] ?? 'Tools');
            $groups[$category][] = $tool;
        }

        return $groups;
    }

    /**
     * @return array{key:string,label:string,category:string,visibility:string,server_side:bool,enabled:bool,rate_limit:string,logging:bool,description:string}
     */
    private function tool(string $key, string $label, string $category, string $visibility, bool $serverSide, string $description, string $rateLimit = 'local'): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'category' => $category,
            'visibility' => $visibility,
            'server_side' => $serverSide,
            'enabled' => true,
            'rate_limit' => $rateLimit,
            'logging' => $serverSide,
            'description' => $description,
        ];
    }
}
