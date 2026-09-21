<?php

declare(strict_types=1);

namespace ModulNest\MailClient\Repository;

use PDO;

/**
 * Schneller lokaler Cache für Nachrichten-Header.
 * Ermöglicht sofortiges Anzeigen der Mail-Liste ohne IMAP-Roundtrips.
 *
 * Tabellenstruktur: mail_client_message_index
 * Unique: (mail_client_account_id, folder_name, uid)
 */
final class MessageIndexRepository
{
    // Bitmask-Flags (kompatibel mit IMAP system flags)
    public const FLAG_SEEN     = 1;
    public const FLAG_ANSWERED = 2;
    public const FLAG_FLAGGED  = 4;
    public const FLAG_DELETED  = 8;
    public const FLAG_DRAFT    = 16;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Gibt gecachte Nachrichten-Header für einen Ordner zurück (neueste zuerst).
     * @return array<int, array<string, mixed>>
     */
    public function listForFolder(int $accountId, string $folder, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, uid, message_id, sender_address, sender_name,
                    recipient_name, recipient_address, subject,
                    message_date, flags, size_bytes, has_attachments
             FROM mail_client_message_index
             WHERE mail_client_account_id=:acc AND folder_name=:folder
             ORDER BY message_date DESC, uid DESC
             LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':acc', $accountId, PDO::PARAM_INT);
        $stmt->bindValue(':folder', $folder, PDO::PARAM_STR);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Alle bekannten UIDs für einen Ordner (für Diff-Berechnung).
     * @return array<int, int>
     */
    public function listUidsForFolder(int $accountId, string $folder): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT uid FROM mail_client_message_index
             WHERE mail_client_account_id=:acc AND folder_name=:folder'
        );
        $stmt->execute(['acc' => $accountId, 'folder' => $folder]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return is_array($rows) ? array_map('intval', $rows) : [];
    }

    /**
     * Gibt die UIDs aus der Liste zurück, bei denen Empfänger-Informationen fehlen.
     * @param array<int, int> $uids
     * @return array<int, int>
     */
    public function listUidsMissingRecipient(int $accountId, string $folder, array $uids): array
    {
        $validUids = array_values(array_filter($uids, static fn (mixed $u): bool => (int) $u > 0));
        if ($validUids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($validUids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT uid FROM mail_client_message_index
             WHERE mail_client_account_id=? AND folder_name=? AND uid IN ({$placeholders})
               AND recipient_address IS NULL"
        );
        $params = array_merge([$accountId, $folder], $validUids);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return is_array($rows) ? array_map('intval', $rows) : [];
    }

    /**
     * Einzelne Nachricht aus dem Index laden.
     * @return array<string, mixed>|null
     */
    public function findByUid(int $accountId, string $folder, int $uid): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM mail_client_message_index
             WHERE mail_client_account_id=:acc AND folder_name=:folder AND uid=:uid LIMIT 1'
        );
        $stmt->execute(['acc' => $accountId, 'folder' => $folder, 'uid' => $uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * Neue Nachrichten-Header in den Cache einfügen (INSERT ON DUPLICATE KEY UPDATE).
     * @param int|array<string, mixed> $userIdOrData
     * @param array<string, mixed> $data
     */
    public function upsert(int|array $userIdOrData, ?int $accountId = null, ?string $folder = null, array $data = []): void
    {
        if (is_array($userIdOrData)) {
            $data      = $userIdOrData;
            $userId    = (int) ($data['user_id'] ?? 0);
            $accountId = (int) ($data['mail_client_account_id'] ?? $data['account_id'] ?? 0);
            $folder    = (string) ($data['folder_name'] ?? $data['folder'] ?? '');
        } else {
            $userId    = $userIdOrData;
            $accountId = (int) $accountId;
            $folder    = (string) $folder;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO mail_client_message_index
                (user_id, mail_client_account_id, folder_name, uid, message_id,
                 sender_address, sender_name, recipient_name, recipient_address,
                 subject, message_date, flags, size_bytes, has_attachments)
             VALUES
                (:user_id, :acc, :folder, :uid, :message_id,
                 :sender_address, :sender_name, :recipient_name, :recipient_address,
                 :subject, :message_date, :flags, :size_bytes, :has_attachments)
             ON DUPLICATE KEY UPDATE
                flags=VALUES(flags),
                sender_name=VALUES(sender_name),
                recipient_name=VALUES(recipient_name),
                recipient_address=VALUES(recipient_address),
                updated_at=NOW()'
        );
        $stmt->execute([
            'user_id'           => $userId,
            'acc'               => $accountId,
            'folder'            => $folder,
            'uid'               => (int) ($data['uid'] ?? 0),
            'message_id'        => isset($data['message_id']) ? substr((string) $data['message_id'], 0, 512) : null,
            'sender_address'    => substr((string) ($data['sender_address'] ?? ''), 0, 255),
            'sender_name'       => substr((string) ($data['sender_name'] ?? ''), 0, 255),
            'recipient_name'    => substr((string) ($data['recipient_name'] ?? ''), 0, 255),
            'recipient_address' => substr((string) ($data['recipient_address'] ?? ''), 0, 255),
            'subject'           => substr((string) ($data['subject'] ?? ''), 0, 998),
            'message_date'      => (int) ($data['message_date'] ?? 0),
            'flags'             => (int) ($data['flags'] ?? 0),
            'size_bytes'        => (int) ($data['size_bytes'] ?? 0),
            'has_attachments'   => (int) ($data['has_attachments'] ?? 0),
        ]);
    }

    /**
     * Entfernt UIDs, die auf dem IMAP-Server nicht mehr existieren (gelöscht/verschoben).
     * @param array<int, int> $existingUids UIDs, die noch auf IMAP vorhanden sind
     */
    public function removeStaleUids(int $accountId, string $folder, array $existingUids): int
    {
        if ($existingUids === []) {
            // Alles entfernen
            $stmt = $this->pdo->prepare(
                'DELETE FROM mail_client_message_index
                 WHERE mail_client_account_id=:acc AND folder_name=:folder'
            );
            $stmt->execute(['acc' => $accountId, 'folder' => $folder]);
            return $stmt->rowCount();
        }

        // UIDs, die wir kennen, aber nicht in der IMAP-Liste sind → löschen
        $placeholders = implode(',', array_fill(0, count($existingUids), '?'));
        $stmt = $this->pdo->prepare(
            "DELETE FROM mail_client_message_index
             WHERE mail_client_account_id=? AND folder_name=? AND uid NOT IN ({$placeholders})"
        );
        $params = array_merge([$accountId, $folder], $existingUids);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Kompletten Cache für einen Ordner löschen (z.B. bei UIDVALIDITY-Änderung).
     */
    public function clearFolder(int $accountId, string $folder): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM mail_client_message_index
             WHERE mail_client_account_id=:acc AND folder_name=:folder'
        );
        $stmt->execute(['acc' => $accountId, 'folder' => $folder]);
    }

    /**
     * Flags für eine UID aktualisieren.
     */
    public function updateFlags(int $accountId, string $folder, int $uid, int $flags): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE mail_client_message_index SET flags=:flags
             WHERE mail_client_account_id=:acc AND folder_name=:folder AND uid=:uid'
        );
        $stmt->execute(['flags' => $flags, 'acc' => $accountId, 'folder' => $folder, 'uid' => $uid]);
    }

    /**
     * Gesamtanzahl der Nachrichten in einem Ordner (aus Cache).
     */
    public function countForFolder(int $accountId, string $folder): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM mail_client_message_index
             WHERE mail_client_account_id=:acc AND folder_name=:folder'
        );
        $stmt->execute(['acc' => $accountId, 'folder' => $folder]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Anzahl ungelesener Mails in einem Ordner (aus Cache).
     */
    public function countUnseenInFolder(int $accountId, string $folder): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM mail_client_message_index
             WHERE mail_client_account_id=:acc AND folder_name=:folder
               AND (flags & :seen_flag) = 0'
        );
        $stmt->execute(['acc' => $accountId, 'folder' => $folder, 'seen_flag' => self::FLAG_SEEN]);
        return (int) $stmt->fetchColumn();
    }
}
