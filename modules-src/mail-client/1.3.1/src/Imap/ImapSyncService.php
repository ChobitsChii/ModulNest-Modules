<?php

declare(strict_types=1);

namespace ModulNest\MailClient\Imap;

use ModulNest\MailClient\Repository\FolderCacheRepository;
use ModulNest\MailClient\Repository\MessageIndexRepository;
use ModulNest\MailClient\Sync\SyncProgressRepository;

/**
 * IMAP-Sync-Service: Zweistufige Sync-Strategie
 *
 * 1. quickSync()    – Neueste 50 UIDs sofort laden (< 2 Sek.) → für erste Anzeige
 * 2. continueSync() – Nächsten Batch im Hintergrund laden (max. 15 Sek.) → per Polling
 *
 * Alle Header werden gecacht, Bodies NICHT.
 */
final class ImapSyncService
{
    private const QUICK_SYNC_LIMIT    = 50;
    private const BATCH_SIZE          = 25;
    private const CONTINUE_TIMEOUT    = 5; // 5 Sekunden pro continueSync()-Aufruf für unterbrechungsfreien Hintergrundsync
    private const FLAG_SYNC_LIMIT     = 200;

    public function __construct(
        private readonly MessageIndexRepository $messageIndex,
        private readonly FolderCacheRepository  $folderCache,
        private readonly SyncProgressRepository $syncProgress,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Öffentliche API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Gibt gecachte Nachrichten zurück UND startet ggf. quickSync.
     * Nutzt gecachten Stand wenn vorhanden, lädt bei Bedarf On-Demand Seiten nach.
     *
     * @param array<string,mixed> $accountConn
     * @return array{messages: list<array>, total: int, unseen: int}
     */
    public function getMessages(int $userId, int $accountId, array $accountConn, string $folder, int $limit, int $offset): array
    {
        $cachedFolder = $this->folderCache->findFolder($accountId, $folder);
        $totalCached  = $this->messageIndex->countForFolder($accountId, $folder);

        // Wenn noch gar nichts gecacht ist oder Ordner-Cache fehlt → direkt Quick-Sync ausführen
        if ($totalCached === 0 || $cachedFolder === null) {
            $this->quickSync($accountId, $accountConn, $folder);
            $cachedFolder = $this->folderCache->findFolder($accountId, $folder);
            $totalCached  = $this->messageIndex->countForFolder($accountId, $folder);
        }

        $total  = (int) ($cachedFolder['total_messages'] ?? $totalCached);
        $unseen = (int) ($cachedFolder['unseen_messages'] ?? $this->messageIndex->countUnseenInFolder($accountId, $folder));

        $cached = $this->messageIndex->listForFolder($accountId, $folder, $limit, $offset);

        // Prüfen, ob für die aktuelle Seite noch Empfänger-Daten fehlen oder die Seite noch nicht gecacht ist:
        $pageUids = array_column($cached, 'uid');
        $missingRecipients = !empty($pageUids) ? $this->messageIndex->listUidsMissingRecipient($accountId, $folder, $pageUids) : [];

        if (!empty($missingRecipients) || (empty($cached) && $offset < $total)) {
            $this->syncPageSlice($userId, $accountId, $accountConn, $folder, $limit, $offset);
            $cached = $this->messageIndex->listForFolder($accountId, $folder, $limit, $offset);
            $pageUids = array_column($cached, 'uid');
        }

        // Live-Flags mit IMAP nur für die 1. Seite ($offset === 0) synchronisieren, wenn keine Empfänger fehlten.
        // Bei Paginierung (höhere Seiten) werden die in der lokalen Datenbank gecachten Flags genutzt,
        // um 1-2 Sekunden Verzögerung durch erneuten IMAP-Verbindungsaufbau beim Blättern zu vermeiden.
        if (!empty($pageUids) && $offset === 0 && empty($missingRecipients)) {
            $liveFlags = $this->syncFlagsForUids($accountConn, $folder, $pageUids, $accountId);
            if (!empty($liveFlags)) {
                foreach ($cached as &$row) {
                    $u = (int) $row['uid'];
                    if (isset($liveFlags[$u])) {
                        $row['flags'] = $liveFlags[$u];
                    }
                }
                unset($row);
            }
        }

        return [
            'messages' => $cached,
            'total'    => $total,
            'unseen'   => $unseen,
        ];
    }

    /**
     * Lädt gezielt eine bestimmte Seite von UIDs vom IMAP-Server nach,
     * falls der Hintergrund-Sync diesen Bereich noch nicht erreicht hat.
     *
     * @param array<string,mixed> $accountConn
     */
    public function syncPageSlice(int $userId, int $accountId, array $accountConn, string $folder, int $limit, int $offset): void
    {
        try {
            $client = ImapConnectionFactory::connect($accountConn);
            try {
                $imapFolder = $this->findImapFolder($client, $folder);
                if ($imapFolder === null) {
                    return;
                }
                $allUids = $this->fetchAllUids($imapFolder);
                rsort($allUids, SORT_NUMERIC);
                $pageUids = array_slice($allUids, $offset, $limit);
                if (empty($pageUids)) {
                    return;
                }

                $cachedUids           = $this->messageIndex->listUidsForFolder($accountId, $folder);
                $cachedSet            = array_flip($cachedUids);
                $missingUids          = array_values(array_filter($pageUids, static fn (int $uid): bool => !isset($cachedSet[$uid])));
                $missingRecipientUids = $this->messageIndex->listUidsMissingRecipient($accountId, $folder, $pageUids);
                $uidsToFetch          = array_values(array_unique(array_merge($missingUids, $missingRecipientUids)));

                if (!empty($uidsToFetch)) {
                    $this->fetchAndCacheHeaders($imapFolder, $userId, $accountId, $folder, $uidsToFetch);
                }
            } finally {
                $client->disconnect();
                ImapConnectionFactory::flushImapErrors();
            }
        } catch (\Throwable) {
            // Im Fehlerfall still scheitern
        }
    }

    /**
     * Schneller Erstsync / Ordner-Aktualisierung: Holt die neuesten 50 UIDs + ihre Header.
     * Startet außerdem den Hintergrund-Sync (speichert pending_uids).
     *
     * @param array<string,mixed> $accountConn
     */
    public function quickSync(int $accountId, array $accountConn, string $folder): void
    {
        $userId = (int) ($accountConn['user_id'] ?? 0);
        $client = ImapConnectionFactory::connect($accountConn);
        try {
            $imapFolder = $this->findImapFolder($client, $folder);
            if ($imapFolder === null) {
                return;
            }

            // Ordner-Status & UIDVALIDITY prüfen via examine() (ohne leeren IMAP SEARCH-Befehl)
            $examine     = $imapFolder->examine();
            $uidValidity = (int) ($examine['uidvalidity'] ?? $this->getUidValidity($imapFolder));
            $cachedFolder = $this->folderCache->findFolder($accountId, $folder);

            if ($cachedFolder !== null && (int)$cachedFolder['uidvalidity'] !== 0 && $uidValidity !== 0 && (int)$cachedFolder['uidvalidity'] !== $uidValidity) {
                // UIDVALIDITY geändert → alles invalidieren
                $this->messageIndex->clearFolder($accountId, $folder);
            }

            // Alle UIDs holen (FETCH 1:* (UID) – RFC-konform und schnell, auch bei 50k UIDs)
            $allUids = $this->fetchAllUids($imapFolder);
            if (empty($allUids)) {
                $this->syncProgress->markComplete($accountId, $folder, 0);
                $this->folderCache->upsertFolder($userId, $accountId, $folder, 0, 0, $uidValidity, (int) ($examine['uidnext'] ?? 0));
                return;
            }

            // UIDs absteigend sortieren (neueste zuerst)
            rsort($allUids, SORT_NUMERIC);
            $totalUids = count($allUids);

            // Bereits gecachte UIDs ermitteln
            $cachedUids = $this->messageIndex->listUidsForFolder($accountId, $folder);
            $cachedSet  = array_flip($cachedUids);

            // Pending: alle die noch nicht gecacht sind
            $pendingUids = array_values(array_filter($allUids, static fn (int $uid): bool => !isset($cachedSet[$uid])));

            // Neueste 50 sofort fetchen (aus pending oder direkt)
            $quickBatch = array_slice($pendingUids, 0, self::QUICK_SYNC_LIMIT);
            if (!empty($quickBatch)) {
                $this->fetchAndCacheHeaders($imapFolder, $userId, $accountId, $folder, $quickBatch);
            }

            // Restliche UIDs für continueSync speichern
            $remainingUids = array_slice($pendingUids, count($quickBatch));
            $syncedSoFar   = $this->messageIndex->countForFolder($accountId, $folder);

            if (empty($remainingUids)) {
                // Alles auf einmal geladen
                $this->syncProgress->markComplete($accountId, $folder, $syncedSoFar);
            } else {
                $this->syncProgress->initSync($accountId, $folder, $totalUids, $remainingUids);
                $this->syncProgress->updateProgress($accountId, $folder, $syncedSoFar, $remainingUids);
            }

            // Ordner-Cache aktualisieren (echte UNSEEN Anzahl via folderStatus abfragen)
            $unseenCount = 0;
            try {
                $st = (array) $imapFolder->getClient()->getConnection()->folderStatus($folder, ['UNSEEN'])->validatedData();
                $unseenCount = (int) ($st['unseen'] ?? 0);
            } catch (\Throwable) {
                $unseenCount = $this->messageIndex->countUnseenInFolder($accountId, $folder);
            }
            $uidNext = (int) ($examine['uidnext'] ?? 0);
            $this->folderCache->upsertFolder($userId, $accountId, $folder, $totalUids, $unseenCount, $uidValidity, $uidNext);

            // Flag-Sync für bereits bekannte Nachrichten
            $this->syncFlags($imapFolder, $accountId, $folder);

        } finally {
            $client->disconnect();
                ImapConnectionFactory::flushImapErrors();
        }
    }

    /**
     * Aktualisiert einen einzelnen Ordner schnell (neue Nachrichten + Flags + Unseen Counter).
     *
     * @param array<string,mixed> $accountConn
     * @return array{synced: bool, total: int, unseen: int}
     */
    public function refreshFolder(int $accountId, array $accountConn, string $folder): array
    {
        $this->quickSync($accountId, $accountConn, $folder);

        $cachedFolder = $this->folderCache->findFolder($accountId, $folder);
        $totalCached  = $this->messageIndex->countForFolder($accountId, $folder);

        return [
            'synced' => true,
            'total'  => (int) ($cachedFolder['total_messages'] ?? $totalCached),
            'unseen' => (int) ($cachedFolder['unseen_messages'] ?? $this->messageIndex->countUnseenInFolder($accountId, $folder)),
        ];
    }

    /**
     * Fortsetzungs-Sync: Verarbeitet nächste Batches bis MAX_TIMEOUT Sekunden erreicht.
     * Wird per Polling vom Frontend aufgerufen.
     *
     * @param array<string,mixed> $accountConn
     * @return array{synced_uids: int, total_uids: int, status: string, error?: string}
     */
    public function continueSync(int $accountId, array $accountConn, string $folder): array
    {
        $userId = (int) ($accountConn['user_id'] ?? 0);
        $progress = $this->syncProgress->get($accountId, $folder);
        $cachedFolder = $this->folderCache->findFolder($accountId, $folder);
        $cachedTotal  = (int) ($cachedFolder['total_messages'] ?? 0);

        if ($progress !== null && $progress['status'] === 'complete') {
            $synced = $this->messageIndex->countForFolder($accountId, $folder);
            $effectiveTotal = (int) ($progress['total_uids'] ?? 0);
            if ($effectiveTotal <= 0) {
                $effectiveTotal = $cachedTotal > 0 ? $cachedTotal : $synced;
            }
            return [
                'synced_uids' => $synced,
                'total_uids'  => $effectiveTotal,
                'status'      => 'complete',
            ];
        }

        // Falls noch gar kein Fortschritt existiert oder Status 'error' war:
        // Erstsync anstoßen bzw. Fehlerzustand auflösen
        if ($progress === null || $progress['status'] === 'error') {
            try {
                $this->quickSync($accountId, $accountConn, $folder);
                $progress = $this->syncProgress->get($accountId, $folder);
            } catch (\Throwable $e) {
                return [
                    'synced_uids' => $this->messageIndex->countForFolder($accountId, $folder),
                    'total_uids'  => $cachedTotal,
                    'status'      => 'error',
                    'error'       => $e->getMessage(),
                ];
            }
        }

        if ($progress === null || $progress['status'] === 'complete') {
            $synced = $this->messageIndex->countForFolder($accountId, $folder);
            return [
                'synced_uids' => $synced,
                'total_uids'  => (int) ($progress['total_uids'] ?? $synced),
                'status'      => 'complete',
            ];
        }

        $pendingUids = $this->syncProgress->getPendingUids($accountId, $folder);
        if (empty($pendingUids)) {
            $synced = $this->messageIndex->countForFolder($accountId, $folder);
            $this->syncProgress->markComplete($accountId, $folder, $synced);
            return ['synced_uids' => $synced, 'total_uids' => (int) ($progress['total_uids'] ?? $synced), 'status' => 'complete'];
        }

        $client = ImapConnectionFactory::connect($accountConn);
        try {
            $imapFolder = $this->findImapFolder($client, $folder);
            if ($imapFolder === null) {
                $this->syncProgress->markError($accountId, $folder, 'Ordner nicht gefunden.');
                return ['synced_uids' => 0, 'total_uids' => (int) ($progress['total_uids'] ?? 0), 'status' => 'error', 'error' => 'Ordner nicht gefunden.'];
            }

            $imapFolder->query(); // Ordner auf dem IMAP-Server selektieren

            $startTime = time();
            $remaining = $pendingUids;

            while (!empty($remaining) && (time() - $startTime) < self::CONTINUE_TIMEOUT) {
                $batch     = array_slice($remaining, 0, self::BATCH_SIZE);
                $remaining = array_slice($remaining, self::BATCH_SIZE);

                $this->fetchAndCacheHeaders($imapFolder, $userId, $accountId, $folder, $batch);
            }

            $syncedSoFar = $this->messageIndex->countForFolder($accountId, $folder);
            $totalUids   = (int) ($progress['total_uids'] ?? $syncedSoFar);

            if (empty($remaining)) {
                $this->syncProgress->markComplete($accountId, $folder, $syncedSoFar);
                return ['synced_uids' => $syncedSoFar, 'total_uids' => $totalUids, 'status' => 'complete'];
            }

            $this->syncProgress->updateProgress($accountId, $folder, $syncedSoFar, $remaining);
            return ['synced_uids' => $syncedSoFar, 'total_uids' => $totalUids, 'status' => 'syncing'];

        } catch (\Throwable $e) {
            $this->syncProgress->markError($accountId, $folder, $e->getMessage());
            return [
                'synced_uids' => $this->messageIndex->countForFolder($accountId, $folder),
                'total_uids'  => (int) ($progress['total_uids'] ?? 0),
                'status'      => 'error',
                'error'       => $e->getMessage(),
            ];
        } finally {
            $client->disconnect();
                ImapConnectionFactory::flushImapErrors();
        }
    }

    /**
     * Gibt den aktuellen Sync-Fortschritt aus der DB zurück (kein IMAP).
     *
     * @return array{synced_uids: int, total_uids: int, status: string}
     */
    public function getSyncProgress(int $accountId, string $folder): array
    {
        $row = $this->syncProgress->get($accountId, $folder);
        return [
            'synced_uids' => (int) ($row['synced_uids'] ?? $this->messageIndex->countForFolder($accountId, $folder)),
            'total_uids'  => (int) ($row['total_uids'] ?? 0),
            'status'      => (string) ($row['status'] ?? 'idle'),
        ];
    }

    /**
     * Listet Ordner live vom IMAP-Server.
     *
     * @param array<string,mixed> $accountConn
     * @return array<int, array{name: string, special: string, unseen: int, total: int}>
     */
    public function listFolders(int $accountId, array $accountConn): array
    {
        $userId = (int) ($accountConn['user_id'] ?? 1);
        $client = ImapConnectionFactory::connect($accountConn);
        try {
            $protocol = $client->getConnection();
            $folders = [];
            foreach ($client->getFolders(false) as $folder) {
                if (!is_object($folder)) {
                    continue;
                }
                $name = (string) ($folder->path ?? $folder->name ?? '');
                if ($name === '') {
                    continue;
                }

                $unseen = 0;
                $total  = 0;
                try {
                    $st = (array) $protocol->folderStatus($name, ['MESSAGES', 'UNSEEN', 'UIDVALIDITY'])->validatedData();
                    $total       = (int) ($st['messages'] ?? 0);
                    $unseen      = (int) ($st['unseen'] ?? 0);
                    $uidvalidity = (int) ($st['uidvalidity'] ?? 0);
                    $this->folderCache->upsert($accountId, $name, [
                        'total_messages'  => $total,
                        'unseen_messages' => $unseen,
                        'uidvalidity'     => $uidvalidity,
                    ], $userId);
                } catch (\Throwable) {
                    $cached = $this->folderCache->findFolder($accountId, $name);
                    if ($cached !== null) {
                        $total  = (int) ($cached['total_messages'] ?? 0);
                        $unseen = (int) ($cached['unseen_messages'] ?? 0);
                    }
                }

                $delimiter = (string) ($folder->delimiter ?? '/');
                if ($delimiter === '') {
                    $delimiter = '/';
                }
                $folders[] = [
                    'name'      => $name,
                    'delimiter' => $delimiter,
                    'special'   => 'folder',
                    'unseen'    => $unseen,
                    'total'     => $total,
                ];
            }
            usort($folders, static function (array $a, array $b): int {
                $aIsInbox = strtolower($a['name']) === 'inbox';
                $bIsInbox = strtolower($b['name']) === 'inbox';
                if ($aIsInbox && !$bIsInbox) {
                    return -1;
                }
                if (!$aIsInbox && $bIsInbox) {
                    return 1;
                }
                return strcmp($a['name'], $b['name']);
            });
            return $folders;
        } finally {
            $client->disconnect();
                ImapConnectionFactory::flushImapErrors();
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private Helfer
    // ─────────────────────────────────────────────────────────────────────────

    private function findImapFolder(\Webklex\PHPIMAP\Client $client, string $folderName): ?\Webklex\PHPIMAP\Folder
    {
        foreach ($client->getFolders(false) as $f) {
            if (!is_object($f)) {
                continue;
            }
            $fName = (string) ($f->path ?? $f->name ?? '');
            if (strtolower(trim($fName)) === strtolower(trim($folderName))) {
                return $f;
            }
        }
        return null;
    }

    /** @return int[] */
    private function fetchAllUids(\Webklex\PHPIMAP\Folder $folder): array
    {
        try {
            $folder->query();
            $protocol = $folder->getClient()->getConnection();
            $uidMap = (array) $protocol->getUid()->validatedData();
            $uids = [];
            foreach ($uidMap as $uid) {
                $uidInt = (int) $uid;
                if ($uidInt > 0) {
                    $uids[] = $uidInt;
                }
            }
            return $uids;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param int[] $uids */
    private function fetchAndCacheHeaders(\Webklex\PHPIMAP\Folder $folder, int $userId, int $accountId, string $folderName, array $uids): void
    {
        if (empty($uids)) {
            return;
        }
        $validUids = array_values(array_filter($uids, static fn (int $uid): bool => $uid > 0));
        if ($validUids === []) {
            return;
        }

        $folder->query();
        $client   = $folder->getClient();
        $protocol = $client->getConnection();

        // In Chunks à BATCH_SIZE aufteilen um Memory und IMAP-Befehlslängen zu schonen
        foreach (array_chunk($validUids, self::BATCH_SIZE) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            try {
                $headerRows = (array) $protocol->headers($chunk, 'RFC822', \Webklex\PHPIMAP\IMAP::ST_UID)->validatedData();
                $flagRows   = (array) $protocol->flags($chunk, \Webklex\PHPIMAP\IMAP::ST_UID)->validatedData();
                $sizeRows   = (array) $protocol->sizes($chunk, \Webklex\PHPIMAP\IMAP::ST_UID)->validatedData();

                foreach ($chunk as $uid) {
                    $rawHeader = (string) ($headerRows[$uid] ?? '');
                    if ($rawHeader === '') {
                        continue;
                    }

                    $header = new \Webklex\PHPIMAP\Header($rawHeader, $client->getConfig());

                    $from    = $this->extractFirstAddress($header->get('from'));
                    $to      = $this->extractFirstAddress($header->get('to'));
                    $subject = $this->decodeHeader((string) ($header->get('subject') ?? ''));

                    $d = $header->get('date');
                    $firstDate = is_object($d) && method_exists($d, 'first') ? $d->first() : (is_array($d) ? ($d[0] ?? null) : $d);
                    $dateTs = $firstDate instanceof \Carbon\Carbon ? $firstDate->getTimestamp() : (int) strtotime((string) $d);
                    if ($dateTs < 0) {
                        $dateTs = 0;
                    }

                    $flags = 0;
                    foreach ((array) ($flagRows[$uid] ?? []) as $f) {
                        $fStr = strtolower(trim((string) $f, "\\ \t\n\r\0\x0B"));
                        if ($fStr === 'seen') $flags |= MessageIndexRepository::FLAG_SEEN;
                        if ($fStr === 'answered') $flags |= MessageIndexRepository::FLAG_ANSWERED;
                        if ($fStr === 'flagged') $flags |= MessageIndexRepository::FLAG_FLAGGED;
                        if ($fStr === 'deleted') $flags |= MessageIndexRepository::FLAG_DELETED;
                        if ($fStr === 'draft') $flags |= MessageIndexRepository::FLAG_DRAFT;
                    }

                    $contentType = strtolower((string) ($header->get('content_type') ?? ''));
                    $hasAttachments = str_contains($contentType, 'multipart/mixed') || str_contains($contentType, 'multipart/related');

                    $this->messageIndex->upsert($userId, $accountId, $folderName, [
                        'uid'             => $uid,
                        'message_id'      => (string) ($header->get('message_id') ?? ''),
                        'sender_name'       => $from['name'],
                        'sender_address'    => $from['address'],
                        'recipient_name'    => $to['name'],
                        'recipient_address' => $to['address'],
                        'subject'           => mb_substr($subject, 0, 255),
                        'message_date'    => $dateTs,
                        'flags'           => $flags,
                        'size_bytes'      => (int) ($sizeRows[$uid] ?? 0),
                        'has_attachments' => $hasAttachments ? 1 : 0,
                    ]);
                }
            } catch (\Throwable) {
                // Einzelne Batches dürfen scheitern ohne den Gesamt-Sync abzubrechen
            } finally {
                ImapConnectionFactory::flushImapErrors();
            }
        }
    }

    private function syncFlags(\Webklex\PHPIMAP\Folder $folder, int $accountId, string $folderName): void
    {
        $cachedUids = array_slice(
            $this->messageIndex->listUidsForFolder($accountId, $folderName),
            0,
            self::FLAG_SYNC_LIMIT
        );
        if (empty($cachedUids)) {
            return;
        }
        $folder->getClient()->openFolder($folder->path);
        $protocol = $folder->getClient()->getConnection();
        foreach (array_chunk($cachedUids, self::BATCH_SIZE) as $chunk) {
            try {
                $flagRows = (array) $protocol->flags($chunk, \Webklex\PHPIMAP\IMAP::ST_UID)->validatedData();
                foreach ($flagRows as $uid => $flagsList) {
                    $flags = 0;
                    foreach ((array) $flagsList as $flag) {
                        $f = strtolower(trim((string) $flag, "\\ \t\n\r\0\x0B"));
                        if ($f === 'seen') $flags |= MessageIndexRepository::FLAG_SEEN;
                        if ($f === 'answered') $flags |= MessageIndexRepository::FLAG_ANSWERED;
                        if ($f === 'flagged') $flags |= MessageIndexRepository::FLAG_FLAGGED;
                        if ($f === 'deleted') $flags |= MessageIndexRepository::FLAG_DELETED;
                        if ($f === 'draft') $flags |= MessageIndexRepository::FLAG_DRAFT;
                    }
                    $this->messageIndex->updateFlags($accountId, $folderName, (int) $uid, $flags);
                }
            } catch (\Throwable) {}
        }
    }

    /**
     * Gleicht die Flags für eine Liste von UIDs direkt mit dem IMAP-Server ab
     * und aktualisiert den lokalen Cache.
     *
     * @param array<string, mixed> $accountConn
     * @param list<int> $uids
     * @return array<int, int> [uid => flagsBitmask]
     */
    public function syncFlagsForUids(array $accountConn, string $folder, array $uids, int $accountId): array
    {
        if (empty($uids)) {
            return [];
        }
        $updatedFlags = [];
        try {
            $client = ImapConnectionFactory::connect($accountConn);
            try {
                $imapFolder = $this->findImapFolder($client, $folder);
                if ($imapFolder === null) {
                    return [];
                }
                $client->openFolder($imapFolder->path);
                $proto = $client->getConnection();
                $flagRows = (array) $proto->flags($uids, \Webklex\PHPIMAP\IMAP::ST_UID)->validatedData();
                foreach ($flagRows as $uid => $flagsList) {
                    $flags = 0;
                    foreach ((array) $flagsList as $flag) {
                        $f = strtolower(trim((string) $flag, "\\ \t\n\r\0\x0B"));
                        if ($f === 'seen') $flags |= MessageIndexRepository::FLAG_SEEN;
                        if ($f === 'answered') $flags |= MessageIndexRepository::FLAG_ANSWERED;
                        if ($f === 'flagged') $flags |= MessageIndexRepository::FLAG_FLAGGED;
                        if ($f === 'deleted') $flags |= MessageIndexRepository::FLAG_DELETED;
                        if ($f === 'draft') $flags |= MessageIndexRepository::FLAG_DRAFT;
                    }
                    $uidInt = (int) $uid;
                    $this->messageIndex->updateFlags($accountId, $folder, $uidInt, $flags);
                    $updatedFlags[$uidInt] = $flags;
                }
            } finally {
                $client->disconnect();
                ImapConnectionFactory::flushImapErrors();
            }
        } catch (\Throwable) {
            // Bei Verbindungsproblemen auf Cache zurückgreifen
        }
        return $updatedFlags;
    }

    private function hasFlag(object $msg, string $flag): bool
    {
        try {
            $flags = $msg->flags ?? [];
            if (is_object($flags)) {
                $flags = $flags->toArray();
            }
            if (!is_array($flags)) {
                return false;
            }
            foreach ($flags as $f) {
                if (str_contains(strtolower((string) $f), strtolower($flag))) {
                    return true;
                }
            }
        } catch (\Throwable) {}
        return false;
    }

    /** @param mixed $addresses */
    private function extractFirstAddress(mixed $addresses): array
    {
        $result = ['name' => '', 'address' => ''];
        try {
            if (is_string($addresses)) {
                $addresses = [$addresses];
            }
            if (is_object($addresses) && method_exists($addresses, 'toArray')) {
                $addresses = $addresses->toArray();
            }
            if (!is_array($addresses) || empty($addresses)) {
                return $result;
            }
            $first = $addresses[0] ?? null;
            if (is_object($first)) {
                $name = (string) ($first->personal ?? '');
                $addr = (string) ($first->mail ?? '');
                if ($addr === '') {
                    $mb   = (string) ($first->mailbox ?? '');
                    $host = (string) ($first->host ?? '');
                    if ($mb !== '' && $host !== '') {
                        $addr = $mb . '@' . $host;
                    }
                }
                $result['name']    = $this->decodeHeader($name);
                $result['address'] = strtolower(trim($addr));
            } elseif (is_string($first)) {
                if (preg_match('/^(.*?)\s*<([^>]+)>/', $first, $m)) {
                    $result['name']    = $this->decodeHeader(trim($m[1], "\"\x27\t\n\r "));
                    $result['address'] = strtolower(trim($m[2]));
                } else {
                    $result['address'] = strtolower(trim($first));
                }
            }
        } catch (\Throwable) {}
        return $result;
    }

    private function decodeHeader(string $value): string
    {
        if (!str_contains($value, '=?')) {
            return $value;
        }
        if (function_exists('mb_decode_mimeheader')) {
            return mb_decode_mimeheader($value);
        }
        return $value;
    }

    private function getUidValidity(\Webklex\PHPIMAP\Folder $folder): int
    {
        try {
            $status = $folder->examine();
            return (int) ($status['uidvalidity'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }
}
