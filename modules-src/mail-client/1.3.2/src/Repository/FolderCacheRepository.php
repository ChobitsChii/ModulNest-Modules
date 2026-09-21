<?php

declare(strict_types=1);

namespace ModulNest\MailClient\Repository;

use PDO;

/**
 * Ordner-Cache (mail_client_folder_cache) und Absender-Whitelist (mail_client_sender_whitelist).
 */
final class FolderCacheRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ordner-Cache
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Alle gecachten Ordner für ein Konto.
     * @return array<int, array<string, mixed>>
     */
    public function listFoldersForAccount(int $accountId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT folder_name, total_messages, unseen_messages, uidvalidity, uidnext, last_synced_at
             FROM mail_client_folder_cache
             WHERE mail_client_account_id = :acc
             ORDER BY folder_name ASC'
        );
        $stmt->execute(['acc' => $accountId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Alias für listFoldersForAccount.
     * @return array<int, array<string, mixed>>
     */
    public function listForAccount(int $accountId): array
    {
        return $this->listFoldersForAccount($accountId);
    }

    /**
     * Einen gecachten Ordner für ein Konto abrufen.
     * @return array<string, mixed>|null
     */
    public function findFolder(int $accountId, string $folder): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT folder_name, total_messages, unseen_messages, uidvalidity, uidnext, last_synced_at
             FROM mail_client_folder_cache
             WHERE mail_client_account_id = :acc AND folder_name = :folder LIMIT 1'
        );
        $stmt->execute(['acc' => $accountId, 'folder' => $folder]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * Ordner-Status upserten (INSERT … ON DUPLICATE KEY UPDATE / ON CONFLICT).
     */
    public function upsertFolder(int $userId, int $accountId, string $folder, int $total, int $unseen, int $uidvalidity, int $uidnext): void
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $sql = 'INSERT INTO mail_client_folder_cache
                        (user_id, mail_client_account_id, folder_name, total_messages, unseen_messages,
                         uidvalidity, uidnext, last_synced_at)
                     VALUES (:uid, :acc, :folder, :total, :unseen, :uidval, :uidnext, CURRENT_TIMESTAMP)
                     ON CONFLICT(mail_client_account_id, folder_name) DO UPDATE SET
                        total_messages = excluded.total_messages,
                        unseen_messages = excluded.unseen_messages,
                        uidvalidity = excluded.uidvalidity,
                        uidnext = excluded.uidnext,
                        last_synced_at = CURRENT_TIMESTAMP';
        } else {
            $sql = 'INSERT INTO mail_client_folder_cache
                        (user_id, mail_client_account_id, folder_name, total_messages, unseen_messages,
                         uidvalidity, uidnext, last_synced_at)
                     VALUES (:uid, :acc, :folder, :total, :unseen, :uidval, :uidnext, NOW())
                     ON DUPLICATE KEY UPDATE
                        total_messages = VALUES(total_messages),
                        unseen_messages = VALUES(unseen_messages),
                        uidvalidity = VALUES(uidvalidity),
                        uidnext = VALUES(uidnext),
                        last_synced_at = NOW()';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'uid'    => $userId,
            'acc'    => $accountId,
            'folder' => $folder,
            'total'  => $total,
            'unseen' => $unseen,
            'uidval' => $uidvalidity,
            'uidnext'=> $uidnext,
        ]);
    }

    /**
     * Array-basiertes Upsert für Ordner-Status.
     * @param array<string, mixed> $data
     */
    public function upsert(int $accountId, string $folder, array $data, int $userId = 1): void
    {
        $total       = (int) ($data['total_messages'] ?? $data['total_count'] ?? 0);
        $unseen      = (int) ($data['unseen_messages'] ?? $data['unread_count'] ?? 0);
        $uidvalidity = (int) ($data['uidvalidity'] ?? 0);
        $uidnext     = (int) ($data['uidnext'] ?? 0);
        $uid         = (int) ($data['user_id'] ?? $userId);
        $this->upsertFolder($uid, $accountId, $folder, $total, $unseen, $uidvalidity, $uidnext);
    }

    /**
     * Einen Ordner aus dem Cache entfernen (z.B. nach Server-seitiger Umbenennung/Löschung).
     */
    public function removeFolder(int $accountId, string $folder): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM mail_client_folder_cache WHERE mail_client_account_id = :acc AND folder_name = :folder'
        );
        $stmt->execute(['acc' => $accountId, 'folder' => $folder]);
    }

    /**
     * Alle gecachten Ordner eines Kontos entfernen.
     */
    public function clearAccount(int $accountId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM mail_client_folder_cache WHERE mail_client_account_id = :acc'
        );
        $stmt->execute(['acc' => $accountId]);
    }

    public function clearForAccount(int $accountId): void
    {
        $this->clearAccount($accountId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Absender-Whitelist
    // ─────────────────────────────────────────────────────────────────────────

    public static function extractCleanEmail(string $sender): string
    {
        $sender = trim($sender);
        if (preg_match('/<([^>]+)>/', $sender, $m)) {
            $sender = trim($m[1]);
        }
        return strtolower(trim($sender, " \"'<>"));
    }

    public static function extractCleanDomain(string $senderOrDomain): string
    {
        $clean = self::extractCleanEmail($senderOrDomain);
        $clean = ltrim($clean, '@');
        if (str_contains($clean, '@')) {
            $clean = substr($clean, strrpos($clean, '@') + 1);
        }
        return strtolower(trim($clean, " >\t\r\n"));
    }

    /**
     * Prüft ob ein Absender externe Bilder laden darf.
     */
    public function isWhitelisted(int $userId, string $senderAddress): bool
    {
        $cleanEmail  = self::extractCleanEmail($senderAddress);
        $cleanDomain = self::extractCleanDomain($senderAddress);
        if ($cleanEmail === '') {
            return false;
        }

        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM mail_client_sender_whitelist
             WHERE user_id = :uid
               AND (
                   (scope_type = 'sender' AND (scope_value = :sender OR scope_value LIKE :sender_like))
                   OR (scope_type = 'domain' AND scope_value = :domain)
               )
             LIMIT 1"
        );
        $stmt->execute([
            'uid'         => $userId,
            'sender'      => $cleanEmail,
            'sender_like' => '%<' . $cleanEmail . '>',
            'domain'      => $cleanDomain,
        ]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return array<int, array<string, mixed>> */
    public function listWhitelistForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, scope_type, scope_value, created_at FROM mail_client_sender_whitelist
             WHERE user_id = :uid ORDER BY scope_type, scope_value'
        );
        $stmt->execute(['uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    public function addToWhitelist(int $userId, string $scopeType, string $scopeValue): void
    {
        if ($scopeType === 'sender') {
            $scopeValue = self::extractCleanEmail($scopeValue);
        } else {
            $scopeValue = self::extractCleanDomain($scopeValue);
        }
        if ($scopeValue === '') {
            return;
        }

        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $insertVerb = ($driver === 'sqlite') ? 'INSERT OR IGNORE' : 'INSERT IGNORE';

        $stmt = $this->pdo->prepare(
            $insertVerb . ' INTO mail_client_sender_whitelist (user_id, scope_type, scope_value)
             VALUES (:uid, :type, :value)'
        );
        $stmt->execute(['uid' => $userId, 'type' => $scopeType, 'value' => $scopeValue]);
    }

    public function removeFromWhitelist(int $userId, int $whitelistId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM mail_client_sender_whitelist WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute(['id' => $whitelistId, 'uid' => $userId]);
    }
}
