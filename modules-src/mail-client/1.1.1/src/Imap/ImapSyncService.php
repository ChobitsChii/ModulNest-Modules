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
 * 2. continueSync() – Nächsten Batch im Hintergrund laden (max. 25 Sek.) → per Polling
 *
 * Alle Header werden gecacht, Bodies NICHT.
 */
final class ImapSyncService
{
    private const QUICK_SYNC_LIMIT    = 50;
    private const BATCH_SIZE          = 100;
    private const CONTINUE_TIMEOUT    = 25; // Sekunden pro continueSync()-Aufruf
    private const FLAG_SYNC_LIMIT     = 200;

    public function __construct(
        private readonly MessageIndexRepository $messageIndex,
        private readonly FolderCacheRepository  $folderCache,
        private readonly SyncProgressRepository $syncProgress,
    ) {}

    // ─────────────────────────────────────────────────────────────────────
    // Öffentliche API
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Gibt gecachte Nachrichten zurück UND startet ggf. quickSync.
     * Nutzt gecachten Stand wenn vorhanden, triggert keinen IMAP-Call.
     *
     * @param array<string,mixed> $accountConn
     * @return array{messages: list<array>, total: int, unseen: int}
     */
    public function getMessages(int $userId, int $accountId, array $accountConn, string $folder, int $limit, int $offset): array
    {
        $cached = $this->messageIndex->listForFolder($accountId, $folder, $limit, $offset);
        $total  = $this->messageIndex->countForFolder($accountId, $folder);
        $unseen = $this->messageIndex->countUnseenInFolder($accountId, $folder);

        // Wenn noch gar nichts gecacht ist → direkt Quick-Sync ausführen
        if ($total === 0) {
            $this->quickSync($accountId, $accountConn, $folder);
            $cached = $this->messageIndex->listForFolder($accountId, $folder, $limit, $offset);
            $total  = $this->messageIndex->countForFolder($accountId, $folder);
            $unseen = $this->messageIndex->countUnseenInFolder($accountId, $folder);
        }

        return [
            'messages' => $cached,
            'total'    => $total,
            'unseen'   => $unseen,
        ];
    }

    /**
     * Schneller Erstsync: Holt die neuesten 50 UIDs + ihre Header.
     * Startet außerdem den Hintergrund-Sync (speichert pending_uids).
     *
     * @param array<string,mixed> $accountConn
     */
    public function quickSync(int $accountId, array $accountConn, string $folder): void
    {
        $client = ImapConnectionFactory::connect($accountConn);
        try {
            $imapFolder = $this->findImapFolder($client, $folder);
            if ($imapFolder === null) {
                return;
            }

            // UIDVALIDITY prüfen
            $status = $imapFolder->query()->count();
            $cachedFolder = $this->folderCache->findFolder($accountId, $folder);
            $uidValidity  = $this->getUidValidity($imapFolder);

            if ($cachedFolder !== null && (int)$cachedFolder['uidvalidity'] !== 0 && $uidValidity !== 0 && (int)$cachedFolder['uidvalidity'] !== $uidValidity) {
                // UIDVALIDITY geändert → alles invalidieren
                $this->messageIndex->clearFolder($accountId, $folder);
            }

            // Alle UIDs holen (SEARCH ALL – geht schnell, auch bei 50k UIDs)
            $allUids = $this->fetchAllUids($imapFolder);
            if (empty($allUids)) {
                $this->syncProgress->markComplete($accountId, $folder, 0);
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
                $this->fetchAndCacheHeaders($imapFolder, $accountId, $folder, $quickBatch);
            }

            // Restliche UIDs für continueSync speichern
            $remainingUids = array_slice($pendingUids, count($quickBatch));
            $totalPending  = count($pendingUids);
            $syncedSoFar   = $this->messageIndex->countForFolder($accountId, $folder);

            if (empty($remainingUids)) {
                // Alles auf einmal geladen
                $this->syncProgress->markComplete($accountId, $folder, $syncedSoFar);
            } else {
                $this->syncProgress->initSync($accountId, $folder, $totalUids, $remainingUids);
                // synced_uids korrekt setzen
                $this->syncProgress->updateProgress($accountId, $folder, $syncedSoFar, $remainingUids);
            }

            // Ordner-Cache aktualisieren
            $this->folderCache->upsertFolder($accountId, $folder, $uidValidity, 0, $totalUids, $this->messageIndex->countUnseenInFolder($accountId, $folder));

            // Flag-Sync für bereits bekannte Nachrichten
            $this->syncFlags($imapFolder, $accountId, $folder);

        } finally {
            $client->disconnect();
        }
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
        $progress = $this->syncProgress->get($accountId, $folder);
        if ($progress === null || $progress['status'] === 'complete' || $progress['status'] === 'error') {
            $synced = $this->messageIndex->countForFolder($accountId, $folder);
            return [
                'synced_uids' => $synced,
                'total_uids'  => (int) ($progress['total_uids'] ?? $synced),
                'status'      => $progress['status'] ?? 'complete',
            ];
        }

        $pendingUids = $this->syncProgress->getPendingUids($accountId, $folder);
        if (empty($pendingUids)) {
            $synced = $this->messageIndex->countForFolder($accountId, $folder);
            $this->syncProgress->markComplete($accountId, $folder, $synced);
            return ['synced_uids' => $synced, 'total_uids' => (int) $progress['total_uids'], 'status' => 'complete'];
        }

        $client = ImapConnectionFactory::connect($accountConn);
        try {
            $imapFolder = $this->findImapFolder($client, $folder);
            if ($imapFolder === null) {
                $this->syncProgress->markError($accountId, $folder, 'Ordner nicht gefunden.');
                return ['synced_uids' => 0, 'total_uids' => (int) $progress['total_uids'], 'status' => 'error', 'error' => 'Ordner nicht gefunden.'];
            }

            $startTime     = time();
            $remaining     = $pendingUids;

            while (!empty($remaining) && (time() - $startTime) < self::CONTINUE_TIMEOUT) {
                $batch     = array_slice($remaining, 0, self::BATCH_SIZE);
                $remaining = array_slice($remaining, self::BATCH_SIZE);

                $this->fetchAndCacheHeaders($imapFolder, $accountId, $folder, $batch);
            }

            $syncedSoFar = $this->messageIndex->countForFolder($accountId, $folder);
            $totalUids   = (int) $progress['total_uids'];

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
     * @return list<array{name: string, special: string}>
     */
    public function listFolders(array $accountConn): array
    {
        $client = ImapConnectionFactory::connect($accountConn);
        try {
            $folders = [];
            foreach ($client->getFolders(false) as $folder) {
                if (!is_object($folder)) {
                    continue;
                }
                $name = (string) ($folder->path ?? $folder->name ?? '');
                if ($name === '') {
                    continue;
                }
                $folders[] = ['name' => $name, 'special' => 'folder'];
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
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Private Helfer
    // ─────────────────────────────────────────────────────────────────────

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
            $messages = $folder->query()->leaveUnread()->fetchOrder('asc')->get();
            $uids = [];
            foreach ($messages as $msg) {
                if (is_object($msg) && isset($msg->uid)) {
                    $uids[] = (int) $msg->uid;
                }
            }
            return $uids;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param int[] $uids */
    private function fetchAndCacheHeaders(\Webklex\PHPIMAP\Folder $folder, int $accountId, string $folderName, array $uids): void
    {
        if (empty($uids)) {
            return;
        }
        // Nur gültige UIDs (> 0) behalten – verhindert leere IMAP WHERE-Klauseln
        $validUids = array_values(array_filter($uids, static fn (int $uid): bool => $uid > 0));
        if ($validUids === []) {
            return;
        }
        // In Chunks à BATCH_SIZE aufteilen um Memory zu schonen
        foreach (array_chunk($validUids, self::BATCH_SIZE) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            try {
                $uidList = implode(',', array_filter($chunk, static fn (int $uid): bool => $uid > 0));
                if ($uidList === '') {
                    continue;
                }
                $messages = $folder->query()->whereUid($uidList)->leaveUnread()->get();
                foreach ($messages as $msg) {
                    if (!is_object($msg)) {
                        continue;
                    }
                    $uid      = (int) ($msg->uid ?? 0);
                    $from     = $this->extractFirstAddress($msg->from ?? []);
                    $subject  = $this->decodeHeader((string) ($msg->subject ?? ''));
                    $dateTs   = 0;
                    try {
                        $dateTs = $msg->date instanceof \Carbon\Carbon ? $msg->date->getTimestamp() : (int) strtotime((string) ($msg->date ?? ''));
                    } catch (\Throwable) {}

                    $flags = 0;
                    if ($this->hasFlag($msg, 'Seen'))     { $flags |= MessageIndexRepository::FLAG_SEEN; }
                    if ($this->hasFlag($msg, 'Answered')) { $flags |= MessageIndexRepository::FLAG_ANSWERED; }
                    if ($this->hasFlag($msg, 'Flagged'))  { $flags |= MessageIndexRepository::FLAG_FLAGGED; }
                    if ($this->hasFlag($msg, 'Deleted'))  { $flags |= MessageIndexRepository::FLAG_DELETED; }
                    if ($this->hasFlag($msg, 'Draft'))    { $flags |= MessageIndexRepository::FLAG_DRAFT; }

                    $hasAttachments = false;
                    try {
                        $hasAttachments = $msg->hasAttachments();
                    } catch (\Throwable) {}

                    $this->messageIndex->upsert([
                        'account_id'      => $accountId,
                        'folder_name'     => $folderName,
                        'uid'             => $uid,
                        'sender_name'     => $from['name'],
                        'sender_address'  => $from['address'],
                        'subject'         => mb_substr($subject, 0, 255),
                        'message_date'    => $dateTs,
                        'flags'           => $flags,
                        'size_bytes'      => (int) ($msg->size ?? 0),
                        'has_attachments' => $hasAttachments ? 1 : 0,
                    ]);
                }
            } catch (\Throwable) {
                // Einzelne Batches dürfen scheitern ohne den Gesamt-Sync abzubrechen
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
        foreach (array_chunk($cachedUids, self::BATCH_SIZE) as $chunk) {
            try {
                $messages = $folder->query()->whereUid(implode(',', $chunk))->leaveUnread()->fetchFlags()->get();
                foreach ($messages as $msg) {
                    if (!is_object($msg)) {
                        continue;
                    }
                    $uid = (int) ($msg->uid ?? 0);
                    $flags = 0;
                    if ($this->hasFlag($msg, 'Seen'))     { $flags |= MessageIndexRepository::FLAG_SEEN; }
                    if ($this->hasFlag($msg, 'Answered')) { $flags |= MessageIndexRepository::FLAG_ANSWERED; }
                    if ($this->hasFlag($msg, 'Flagged'))  { $flags |= MessageIndexRepository::FLAG_FLAGGED; }
                    if ($this->hasFlag($msg, 'Deleted'))  { $flags |= MessageIndexRepository::FLAG_DELETED; }
                    if ($this->hasFlag($msg, 'Draft'))    { $flags |= MessageIndexRepository::FLAG_DRAFT; }
                    $this->messageIndex->updateFlags($accountId, $folderName, $uid, $flags);
                }
            } catch (\Throwable) {}
        }
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
            if (is_object($addresses) && method_exists($addresses, 'toArray')) {
                $addresses = $addresses->toArray();
            }
            if (!is_array($addresses) || empty($addresses)) {
                return $result;
            }
            $first = $addresses[0] ?? null;
            if (is_object($first)) {
                $result['name']    = $this->decodeHeader((string) ($first->personal ?? ''));
                $result['address'] = strtolower(trim(($first->mailbox ?? '') . '@' . ($first->host ?? '')));
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
