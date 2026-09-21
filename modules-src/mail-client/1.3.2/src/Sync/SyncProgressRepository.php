<?php

declare(strict_types=1);

namespace ModulNest\MailClient\Sync;

use PDO;

/**
 * Repository für den Sync-Fortschritt pro (Account, Ordner).
 *
 * Speichert:
 * - Wie viele UIDs der Ordner insgesamt hat (total_uids)
 * - Wie viele Header bereits gecacht sind (synced_uids)
 * - Die Liste der noch ausstehenden UIDs (pending_uids, JSON)
 * - Den aktuellen Status (idle | syncing | complete | error)
 */
final class SyncProgressRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Liest den aktuellen Sync-Fortschritt.
     * @return array<string,mixed>|null
     */
    public function get(int $accountId, string $folderName): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM mail_client_sync_progress
             WHERE account_id = :a AND folder_name = :f
             LIMIT 1'
        );
        $stmt->execute([':a' => $accountId, ':f' => $folderName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * Startet einen neuen Sync (oder setzt einen bestehenden zurück).
     * @param int[] $pendingUids UIDs die noch gesynct werden müssen (absteigend = neueste zuerst)
     */
    public function initSync(int $accountId, string $folderName, int $totalUids, array $pendingUids): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO mail_client_sync_progress
                (account_id, folder_name, total_uids, synced_uids, sync_offset, pending_uids, status, started_at)
             VALUES
                (:a, :f, :total, 0, 0, :uids, \'syncing\', NOW())
             ON DUPLICATE KEY UPDATE
                total_uids   = VALUES(total_uids),
                synced_uids  = 0,
                sync_offset  = 0,
                pending_uids = VALUES(pending_uids),
                status       = \'syncing\',
                error_message = NULL,
                started_at   = NOW()'
        );
        $stmt->execute([
            ':a'     => $accountId,
            ':f'     => $folderName,
            ':total' => $totalUids,
            ':uids'  => json_encode($pendingUids, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * Aktualisiert den Fortschritt nach einem abgeschlossenen Batch.
     * @param int[] $remainingUids Noch ausstehende UIDs
     */
    public function updateProgress(int $accountId, string $folderName, int $syncedUids, array $remainingUids): void
    {
        $status = count($remainingUids) === 0 ? 'complete' : 'syncing';
        $stmt = $this->pdo->prepare(
            'INSERT INTO mail_client_sync_progress
                (account_id, folder_name, total_uids, synced_uids, sync_offset, pending_uids, status, started_at)
             VALUES
                (:a, :f, :synced, :synced, 0, :remaining, :status, NOW())
             ON DUPLICATE KEY UPDATE
                synced_uids  = VALUES(synced_uids),
                sync_offset  = sync_offset + :batch,
                pending_uids = VALUES(pending_uids),
                status       = VALUES(status)'
        );
        $stmt->execute([
            ':synced'    => $syncedUids,
            ':batch'     => 0, // offset läuft über pending_uids-Länge
            ':remaining' => json_encode($remainingUids, JSON_THROW_ON_ERROR),
            ':status'    => $status,
            ':a'         => $accountId,
            ':f'         => $folderName,
        ]);
    }

    /**
     * Markiert den Sync als abgeschlossen (UPSERT).
     */
    public function markComplete(int $accountId, string $folderName, int $totalSynced): void
    {
        $this->pdo->prepare(
            'INSERT INTO mail_client_sync_progress
                (account_id, folder_name, total_uids, synced_uids, sync_offset, pending_uids, status, started_at)
             VALUES
                (:a, :f, :synced, :synced, 0, \'[]\', \'complete\', NOW())
             ON DUPLICATE KEY UPDATE
                total_uids   = GREATEST(total_uids, VALUES(total_uids)),
                synced_uids  = VALUES(synced_uids),
                pending_uids = \'[]\',
                status       = \'complete\',
                error_message = NULL'
        )->execute([':synced' => $totalSynced, ':a' => $accountId, ':f' => $folderName]);
    }

    /**
     * Markiert den Sync als fehlerhaft (UPSERT).
     */
    public function markError(int $accountId, string $folderName, string $message): void
    {
        $this->pdo->prepare(
            'INSERT INTO mail_client_sync_progress
                (account_id, folder_name, total_uids, synced_uids, sync_offset, pending_uids, status, error_message, started_at)
             VALUES
                (:a, :f, 0, 0, 0, \'[]\', \'error\', :msg, NOW())
             ON DUPLICATE KEY UPDATE
                status = \'error\',
                error_message = VALUES(error_message)'
        )->execute([':msg' => $message, ':a' => $accountId, ':f' => $folderName]);
    }

    /**
     * Liest die noch ausstehenden UIDs als Array.
     * @return int[]
     */
    public function getPendingUids(int $accountId, string $folderName): array
    {
        $row = $this->get($accountId, $folderName);
        if ($row === null || !is_string($row['pending_uids'])) {
            return [];
        }
        $uids = json_decode($row['pending_uids'], true);
        return is_array($uids) ? array_map('intval', $uids) : [];
    }
}
