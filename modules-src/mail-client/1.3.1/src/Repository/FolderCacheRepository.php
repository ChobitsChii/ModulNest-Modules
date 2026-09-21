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
     * Gibt alle gecachten Ordner für ein Konto zurück (sortiert nach Name).
     * @return array<int, array<string, mixed>>
     */
    public function listForAccount(int $accountId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT folder_name, folder_path, unread_count, total_count,
                    icon, is_system, updated_at
             FROM mail_client_folder_cache
             WHERE mail_client_account_id = :acc
             ORDER BY is_system DESC, folder_name ASC'
        );
        $stmt->execute(['acc' => $accountId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Speichert / aktualisiert einen Ordner-Cache-Eintrag.
     *
     * @param array{
     *   mail_client_account_id: int,
     *   folder_name:            string,
     *   folder_path:            string,
     *   unread_count:           int,
     *   total_count:            int,
     *   icon:                   string,
     *   is_system:              int,
     * } $data
     */
    public function upsert(array $data): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO mail_client_folder_cache
               (mail_client_account_id, folder_name, folder_path,
                unread_count, total_count, icon, is_system, updated_at)
             VALUES
               (:acc, :name, :path, :unread, :total, :icon, :is_sys, NOW())
             ON DUPLICATE KEY UPDATE
               folder_path  = VALUES(folder_path),
               unread_count = VALUES(unread_count),
               total_count  = VALUES(total_count),
               icon         = VALUES(icon),
               is_system    = VALUES(is_system),
               updated_at   = NOW()'
        );
        $stmt->execute([
            'acc'    => $data['mail_client_account_id'],
            'name'   => $data['folder_name'],
            'path'   => $data['folder_path'],
            'unread' => $data['unread_count'],
            'total'  => $data['total_count'],
            'icon'   => $data['icon'] ?? 'bi-folder',
            'is_sys' => $data['is_system'] ?? 0,
        ]);
    }

    /**
     * Aktualisiert nur die Zähler eines Ordners.
     */
    public function updateCounts(int $accountId, string $folderName, int $unread, int $total): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE mail_client_folder_cache
             SET unread_count = :unread, total_count = :total, updated_at = NOW()
             WHERE mail_client_account_id = :acc AND folder_name = :name'
        );
        $stmt->execute([
            'unread' => $unread,
            'total'  => $total,
            'acc'    => $accountId,
            'name'   => $folderName,
        ]);
    }

    /**
     * Erhöht oder verringert den Ungelesen-Zähler atomar um $delta (+1 oder -1).
     */
    public function adjustUnreadCount(int $accountId, string $folderName, int $delta): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE mail_client_folder_cache
             SET unread_count = GREATEST(0, unread_count + :delta), updated_at = NOW()
             WHERE mail_client_account_id = :acc AND folder_name = :name'
        );
        $stmt->execute([
            'delta' => $delta,
            'acc'   => $accountId,
            'name'  => $folderName,
        ]);
    }

    /**
     * Löscht alle Cache-Einträge für ein Konto (z. B. vor vollständigem Resync).
     */
    public function clearForAccount(int $accountId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM mail_client_folder_cache WHERE mail_client_account_id = :acc'
        );
        $stmt->execute(['acc' => $accountId]);
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

        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO mail_client_sender_whitelist (user_id, scope_type, scope_value)
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
