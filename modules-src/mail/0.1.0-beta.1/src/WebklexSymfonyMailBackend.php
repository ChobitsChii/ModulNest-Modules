<?php

declare(strict_types=1);

namespace ModulNest\Mail;

use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\IMAP;

final class WebklexSymfonyMailBackend implements MailTransportBackendInterface
{
    public function listFolders(array $connection): array
    {
        $client = $this->buildImapClient($connection);
        try {
            $folders = $client->getFolders();
            $names = [];
            foreach ($folders as $folder) {
                if (!is_object($folder) || !isset($folder->path)) {
                    continue;
                }
                $path = trim((string) $folder->path);
                if ($path === '') {
                    continue;
                }
                $names[] = $path;
            }
            $names = array_values(array_unique($names));
            sort($names, SORT_NATURAL | SORT_FLAG_CASE);
            return $names;
        } catch (\Throwable $exception) {
            throw new RuntimeException('IMAP-Ordner konnten nicht geladen werden.');
        } finally {
            $client->disconnect();
        }
    }

    public function listMessages(
        array $connection,
        string $folder,
        int $limit = 50,
        ?int $untilUid = null,
        string $direction = 'desc'
    ): array
    {
        $client = $this->buildImapClient($connection);
        try {
            $limit = max(1, min(100, $limit));
            $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';
            $folderObj = $this->resolveFolder($client, $folder);
            // Nur Header-/Metadaten abrufen (kein Body/MIME-Parsing) für schnelle Listenansicht.
            $folderObj->query();
            $protocol = $client->getConnection();
            $uidMap = (array) $protocol->getUid()->validatedData();
            $uids = [];
            foreach ($uidMap as $uid) {
                $uidInt = (int) $uid;
                if ($uidInt > 0) {
                    $uids[] = $uidInt;
                }
            }
            if ($uids === []) {
                return [
                    'messages' => [],
                    'has_more' => false,
                    'next_until_uid' => null,
                ];
            }

            sort($uids, SORT_NUMERIC);
            $selectedUids = [];
            $hasMore = false;
            $nextUntilUid = null;

            if ($direction === 'desc') {
                if ($untilUid !== null && $untilUid > 0) {
                    foreach ($uids as $uid) {
                        if ($uid >= $untilUid) {
                            $selectedUids[] = $uid;
                        }
                    }
                } else {
                    $selectedUids = array_slice($uids, -$limit);
                }

                if ($selectedUids !== []) {
                    $currentMinUid = min($selectedUids);
                    $olderUids = [];
                    foreach ($uids as $uid) {
                        if ($uid < $currentMinUid) {
                            $olderUids[] = $uid;
                        }
                    }
                    if ($olderUids !== []) {
                        $hasMore = true;
                        $nextBatch = array_slice($olderUids, -$limit);
                        $nextUntilUid = (int) min($nextBatch);
                    }
                }

                rsort($selectedUids, SORT_NUMERIC);
            } else {
                if ($untilUid !== null && $untilUid > 0) {
                    foreach ($uids as $uid) {
                        if ($uid <= $untilUid) {
                            $selectedUids[] = $uid;
                        }
                    }
                } else {
                    $selectedUids = array_slice($uids, 0, $limit);
                }

                if ($selectedUids !== []) {
                    $currentMaxUid = max($selectedUids);
                    $newerUids = [];
                    foreach ($uids as $uid) {
                        if ($uid > $currentMaxUid) {
                            $newerUids[] = $uid;
                        }
                    }
                    if ($newerUids !== []) {
                        $hasMore = true;
                        $nextBatch = array_slice($newerUids, 0, $limit);
                        $nextUntilUid = (int) max($nextBatch);
                    }
                }
            }

            if ($selectedUids === []) {
                return [
                    'messages' => [],
                    'has_more' => false,
                    'next_until_uid' => null,
                ];
            }

            $metadata = $this->fetchFolderMessageMetadata($protocol, $selectedUids);
            $result = [];
            foreach ($selectedUids as $uid) {
                $entry = $metadata[$uid] ?? null;
                if (!is_array($entry)) {
                    continue;
                }
                $result[] = $entry;
            }

            return [
                'messages' => $result,
                'has_more' => $hasMore,
                'next_until_uid' => $nextUntilUid,
            ];
        } catch (\Throwable $exception) {
            throw new RuntimeException('Nachrichten konnten nicht geladen werden.');
        } finally {
            $client->disconnect();
        }
    }

    public function listMessagesBySender(
        array $connection,
        string $folder,
        string $senderKey,
        int $limit = 50,
        ?int $untilUid = null,
        string $direction = 'desc'
    ): array {
        $senderKey = strtolower(trim($senderKey));
        if ($senderKey === '') {
            return [
                'messages' => [],
                'has_more' => false,
                'next_until_uid' => null,
            ];
        }

        $client = $this->buildImapClient($connection);
        try {
            $limit = max(1, min(100, $limit));
            $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';
            $folderObj = $this->resolveFolder($client, $folder);
            $folderObj->query();
            $protocol = $client->getConnection();
            $uidMap = (array) $protocol->getUid()->validatedData();

            $uids = [];
            foreach ($uidMap as $uid) {
                $uidInt = (int) $uid;
                if ($uidInt > 0) {
                    $uids[] = $uidInt;
                }
            }
            if ($uids === []) {
                return [
                    'messages' => [],
                    'has_more' => false,
                    'next_until_uid' => null,
                ];
            }
            sort($uids, SORT_NUMERIC);

            $metadata = $this->fetchFolderMessageMetadata($protocol, $uids);
            $senderUids = [];
            foreach ($uids as $uid) {
                $entry = $metadata[$uid] ?? null;
                if (!is_array($entry)) {
                    continue;
                }
                if (strtolower((string) ($entry['sender_key'] ?? '')) !== $senderKey) {
                    continue;
                }
                $senderUids[] = $uid;
            }

            if ($senderUids === []) {
                return [
                    'messages' => [],
                    'has_more' => false,
                    'next_until_uid' => null,
                ];
            }

            $pagination = $this->paginateByUidCumulative($senderUids, $limit, $untilUid, $direction);
            $selectedUids = $pagination['selected_uids'];
            $result = [];
            foreach ($selectedUids as $uid) {
                $entry = $metadata[$uid] ?? null;
                if (!is_array($entry)) {
                    continue;
                }
                $result[] = $entry;
            }

            return [
                'messages' => $result,
                'has_more' => $pagination['has_more'],
                'next_until_uid' => $pagination['next_until_uid'],
            ];
        } catch (\Throwable) {
            throw new RuntimeException('Nachrichten konnten nicht geladen werden.');
        } finally {
            $client->disconnect();
        }
    }

    public function listSenderStats(
        array $connection,
        string $folder,
        array $excludedSenderKeys = []
    ): array {
        $client = $this->buildImapClient($connection);
        try {
            $folderObj = $this->resolveFolder($client, $folder);
            $folderObj->query();
            $protocol = $client->getConnection();
            $uidMap = (array) $protocol->getUid()->validatedData();
            $uids = [];
            foreach ($uidMap as $uid) {
                $uidInt = (int) $uid;
                if ($uidInt > 0) {
                    $uids[] = $uidInt;
                }
            }
            if ($uids === []) {
                return [];
            }
            sort($uids, SORT_NUMERIC);
            $metadata = $this->fetchFolderMessageMetadata($protocol, $uids);

            $excluded = [];
            foreach ($excludedSenderKeys as $key) {
                $normalized = strtolower(trim((string) $key));
                if ($normalized !== '') {
                    $excluded[$normalized] = true;
                }
            }

            $stats = [];
            foreach ($uids as $uid) {
                $entry = $metadata[$uid] ?? null;
                if (!is_array($entry)) {
                    continue;
                }
                $senderKey = strtolower((string) ($entry['sender_key'] ?? 'unknown'));
                if (isset($excluded[$senderKey])) {
                    continue;
                }
                if (!isset($stats[$senderKey])) {
                    $stats[$senderKey] = [
                        'sender_key' => $senderKey,
                        'sender_label' => (string) ($entry['sender_label'] ?? 'Unbekannt'),
                        'total_count' => 0,
                        'unread_count' => 0,
                        'latest_timestamp' => (int) ($entry['timestamp'] ?? 0),
                        'latest_date_label' => (string) ($entry['date_label'] ?? '-'),
                    ];
                }
                $stats[$senderKey]['total_count']++;
                if (!(bool) ($entry['is_read'] ?? false)) {
                    $stats[$senderKey]['unread_count']++;
                }
                $timestamp = (int) ($entry['timestamp'] ?? 0);
                if ($timestamp > (int) $stats[$senderKey]['latest_timestamp']) {
                    $stats[$senderKey]['latest_timestamp'] = $timestamp;
                    $stats[$senderKey]['latest_date_label'] = (string) ($entry['date_label'] ?? '-');
                    if ((string) ($entry['sender_label'] ?? '') !== '') {
                        $stats[$senderKey]['sender_label'] = (string) $entry['sender_label'];
                    }
                }
            }

            $result = array_values($stats);
            usort($result, static function (array $a, array $b): int {
                $cmp = ((int) ($b['latest_timestamp'] ?? 0)) <=> ((int) ($a['latest_timestamp'] ?? 0));
                if ($cmp !== 0) {
                    return $cmp;
                }

                return strcmp((string) ($a['sender_label'] ?? ''), (string) ($b['sender_label'] ?? ''));
            });

            return $result;
        } catch (\Throwable) {
            throw new RuntimeException('Absenderübersicht konnte nicht geladen werden.');
        } finally {
            $client->disconnect();
        }
    }

    public function listFolderUids(array $connection, string $folder): array
    {
        $client = $this->buildImapClient($connection);
        try {
            $folderObj = $this->resolveFolder($client, $folder);
            $folderObj->query();
            $protocol = $client->getConnection();
            $uidMap = (array) $protocol->getUid()->validatedData();
            $uids = [];
            foreach ($uidMap as $uid) {
                $uidInt = (int) $uid;
                if ($uidInt > 0) {
                    $uids[] = $uidInt;
                }
            }
            sort($uids, SORT_NUMERIC);

            return array_values(array_unique($uids));
        } catch (\Throwable) {
            throw new RuntimeException('Ordner-UIDs konnten nicht geladen werden.');
        } finally {
            $client->disconnect();
        }
    }

    public function fetchMessageMetadata(array $connection, string $folder, array $uids): array
    {
        $uids = array_values(array_unique(array_map(static fn (int $uid): int => (int) $uid, $uids)));
        $uids = array_values(array_filter($uids, static fn (int $uid): bool => $uid > 0));
        if ($uids === []) {
            return [];
        }

        $client = $this->buildImapClient($connection);
        try {
            $folderObj = $this->resolveFolder($client, $folder);
            $folderObj->query();
            $protocol = $client->getConnection();

            return $this->fetchFolderMessageMetadata($protocol, $uids);
        } catch (\Throwable) {
            throw new RuntimeException('Nachrichten-Metadaten konnten nicht geladen werden.');
        } finally {
            $client->disconnect();
        }
    }

    public function fetchMessageReadFlags(array $connection, string $folder, array $uids): array
    {
        $uids = array_values(array_unique(array_map(static fn (int $uid): int => (int) $uid, $uids)));
        $uids = array_values(array_filter($uids, static fn (int $uid): bool => $uid > 0));
        if ($uids === []) {
            return [];
        }

        $client = $this->buildImapClient($connection);
        try {
            $folderObj = $this->resolveFolder($client, $folder);
            $folderObj->query();
            $protocol = $client->getConnection();
            $flagRows = (array) $protocol->flags($uids, IMAP::ST_UID)->validatedData();

            $result = [];
            foreach ($uids as $uid) {
                $result[$uid] = $this->isSeenFlags((array) ($flagRows[$uid] ?? []));
            }

            return $result;
        } catch (\Throwable) {
            throw new RuntimeException('Nachrichten-Status konnte nicht geladen werden.');
        } finally {
            $client->disconnect();
        }
    }

    public function getMessageDetail(array $connection, string $folder, int $uid): array
    {
        $client = $this->buildImapClient($connection);
        try {
            $folderObj = $this->resolveFolder($client, $folder);
            $message = null;
            $messages = $folderObj->query()
                ->whereUid($uid)
                ->setFetchOrder('desc')
                ->setFetchBody(true)
                ->setFetchFlags(true)
                ->limit(1, 1)
                ->get();
            foreach ($messages as $candidate) {
                $message = $candidate;
                break;
            }
            if (!is_object($message)) {
                throw new RuntimeException('Nachricht nicht gefunden.');
            }

            $fromRaw = trim((string) $message->getFrom()->toString());
            $sender = $this->parseSender($fromRaw);
            $senderEmail = filter_var((string) $sender['key'], FILTER_VALIDATE_EMAIL) ? (string) $sender['key'] : '';
            $senderDomain = $senderEmail !== '' && str_contains($senderEmail, '@')
                ? (string) substr(strrchr($senderEmail, '@'), 1)
                : '';

            $timestamp = strtotime((string) $message->getDate()->toString());
            if (!is_int($timestamp) || $timestamp <= 0) {
                $timestamp = 0;
            }

            return [
                'uid' => (int) $message->uid,
                'message_id' => trim((string) $message->getMessageId()->toString()),
                'subject' => $this->normalizeSubject((string) $message->getSubject()->toString()),
                'from_raw' => $fromRaw,
                'from_label' => $sender['label'],
                'sender_email' => $senderEmail,
                'sender_domain' => strtolower($senderDomain),
                'to_raw' => trim((string) $message->getTo()->toString()),
                'date_label' => $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '-',
                'timestamp' => $timestamp,
                'is_read' => $this->isSeen($message),
                'html_body' => (string) $message->getHTMLBody(),
                'plain_body' => (string) $message->getTextBody(),
            ];
        } catch (\Throwable $exception) {
            throw new RuntimeException('Nachricht konnte nicht geladen werden.');
        } finally {
            $client->disconnect();
        }
    }

    public function sendMessage(array $connection, array $payload): void
    {
        $host = (string) ($connection['smtp_host'] ?? '');
        $port = (int) ($connection['smtp_port'] ?? 0);
        $encryption = strtolower((string) ($connection['smtp_encryption'] ?? 'tls'));
        $username = (string) ($connection['smtp_username'] ?? '');
        $password = (string) ($connection['smtp_password'] ?? '');
        $from = (string) ($connection['account_email'] ?? '');
        if (
            $host === '' || $port <= 0 || $username === '' || $password === '' || $from === ''
            || !filter_var($from, FILTER_VALIDATE_EMAIL)
        ) {
            throw new RuntimeException('SMTP-Konfiguration ist unvollständig.');
        }

        $scheme = $encryption === 'ssl' ? 'smtps' : 'smtp';
        $query = [
            'verify_peer' => '1',
            'verify_peer_name' => '1',
            'allow_self_signed' => '0',
        ];
        if (in_array($encryption, ['tls', 'starttls'], true)) {
            $query['encryption'] = 'tls';
        }
        $dsn = sprintf(
            '%s://%s:%s@%s:%d?%s',
            $scheme,
            rawurlencode($username),
            rawurlencode($password),
            $host,
            $port,
            http_build_query($query, '', '&', PHP_QUERY_RFC3986)
        );

        $email = new Email();
        $email->from(new Address($from));

        $to = is_array($payload['to'] ?? null) ? $payload['to'] : [];
        $cc = is_array($payload['cc'] ?? null) ? $payload['cc'] : [];
        $bcc = is_array($payload['bcc'] ?? null) ? $payload['bcc'] : [];
        $email->to(...$this->normalizeAddresses($to));
        if ($cc !== []) {
            $email->cc(...$this->normalizeAddresses($cc));
        }
        if ($bcc !== []) {
            $email->bcc(...$this->normalizeAddresses($bcc));
        }
        $email->subject((string) ($payload['subject'] ?? '(ohne Betreff)'));
        $email->text((string) ($payload['body_plain'] ?? ''));
        if (trim((string) ($payload['body_html'] ?? '')) !== '') {
            $email->html((string) $payload['body_html']);
        }

        $inReplyTo = trim((string) ($payload['in_reply_to'] ?? ''));
        if ($inReplyTo !== '') {
            $email->getHeaders()->addTextHeader('In-Reply-To', $inReplyTo);
        }
        $references = trim((string) ($payload['references'] ?? ''));
        if ($references !== '') {
            $email->getHeaders()->addTextHeader('References', $references);
        }

        try {
            $transport = Transport::fromDsn($dsn);
            $mailer = new Mailer($transport);
            $mailer->send($email);
        } catch (TransportExceptionInterface $exception) {
            throw new RuntimeException('SMTP-Versand fehlgeschlagen.');
        } catch (\Throwable $exception) {
            throw new RuntimeException('SMTP-Versand fehlgeschlagen.');
        }
    }

    /**
     * @param array<string, mixed> $connection
     */
    private function buildImapClient(array $connection): \Webklex\PHPIMAP\Client
    {
        $host = (string) ($connection['imap_host'] ?? '');
        $port = (int) ($connection['imap_port'] ?? 0);
        $encryption = strtolower((string) ($connection['imap_encryption'] ?? 'tls'));
        $username = (string) ($connection['imap_username'] ?? '');
        $password = (string) ($connection['imap_password'] ?? '');
        if ($host === '' || $port <= 0 || $username === '' || $password === '') {
            throw new RuntimeException('IMAP-Konfiguration ist unvollständig.');
        }

        $config = [
            'default' => 'runtime',
            'accounts' => [
                'runtime' => [
                    'host' => $host,
                    'port' => $port,
                    'protocol' => 'imap',
                    'encryption' => $encryption,
                    'validate_cert' => true,
                    'username' => $username,
                    'password' => $password,
                    'authentication' => null,
                    'timeout' => 20,
                    'extensions' => [],
                ],
            ],
            'options' => [
                'fetch' => \Webklex\PHPIMAP\IMAP::FT_PEEK,
                'sequence' => \Webklex\PHPIMAP\IMAP::ST_UID,
                'fetch_flags' => true,
                'fetch_body' => false,
            ],
        ];

        try {
            $manager = new ClientManager($config);
            $client = $manager->account('runtime');
            $client->connect();
            return $client;
        } catch (\Throwable $exception) {
            throw new RuntimeException('IMAP-Verbindung fehlgeschlagen.');
        }
    }

    private function resolveFolder(\Webklex\PHPIMAP\Client $client, string $folder): \Webklex\PHPIMAP\Folder
    {
        $folder = trim($folder);
        if ($folder === '') {
            throw new RuntimeException('Ordnername fehlt.');
        }

        $folderObj = $client->getFolderByPath($folder, false, true);
        if ($folderObj === null) {
            $folderObj = $client->getFolder($folder);
        }
        if ($folderObj === null) {
            throw new RuntimeException('Ordner nicht gefunden.');
        }
        return $folderObj;
    }

    /**
     * @param array<int, string> $addresses
     * @return array<int, Address>
     */
    private function normalizeAddresses(array $addresses): array
    {
        $result = [];
        foreach ($addresses as $address) {
            $value = strtolower(trim((string) $address));
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $result[] = new Address($value);
        }
        if ($result === []) {
            throw new RuntimeException('Ungültige Empfängeradresse.');
        }
        return $result;
    }

    private function normalizeSubject(string $subject): string
    {
        $subject = trim($subject);
        return $subject === '' ? '(ohne Betreff)' : $subject;
    }

    /**
     * @param object $message
     * @return array{key:string,label:string}
     */
    private function parseSender(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['key' => 'unknown', 'label' => 'Unbekannt'];
        }

        if (preg_match('/<([^>]+)>/', $raw, $matches) === 1) {
            $email = strtolower(trim((string) $matches[1]));
            $label = trim(str_replace($matches[0], '', $raw), " \t\n\r\0\x0B\"'");
            return [
                'key' => $email,
                'label' => $label !== '' ? $label . ' <' . $email . '>' : $email,
            ];
        }

        $email = strtolower($raw);
        return ['key' => $email, 'label' => $raw];
    }

    /**
     * @param array<int, string> $flags
     */
    private function isSeenFlags(array $flags): bool
    {
        foreach ($flags as $flag) {
            $value = strtolower(trim((string) $flag, "\\ \t\n\r\0\x0B"));
            if ($value === 'seen') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function parseHeaderMap(string $rawHeader): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $rawHeader) ?: [];
        $headers = [];
        $current = '';

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            if (($line[0] ?? '') === ' ' || ($line[0] ?? '') === "\t") {
                if ($current !== '' && isset($headers[$current])) {
                    $lastIndex = count($headers[$current]) - 1;
                    if ($lastIndex >= 0) {
                        $headers[$current][$lastIndex] .= ' ' . trim($line);
                    }
                }
                continue;
            }

            $position = strpos($line, ':');
            if ($position === false) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $position)));
            $value = trim(substr($line, $position + 1));
            if ($name === '') {
                continue;
            }
            $current = $name;
            $headers[$name] ??= [];
            $headers[$name][] = $value;
        }

        return $headers;
    }

    /**
     * @param array<string, array<int, string>> $headers
     */
    private function firstHeaderValue(array $headers, string $name): string
    {
        $values = $headers[strtolower($name)] ?? [];
        if (!is_array($values) || $values === []) {
            return '';
        }

        return (string) ($values[0] ?? '');
    }

    private function decodeMimeHeaderValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if (is_string($decoded) && trim($decoded) !== '') {
                return trim($decoded);
            }
        }

        return $value;
    }

    /**
     * @param array<int, int> $uids
     * @return array<int, array<string, mixed>>
     */
    private function fetchFolderMessageMetadata(object $protocol, array $uids): array
    {
        if ($uids === []) {
            return [];
        }

        $headerRows = (array) $protocol->headers($uids, 'RFC822', IMAP::ST_UID)->validatedData();
        $flagRows = (array) $protocol->flags($uids, IMAP::ST_UID)->validatedData();

        $metadata = [];
        foreach ($uids as $uid) {
            $rawHeader = (string) ($headerRows[$uid] ?? '');
            if ($rawHeader === '') {
                continue;
            }

            $headers = $this->parseHeaderMap($rawHeader);
            $fromRaw = $this->decodeMimeHeaderValue($this->firstHeaderValue($headers, 'from'));
            $subject = $this->decodeMimeHeaderValue($this->firstHeaderValue($headers, 'subject'));
            $dateRaw = $this->decodeMimeHeaderValue($this->firstHeaderValue($headers, 'date'));
            $messageId = $this->decodeMimeHeaderValue($this->firstHeaderValue($headers, 'message-id'));

            $sender = $this->parseSender($fromRaw);
            $timestamp = strtotime($dateRaw);
            if (!is_int($timestamp) || $timestamp <= 0) {
                $timestamp = 0;
            }

            $metadata[$uid] = [
                'uid' => $uid,
                'message_id' => trim($messageId),
                'subject' => $this->normalizeSubject($subject),
                'sender_key' => $sender['key'],
                'sender_label' => $sender['label'],
                'timestamp' => $timestamp,
                'date_label' => $timestamp > 0 ? date('Y-m-d H:i', $timestamp) : '-',
                'is_read' => $this->isSeenFlags((array) ($flagRows[$uid] ?? [])),
            ];
        }

        return $metadata;
    }

    /**
     * @param array<int, int> $uids
     * @return array{selected_uids: array<int,int>, has_more: bool, next_until_uid: ?int}
     */
    private function paginateByUidCumulative(array $uids, int $limit, ?int $untilUid, string $direction): array
    {
        $limit = max(1, min(100, $limit));
        sort($uids, SORT_NUMERIC);

        $selectedUids = [];
        $hasMore = false;
        $nextUntilUid = null;
        if ($direction === 'asc') {
            if ($untilUid !== null && $untilUid > 0) {
                foreach ($uids as $uid) {
                    if ($uid <= $untilUid) {
                        $selectedUids[] = $uid;
                    }
                }
            } else {
                $selectedUids = array_slice($uids, 0, $limit);
            }

            if ($selectedUids !== []) {
                $currentMaxUid = max($selectedUids);
                $newerUids = [];
                foreach ($uids as $uid) {
                    if ($uid > $currentMaxUid) {
                        $newerUids[] = $uid;
                    }
                }
                if ($newerUids !== []) {
                    $hasMore = true;
                    $nextBatch = array_slice($newerUids, 0, $limit);
                    $nextUntilUid = (int) max($nextBatch);
                }
            }
        } else {
            if ($untilUid !== null && $untilUid > 0) {
                foreach ($uids as $uid) {
                    if ($uid >= $untilUid) {
                        $selectedUids[] = $uid;
                    }
                }
            } else {
                $selectedUids = array_slice($uids, -$limit);
            }

            if ($selectedUids !== []) {
                $currentMinUid = min($selectedUids);
                $olderUids = [];
                foreach ($uids as $uid) {
                    if ($uid < $currentMinUid) {
                        $olderUids[] = $uid;
                    }
                }
                if ($olderUids !== []) {
                    $hasMore = true;
                    $nextBatch = array_slice($olderUids, -$limit);
                    $nextUntilUid = (int) min($nextBatch);
                }
            }

            rsort($selectedUids, SORT_NUMERIC);
        }

        return [
            'selected_uids' => $selectedUids,
            'has_more' => $hasMore,
            'next_until_uid' => $nextUntilUid,
        ];
    }

    /**
     * @param object $message
     */
    private function isSeen(object $message): bool
    {
        try {
            $flags = $message->getFlags()->toArray();
            foreach ($flags as $flag) {
                $value = strtolower(trim((string) $flag, "\\ \t\n\r\0\x0B"));
                if ($value === 'seen') {
                    return true;
                }
            }
        } catch (\Throwable) {
        }

        return false;
    }
}
