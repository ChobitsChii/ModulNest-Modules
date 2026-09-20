<?php

declare(strict_types=1);

namespace ModulNest\MailClient\Imap;

use ModulNest\MailClient\Repository\FolderCacheRepository;
use ModulNest\MailClient\Repository\MessageIndexRepository;
use RuntimeException;
use Webklex\PHPIMAP\Client;

/**
 * IMAP-Sync-Service.
 *
 * Synchronisiert einen Ordner zwischen IMAP-Server und lokalem Header-Cache.
 *
 * Sync-Strategie:
 * 1. UIDVALIDITY prüfen → hat sie sich geändert, kompletten Cache löschen
 * 2. UID-Diff → UIDs, die im IMAP nicht mehr vorhanden sind, aus Cache entfernen
 * 3. Neue UIDs → Header-Daten laden und cachen
 * 4. Flag-Sync → geänderte Flags (gelesen, beantwortet, markiert) updaten
 */
final class ImapSyncService
{
    /** Maximale Anzahl an UIDs, die pro Sync-Vorgang als Headers geladen werden. */
    private const MAX_HEADERS_PER_SYNC = 100;

    public function __construct(
        private readonly MessageIndexRepository $messageIndex,
        private readonly FolderCacheRepository  $folderCache,
    ) {
    }

    /**
     * Listet alle Ordner eines Kontos auf (direkt vom IMAP-Server).
     *
     * @param array<string, mixed> $account
     * @return array<int, array<string, mixed>> [{name, delimiter, attributes}, ...]
     */
    public function listFolders(array $account): array
    {
        $client = ImapConnectionFactory::connect($account);
        try {
            $folders = $client->getFolders(false);
            $result  = [];
            foreach ($folders as $folder) {
                if (!is_object($folder)) {
                    continue;
                }
                $name = trim((string) ($folder->path ?? $folder->name ?? ''));
                if ($name === '') {
                    continue;
                }
                $result[] = [
                    'name'       => $name,
                    'delimiter'  => (string) ($folder->delimiter ?? '.'),
                    'attributes' => [],
                ];
            }
            // Alphabetisch sortieren, aber INBOX immer zuerst
            usort($result, static function (array $a, array $b): int {
                if (strtolower($a['name']) === 'inbox') {
                    return -1;
                }
                if (strtolower($b['name']) === 'inbox') {
                    return 1;
                }
                return strnatcasecmp($a['name'], $b['name']);
            });
            return $result;
        } catch (\Throwable $e) {
            throw new RuntimeException('Ordner konnten nicht geladen werden: ' . $e->getMessage(), 0, $e);
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Synchronisiert einen Ordner:
     * - Prüft UIDVALIDITY
     * - Entfernt nicht mehr vorhandene UIDs aus dem Cache
     * - Lädt Header für neue UIDs
     * - Synct Flags für bereits gecachte UIDs
     *
     * @param array<string, mixed> $account
     * @return array{synced: int, removed: int, uidvalidity: int, uidnext: int, total: int, unseen: int}
     */
    public function syncFolder(int $userId, int $accountId, array $account, string $folder): array
    {
        $client = ImapConnectionFactory::connect($account);
        try {
            return $this->doSync($client, $userId, $accountId, $account, $folder);
        } catch (\Throwable $e) {
            throw new RuntimeException('Ordner-Sync fehlgeschlagen: ' . $e->getMessage(), 0, $e);
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Gibt gecachte Nachrichten zurück (mit optionalem Hintergrund-Sync).
     *
     * @param array<string, mixed> $account
     * @return array{messages: array<int, array<string, mixed>>, total: int, unseen: int, synced_new: int, removed: int}
     */
    public function getMessages(
        int $userId,
        int $accountId,
        array $account,
        string $folder,
        int $limit = 50,
        int $offset = 0,
        bool $forceSync = false
    ): array {
        // Leichter Sync um die Liste aktuell zu halten
        $syncResult = ['synced' => 0, 'removed' => 0, 'total' => 0, 'unseen' => 0];
        try {
            $syncResult = $this->syncFolder($userId, $accountId, $account, $folder);
        } catch (\Throwable) {
            // Sync-Fehler sind nicht fatal – wir liefern die gecachten Daten
        }

        $messages = $this->messageIndex->listForFolder($accountId, $folder, $limit, $offset);

        return [
            'messages'   => $messages,
            'total'      => $syncResult['total'],
            'unseen'     => $syncResult['unseen'],
            'synced_new' => $syncResult['synced'],
            'removed'    => $syncResult['removed'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $account */
    private function doSync(Client $client, int $userId, int $accountId, array $account, string $folder): array
    {
        // Ordner öffnen
        $folderObj = null;
        foreach ($client->getFolders(false) as $f) {
            if (is_object($f) && strtolower(trim((string) ($f->path ?? $f->name ?? ''))) === strtolower($folder)) {
                $folderObj = $f;
                break;
            }
        }
        if ($folderObj === null) {
            throw new RuntimeException("Ordner '{$folder}' nicht gefunden.");
        }

        // STATUS abrufen
        $folderObj->query(); // Ordner selektieren
        $protocol    = $client->getConnection();
        $status      = (object) ($protocol->getStatus([
            'MESSAGES', 'UNSEEN', 'UIDVALIDITY', 'UIDNEXT'
        ])?->validatedData() ?? []);

        $total       = (int) ($status->MESSAGES ?? 0);
        $unseen      = (int) ($status->UNSEEN ?? 0);
        $uidvalidity = (int) ($status->UIDVALIDITY ?? 0);
        $uidnext     = (int) ($status->UIDNEXT ?? 0);

        // UIDVALIDITY-Check: hat sich der Ordner komplett geändert?
        $cachedFolders = $this->folderCache->listFoldersForAccount($accountId);
        $cachedUidvalidity = 0;
        foreach ($cachedFolders as $cf) {
            if ($cf['folder_name'] === $folder) {
                $cachedUidvalidity = (int) $cf['uidvalidity'];
                break;
            }
        }
        if ($cachedUidvalidity !== 0 && $cachedUidvalidity !== $uidvalidity) {
            // UIDVALIDITY hat sich geändert → Cache komplett invalidieren
            $this->messageIndex->clearFolder($accountId, $folder);
        }

        // Ordner-Cache updaten
        $this->folderCache->upsertFolder($userId, $accountId, $folder, $total, $unseen, $uidvalidity, $uidnext);

        // Alle UIDs vom Server holen
        $uidMap = (array) ($protocol->getUid()?->validatedData() ?? []);
        $serverUids = [];
        foreach ($uidMap as $uid) {
            $uidInt = (int) $uid;
            if ($uidInt > 0) {
                $serverUids[] = $uidInt;
            }
        }
        $serverUids = array_values(array_unique($serverUids));
        sort($serverUids, SORT_NUMERIC);

        // UIDs aus dem Cache
        $cachedUids = $this->messageIndex->listUidsForFolder($accountId, $folder);

        // Veraltete UIDs entfernen (auf anderem Gerät gelöscht/verschoben)
        $removed = 0;
        if ($serverUids !== []) {
            $removed = $this->messageIndex->removeStaleUids($accountId, $folder, $serverUids);
        } elseif ($total === 0) {
            $this->messageIndex->clearFolder($accountId, $folder);
            $removed = count($cachedUids);
        }

        // Neue UIDs bestimmen
        $cachedUidSet = array_flip($cachedUids);
        $newUids = [];
        foreach ($serverUids as $uid) {
            if (!isset($cachedUidSet[$uid])) {
                $newUids[] = $uid;
            }
        }

        // Header für neue UIDs laden (neueste zuerst, max. MAX_HEADERS_PER_SYNC)
        $synced = 0;
        if ($newUids !== []) {
            // Neueste UIDs zuerst
            $toFetch = array_slice(array_reverse($newUids), 0, self::MAX_HEADERS_PER_SYNC);
            $synced  = $this->fetchAndCacheHeaders($protocol, $userId, $accountId, $folder, $toFetch);
        }

        // Flag-Sync für bereits gecachte UIDs (nur die letzten 200 um nicht zu überlasten)
        $this->syncFlags($protocol, $accountId, $folder, array_slice(array_reverse($cachedUids), 0, 200));

        return [
            'synced'      => $synced,
            'removed'     => $removed,
            'uidvalidity' => $uidvalidity,
            'uidnext'     => $uidnext,
            'total'       => $total,
            'unseen'      => $unseen,
        ];
    }

    /**
     * Lädt Header-Metadaten für eine Liste von UIDs und speichert sie im Cache.
     *
     * @param array<int, int> $uids
     */
    private function fetchAndCacheHeaders(
        object $protocol,
        int    $userId,
        int    $accountId,
        string $folder,
        array  $uids
    ): int {
        if ($uids === []) {
            return 0;
        }

        $synced = 0;
        // UIDs in Chunks aufteilen um den Server nicht zu überlasten
        $chunks = array_chunk($uids, 20);
        foreach ($chunks as $chunk) {
            $uidList = implode(',', $chunk);
            try {
                // FETCH: Envelope, Flags, RFC822.SIZE
                $response = $protocol->fetch(
                    ['ENVELOPE', 'FLAGS', 'RFC822.SIZE', 'BODYSTRUCTURE'],
                    $uidList,
                    true // UID-Fetch
                );
                $fetchData = is_object($response) ? (array) $response->validatedData() : [];
                foreach ($fetchData as $seqNum => $msgData) {
                    if (!is_object($msgData) && !is_array($msgData)) {
                        continue;
                    }
                    $msg = is_object($msgData) ? (array) $msgData : $msgData;
                    $uid = (int) ($msg['UID'] ?? 0);
                    if ($uid === 0) {
                        continue;
                    }

                    $envelope = is_object($msg['ENVELOPE'] ?? null) ? $msg['ENVELOPE'] : null;
                    $flags    = is_array($msg['FLAGS'] ?? null) ? $msg['FLAGS'] : [];

                    // Absender ermitteln
                    $senderAddress = '';
                    $senderName    = '';
                    if ($envelope !== null) {
                        $from = $envelope->from ?? null;
                        if (is_array($from) && isset($from[0])) {
                            $f = $from[0];
                            if (is_object($f)) {
                                $mb   = (string) ($f->mailbox ?? '');
                                $host = (string) ($f->host ?? '');
                                if ($mb !== '' && $host !== '') {
                                    $senderAddress = $mb . '@' . $host;
                                }
                                $senderName = $this->decodeMimeHeader((string) ($f->personal ?? ''));
                            }
                        }
                    }

                    // Betreff dekodieren
                    $subjectRaw = '';
                    if ($envelope !== null) {
                        $subjectRaw = $this->decodeMimeHeader((string) ($envelope->subject ?? ''));
                    }

                    // Datum
                    $dateRaw    = $envelope !== null ? (string) ($envelope->date ?? '') : '';
                    $timestamp  = $dateRaw !== '' ? (int) @strtotime($dateRaw) : 0;

                    // Flags
                    $flagBits = 0;
                    $flagStr  = strtolower(implode(' ', $flags));
                    if (str_contains($flagStr, '\\seen'))     { $flagBits |= MessageIndexRepository::FLAG_SEEN; }
                    if (str_contains($flagStr, '\\answered')) { $flagBits |= MessageIndexRepository::FLAG_ANSWERED; }
                    if (str_contains($flagStr, '\\flagged'))  { $flagBits |= MessageIndexRepository::FLAG_FLAGGED; }
                    if (str_contains($flagStr, '\\deleted'))  { $flagBits |= MessageIndexRepository::FLAG_DELETED; }
                    if (str_contains($flagStr, '\\draft'))    { $flagBits |= MessageIndexRepository::FLAG_DRAFT; }

                    // Anhänge prüfen (via BODYSTRUCTURE)
                    $hasAttachments = 0;
                    $bodyStructure = $msg['BODYSTRUCTURE'] ?? null;
                    if ($bodyStructure !== null) {
                        $hasAttachments = $this->hasAttachments($bodyStructure) ? 1 : 0;
                    }

                    $this->messageIndex->upsert($userId, $accountId, $folder, [
                        'uid'             => $uid,
                        'message_id'      => $envelope !== null ? (string) ($envelope->message_id ?? '') : '',
                        'sender_address'  => strtolower($senderAddress),
                        'sender_name'     => $senderName,
                        'subject'         => $subjectRaw,
                        'message_date'    => $timestamp > 0 ? $timestamp : time(),
                        'flags'           => $flagBits,
                        'size_bytes'      => (int) ($msg['RFC822.SIZE'] ?? 0),
                        'has_attachments' => $hasAttachments,
                    ]);
                    $synced++;
                }
            } catch (\Throwable) {
                // Einzelne UIDs dürfen den gesamten Sync nicht abbrechen
            }
        }

        return $synced;
    }

    /**
     * Synct Flags für bereits gecachte UIDs.
     * @param array<int, int> $uids
     */
    private function syncFlags(object $protocol, int $accountId, string $folder, array $uids): void
    {
        if ($uids === []) {
            return;
        }
        $chunks = array_chunk($uids, 50);
        foreach ($chunks as $chunk) {
            try {
                $uidList  = implode(',', $chunk);
                $response = $protocol->fetch(['FLAGS'], $uidList, true);
                $data     = is_object($response) ? (array) $response->validatedData() : [];
                foreach ($data as $msgData) {
                    $msg   = is_object($msgData) ? (array) $msgData : (array) $msgData;
                    $uid   = (int) ($msg['UID'] ?? 0);
                    $flags = is_array($msg['FLAGS'] ?? null) ? $msg['FLAGS'] : [];
                    if ($uid === 0) {
                        continue;
                    }
                    $flagStr = strtolower(implode(' ', $flags));
                    $bits    = 0;
                    if (str_contains($flagStr, '\\seen'))     { $bits |= MessageIndexRepository::FLAG_SEEN; }
                    if (str_contains($flagStr, '\\answered')) { $bits |= MessageIndexRepository::FLAG_ANSWERED; }
                    if (str_contains($flagStr, '\\flagged'))  { $bits |= MessageIndexRepository::FLAG_FLAGGED; }
                    if (str_contains($flagStr, '\\deleted'))  { $bits |= MessageIndexRepository::FLAG_DELETED; }
                    if (str_contains($flagStr, '\\draft'))    { $bits |= MessageIndexRepository::FLAG_DRAFT; }
                    $this->messageIndex->updateFlags($accountId, $folder, $uid, $bits);
                }
            } catch (\Throwable) {
                // Flags-Sync-Fehler sind nicht fatal
            }
        }
    }

    /** Prüft ob eine BODYSTRUCTURE-Antwort Anhänge enthält. */
    private function hasAttachments(mixed $bodyStructure): bool
    {
        if (is_object($bodyStructure)) {
            $disp = strtolower((string) ($bodyStructure->disposition ?? ''));
            if ($disp === 'attachment') {
                return true;
            }
            // Unterteile rekursiv prüfen
            if (isset($bodyStructure->parts) && is_array($bodyStructure->parts)) {
                foreach ($bodyStructure->parts as $part) {
                    if ($this->hasAttachments($part)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /** Dekodiert MIME-encodierte Header-Werte (z.B. =?UTF-8?B?...?=). */
    private function decodeMimeHeader(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        try {
            $decoded = imap_mime_header_decode($raw);
            if (!is_array($decoded)) {
                return mb_convert_encoding($raw, 'UTF-8', 'auto');
            }
            $result = '';
            foreach ($decoded as $part) {
                if (is_object($part)) {
                    $charset = strtolower((string) ($part->charset ?? 'default'));
                    $text    = (string) ($part->text ?? '');
                    if ($charset === 'default' || $charset === 'utf-8') {
                        $result .= $text;
                    } else {
                        $result .= mb_convert_encoding($text, 'UTF-8', $charset) ?: $text;
                    }
                }
            }
            return $result;
        } catch (\Throwable) {
            return $raw;
        }
    }
}

