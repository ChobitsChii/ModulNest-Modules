<?php

declare(strict_types=1);

namespace ModulNest\Mail;

use PDO;

final class MailRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAccountsForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, user_id, display_name, email_address,
                    imap_host, imap_port, imap_encryption, imap_username,
                    smtp_host, smtp_port, smtp_encryption, smtp_username,
                    encrypted_password, is_active, sort_order, created_at, updated_at
             FROM mail_accounts
             WHERE user_id = :user_id
             ORDER BY sort_order ASC, id ASC"
        );
        $statement->execute(['user_id' => $userId]);
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findAccountForUser(int $accountId, int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, user_id, display_name, email_address,
                    imap_host, imap_port, imap_encryption, imap_username,
                    smtp_host, smtp_port, smtp_encryption, smtp_username,
                    encrypted_password, is_active, sort_order, created_at, updated_at
             FROM mail_accounts
             WHERE id = :id AND user_id = :user_id
             LIMIT 1"
        );
        $statement->execute([
            'id' => $accountId,
            'user_id' => $userId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createAccount(int $userId, array $payload): int
    {
        $sortOrder = $this->nextSortOrder($userId);
        $statement = $this->pdo->prepare(
            "INSERT INTO mail_accounts (
                    user_id, display_name, email_address,
                    imap_host, imap_port, imap_encryption, imap_username,
                    smtp_host, smtp_port, smtp_encryption, smtp_username,
                    encrypted_password, is_active, sort_order
             ) VALUES (
                    :user_id, :display_name, :email_address,
                    :imap_host, :imap_port, :imap_encryption, :imap_username,
                    :smtp_host, :smtp_port, :smtp_encryption, :smtp_username,
                    :encrypted_password, :is_active, :sort_order
             )"
        );
        $statement->execute([
            'user_id' => $userId,
            'display_name' => (string) $payload['display_name'],
            'email_address' => (string) $payload['email_address'],
            'imap_host' => (string) $payload['imap_host'],
            'imap_port' => (int) $payload['imap_port'],
            'imap_encryption' => (string) $payload['imap_encryption'],
            'imap_username' => (string) $payload['imap_username'],
            'smtp_host' => (string) $payload['smtp_host'],
            'smtp_port' => (int) $payload['smtp_port'],
            'smtp_encryption' => (string) $payload['smtp_encryption'],
            'smtp_username' => (string) $payload['smtp_username'],
            'encrypted_password' => (string) $payload['encrypted_password'],
            'is_active' => (int) $payload['is_active'],
            'sort_order' => $sortOrder,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateAccount(int $accountId, int $userId, array $payload): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE mail_accounts
             SET display_name = :display_name,
                 email_address = :email_address,
                 imap_host = :imap_host,
                 imap_port = :imap_port,
                 imap_encryption = :imap_encryption,
                 imap_username = :imap_username,
                 smtp_host = :smtp_host,
                 smtp_port = :smtp_port,
                 smtp_encryption = :smtp_encryption,
                 smtp_username = :smtp_username,
                 encrypted_password = :encrypted_password,
                 is_active = :is_active,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND user_id = :user_id"
        );
        $statement->execute([
            'id' => $accountId,
            'user_id' => $userId,
            'display_name' => (string) $payload['display_name'],
            'email_address' => (string) $payload['email_address'],
            'imap_host' => (string) $payload['imap_host'],
            'imap_port' => (int) $payload['imap_port'],
            'imap_encryption' => (string) $payload['imap_encryption'],
            'imap_username' => (string) $payload['imap_username'],
            'smtp_host' => (string) $payload['smtp_host'],
            'smtp_port' => (int) $payload['smtp_port'],
            'smtp_encryption' => (string) $payload['smtp_encryption'],
            'smtp_username' => (string) $payload['smtp_username'],
            'encrypted_password' => (string) $payload['encrypted_password'],
            'is_active' => (int) $payload['is_active'],
        ]);
    }

    public function updateAccountWithoutPassword(int $accountId, int $userId, array $payload): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE mail_accounts
             SET display_name = :display_name,
                 email_address = :email_address,
                 imap_host = :imap_host,
                 imap_port = :imap_port,
                 imap_encryption = :imap_encryption,
                 imap_username = :imap_username,
                 smtp_host = :smtp_host,
                 smtp_port = :smtp_port,
                 smtp_encryption = :smtp_encryption,
                 smtp_username = :smtp_username,
                 is_active = :is_active,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND user_id = :user_id"
        );
        $statement->execute([
            'id' => $accountId,
            'user_id' => $userId,
            'display_name' => (string) $payload['display_name'],
            'email_address' => (string) $payload['email_address'],
            'imap_host' => (string) $payload['imap_host'],
            'imap_port' => (int) $payload['imap_port'],
            'imap_encryption' => (string) $payload['imap_encryption'],
            'imap_username' => (string) $payload['imap_username'],
            'smtp_host' => (string) $payload['smtp_host'],
            'smtp_port' => (int) $payload['smtp_port'],
            'smtp_encryption' => (string) $payload['smtp_encryption'],
            'smtp_username' => (string) $payload['smtp_username'],
            'is_active' => (int) $payload['is_active'],
        ]);
    }

    public function deleteAccount(int $accountId, int $userId): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM mail_accounts WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([
            'id' => $accountId,
            'user_id' => $userId,
        ]);
    }

    public function setAccountActiveState(int $accountId, int $userId, bool $isActive): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE mail_accounts
             SET is_active = :is_active,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND user_id = :user_id"
        );
        $statement->execute([
            'id' => $accountId,
            'user_id' => $userId,
            'is_active' => $isActive ? 1 : 0,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listFavoriteFoldersForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT f.id, f.user_id, f.mail_account_id, f.folder_name, f.sort_order, f.created_at,
                    a.display_name, a.email_address
             FROM mail_favorite_folders f
             INNER JOIN mail_accounts a ON a.id = f.mail_account_id
             WHERE f.user_id = :user_id
               AND a.user_id = :user_id
             ORDER BY f.sort_order ASC, f.id ASC"
        );
        $statement->execute(['user_id' => $userId]);
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function favoriteFolderExists(int $userId, int $accountId, string $folderName): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT id
             FROM mail_favorite_folders
             WHERE user_id = :user_id
               AND mail_account_id = :mail_account_id
               AND folder_name = :folder_name
             LIMIT 1"
        );
        $statement->execute([
            'user_id' => $userId,
            'mail_account_id' => $accountId,
            'folder_name' => $folderName,
        ]);

        return $statement->fetch() !== false;
    }

    public function addFavoriteFolder(int $userId, int $accountId, string $folderName): void
    {
        if ($this->favoriteFolderExists($userId, $accountId, $folderName)) {
            return;
        }

        $sortStatement = $this->pdo->prepare(
            "SELECT COALESCE(MAX(sort_order), 0) AS max_sort
             FROM mail_favorite_folders
             WHERE user_id = :user_id
               AND mail_account_id = :mail_account_id"
        );
        $sortStatement->execute([
            'user_id' => $userId,
            'mail_account_id' => $accountId,
        ]);
        $sortRow = $sortStatement->fetch();
        $nextSort = (is_array($sortRow) ? (int) ($sortRow['max_sort'] ?? 0) : 0) + 10;

        $statement = $this->pdo->prepare(
            "INSERT INTO mail_favorite_folders (
                user_id, mail_account_id, folder_name, sort_order
             ) VALUES (
                :user_id, :mail_account_id, :folder_name, :sort_order
             )"
        );
        $statement->execute([
            'user_id' => $userId,
            'mail_account_id' => $accountId,
            'folder_name' => $folderName,
            'sort_order' => $nextSort,
        ]);
    }

    public function removeFavoriteFolder(int $userId, int $accountId, string $folderName): void
    {
        $statement = $this->pdo->prepare(
            "DELETE FROM mail_favorite_folders
             WHERE user_id = :user_id
               AND mail_account_id = :mail_account_id
               AND folder_name = :folder_name"
        );
        $statement->execute([
            'user_id' => $userId,
            'mail_account_id' => $accountId,
            'folder_name' => $folderName,
        ]);
    }

    public function whitelistRuleExists(
        int $userId,
        string $scopeType,
        string $scopeValue,
        bool $allowExternalImages = true,
    ): bool {
        $statement = $this->pdo->prepare(
            "SELECT id
             FROM mail_sender_whitelist
             WHERE user_id = :user_id
               AND scope_type = :scope_type
               AND scope_value = :scope_value
               AND allow_external_images = :allow_external_images
             LIMIT 1"
        );
        $statement->execute([
            'user_id' => $userId,
            'scope_type' => $scopeType,
            'scope_value' => $scopeValue,
            'allow_external_images' => $allowExternalImages ? 1 : 0,
        ]);

        return $statement->fetch() !== false;
    }

    public function addWhitelistRule(
        int $userId,
        string $scopeType,
        string $scopeValue,
        bool $allowExternalImages = true,
    ): void {
        if ($this->whitelistRuleExists($userId, $scopeType, $scopeValue, $allowExternalImages)) {
            return;
        }

        $statement = $this->pdo->prepare(
            "INSERT INTO mail_sender_whitelist (user_id, scope_type, scope_value, allow_external_images)
             VALUES (:user_id, :scope_type, :scope_value, :allow_external_images)"
        );
        $statement->execute([
            'user_id' => $userId,
            'scope_type' => $scopeType,
            'scope_value' => $scopeValue,
            'allow_external_images' => $allowExternalImages ? 1 : 0,
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function listExcludedSenderKeysForContext(int $userId, int $accountId, string $folderName): array
    {
        $statement = $this->pdo->prepare(
            "SELECT sender_key
             FROM mail_sender_exclusions
             WHERE user_id = :user_id
               AND mail_account_id = :mail_account_id
               AND folder_name = :folder_name
             ORDER BY sender_key ASC"
        );
        $statement->execute([
            'user_id' => $userId,
            'mail_account_id' => $accountId,
            'folder_name' => $folderName,
        ]);
        $rows = $statement->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $senderKey = strtolower(trim((string) ($row['sender_key'] ?? '')));
            if ($senderKey !== '') {
                $result[] = $senderKey;
            }
        }

        return array_values(array_unique($result));
    }

    public function senderExclusionExists(int $userId, int $accountId, string $folderName, string $senderKey): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT id
             FROM mail_sender_exclusions
             WHERE user_id = :user_id
               AND mail_account_id = :mail_account_id
               AND folder_name = :folder_name
               AND sender_key = :sender_key
             LIMIT 1"
        );
        $statement->execute([
            'user_id' => $userId,
            'mail_account_id' => $accountId,
            'folder_name' => $folderName,
            'sender_key' => strtolower(trim($senderKey)),
        ]);

        return $statement->fetch() !== false;
    }

    public function addSenderExclusion(int $userId, int $accountId, string $folderName, string $senderKey): void
    {
        $normalized = strtolower(trim($senderKey));
        if ($normalized === '' || $this->senderExclusionExists($userId, $accountId, $folderName, $normalized)) {
            return;
        }

        $statement = $this->pdo->prepare(
            "INSERT INTO mail_sender_exclusions (
                user_id, mail_account_id, folder_name, sender_key
             ) VALUES (
                :user_id, :mail_account_id, :folder_name, :sender_key
             )"
        );
        $statement->execute([
            'user_id' => $userId,
            'mail_account_id' => $accountId,
            'folder_name' => $folderName,
            'sender_key' => $normalized,
        ]);
    }

    public function removeSenderExclusion(int $userId, int $accountId, string $folderName, string $senderKey): void
    {
        $statement = $this->pdo->prepare(
            "DELETE FROM mail_sender_exclusions
             WHERE user_id = :user_id
               AND mail_account_id = :mail_account_id
               AND folder_name = :folder_name
               AND sender_key = :sender_key"
        );
        $statement->execute([
            'user_id' => $userId,
            'mail_account_id' => $accountId,
            'folder_name' => $folderName,
            'sender_key' => strtolower(trim($senderKey)),
        ]);
    }

    /**
     * @return array<int, int>
     */
    public function listIndexedUidsForFolder(int $userId, int $accountId, string $folderName): array
    {
        $statement = $this->pdo->prepare(
            "SELECT uid
             FROM mail_message_index
             WHERE user_id = :user_id
               AND mail_account_id = :mail_account_id
               AND folder_name = :folder_name
             ORDER BY uid ASC"
        );
        $statement->execute([
            'user_id' => $userId,
            'mail_account_id' => $accountId,
            'folder_name' => $folderName,
        ]);
        $rows = $statement->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $uids = [];
        foreach ($rows as $row) {
            $uid = (int) ($row['uid'] ?? 0);
            if ($uid > 0) {
                $uids[] = $uid;
            }
        }

        return $uids;
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     */
    public function upsertMessageIndexBatch(
        int $userId,
        int $accountId,
        string $folderName,
        array $messages
    ): void {
        if ($messages === []) {
            return;
        }

        $statement = $this->pdo->prepare(
            "INSERT INTO mail_message_index (
                user_id, mail_account_id, folder_name, uid, message_id,
                sender_key, sender_label, subject, message_timestamp, is_read
             ) VALUES (
                :user_id, :mail_account_id, :folder_name, :uid, :message_id,
                :sender_key, :sender_label, :subject, :message_timestamp, :is_read
             )
             ON DUPLICATE KEY UPDATE
                message_id = VALUES(message_id),
                sender_key = VALUES(sender_key),
                sender_label = VALUES(sender_label),
                subject = VALUES(subject),
                message_timestamp = VALUES(message_timestamp),
                is_read = VALUES(is_read),
                updated_at = CURRENT_TIMESTAMP"
        );

        foreach ($messages as $message) {
            $uid = (int) ($message['uid'] ?? 0);
            if ($uid <= 0) {
                continue;
            }

            $statement->execute([
                'user_id' => $userId,
                'mail_account_id' => $accountId,
                'folder_name' => $folderName,
                'uid' => $uid,
                'message_id' => mb_substr(trim((string) ($message['message_id'] ?? '')), 0, 255) ?: null,
                'sender_key' => mb_substr(strtolower(trim((string) ($message['sender_key'] ?? 'unknown'))), 0, 255),
                'sender_label' => mb_substr(trim((string) ($message['sender_label'] ?? 'Unbekannt')), 0, 255),
                'subject' => mb_substr(trim((string) ($message['subject'] ?? '(ohne Betreff)')), 0, 255),
                'message_timestamp' => (int) ($message['timestamp'] ?? 0),
                'is_read' => (bool) ($message['is_read'] ?? false) ? 1 : 0,
            ]);
        }
    }

    /**
     * @param array<int, int> $uids
     */
    public function deleteIndexedUidsForFolder(int $userId, int $accountId, string $folderName, array $uids): void
    {
        $uids = array_values(array_filter(array_map(static fn (int $uid): int => (int) $uid, $uids), static fn (int $uid): bool => $uid > 0));
        if ($uids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($uids), '?'));
        $sql = "DELETE FROM mail_message_index
                WHERE user_id = ?
                  AND mail_account_id = ?
                  AND folder_name = ?
                  AND uid IN ($placeholders)";
        $statement = $this->pdo->prepare($sql);
        $params = [$userId, $accountId, $folderName, ...$uids];
        $statement->execute($params);
    }

    /**
     * @param array<int, bool> $flagsByUid
     */
    public function updateIndexedReadFlagsForFolder(int $userId, int $accountId, string $folderName, array $flagsByUid): void
    {
        if ($flagsByUid === []) {
            return;
        }

        $statement = $this->pdo->prepare(
            "UPDATE mail_message_index
             SET is_read = :is_read,
                 updated_at = CURRENT_TIMESTAMP
             WHERE user_id = :user_id
               AND mail_account_id = :mail_account_id
               AND folder_name = :folder_name
               AND uid = :uid"
        );

        foreach ($flagsByUid as $uid => $isRead) {
            $uid = (int) $uid;
            if ($uid <= 0) {
                continue;
            }
            $statement->execute([
                'is_read' => $isRead ? 1 : 0,
                'user_id' => $userId,
                'mail_account_id' => $accountId,
                'folder_name' => $folderName,
                'uid' => $uid,
            ]);
        }
    }

    /**
     * @return array{messages: array<int, array<string, mixed>>, has_more: bool, next_until_uid: ?int}
     */
    public function listIndexedMessagesForFolder(
        int $userId,
        int $accountId,
        string $folderName,
        int $limit,
        ?int $untilUid = null,
        string $direction = 'desc',
        string $senderKey = ''
    ): array {
        $limit = max(1, min(100, $limit));
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';
        $senderKey = strtolower(trim($senderKey));

        $where = "user_id = :user_id AND mail_account_id = :mail_account_id AND folder_name = :folder_name";
        $params = [
            'user_id' => $userId,
            'mail_account_id' => $accountId,
            'folder_name' => $folderName,
        ];
        if ($senderKey !== '') {
            $where .= " AND sender_key = :sender_key";
            $params['sender_key'] = $senderKey;
        }
        if ($untilUid !== null && $untilUid > 0) {
            if ($direction === 'asc') {
                $where .= " AND uid <= :until_uid";
            } else {
                $where .= " AND uid >= :until_uid";
            }
            $params['until_uid'] = $untilUid;
        }

        $statement = $this->pdo->prepare(
            "SELECT uid, message_id, sender_key, sender_label, subject, message_timestamp, is_read
             FROM mail_message_index
             WHERE {$where}
             ORDER BY uid " . ($direction === 'asc' ? 'ASC' : 'DESC')
             . (($untilUid === null || $untilUid <= 0) ? " LIMIT {$limit}" : '')
        );
        $statement->execute($params);
        $rows = $statement->fetchAll();
        $rows = is_array($rows) ? $rows : [];

        $messages = [];
        foreach ($rows as $row) {
            $timestamp = (int) ($row['message_timestamp'] ?? 0);
            $messages[] = [
                'uid' => (int) ($row['uid'] ?? 0),
                'message_id' => (string) ($row['message_id'] ?? ''),
                'sender_key' => (string) ($row['sender_key'] ?? 'unknown'),
                'sender_label' => (string) ($row['sender_label'] ?? 'Unbekannt'),
                'subject' => (string) ($row['subject'] ?? '(ohne Betreff)'),
                'timestamp' => $timestamp,
                'date_label' => $timestamp > 0 ? date('Y-m-d H:i', $timestamp) : '-',
                'is_read' => (int) ($row['is_read'] ?? 0) === 1,
            ];
        }

        if ($messages === []) {
            return [
                'messages' => [],
                'has_more' => false,
                'next_until_uid' => null,
            ];
        }

        if (($untilUid === null || $untilUid <= 0) && count($messages) > $limit) {
            $messages = array_slice($messages, 0, $limit);
        }

        $uids = array_map(static fn (array $entry): int => (int) ($entry['uid'] ?? 0), $messages);
        $uids = array_values(array_filter($uids, static fn (int $uid): bool => $uid > 0));
        if ($uids === []) {
            return [
                'messages' => [],
                'has_more' => false,
                'next_until_uid' => null,
            ];
        }

        $currentMinUid = min($uids);
        $currentMaxUid = max($uids);

        $moreWhere = "user_id = :user_id AND mail_account_id = :mail_account_id AND folder_name = :folder_name";
        $moreParams = [
            'user_id' => $userId,
            'mail_account_id' => $accountId,
            'folder_name' => $folderName,
        ];
        if ($senderKey !== '') {
            $moreWhere .= " AND sender_key = :sender_key";
            $moreParams['sender_key'] = $senderKey;
        }
        if ($direction === 'asc') {
            $moreWhere .= " AND uid > :uid_boundary";
            $moreParams['uid_boundary'] = $currentMaxUid;
            $nextOrder = 'ASC';
        } else {
            $moreWhere .= " AND uid < :uid_boundary";
            $moreParams['uid_boundary'] = $currentMinUid;
            $nextOrder = 'DESC';
        }

        $hasMoreStatement = $this->pdo->prepare(
            "SELECT uid
             FROM mail_message_index
             WHERE {$moreWhere}
             ORDER BY uid {$nextOrder}
             LIMIT {$limit}"
        );
        $hasMoreStatement->execute($moreParams);
        $moreRows = $hasMoreStatement->fetchAll();
        $moreRows = is_array($moreRows) ? $moreRows : [];
        $hasMore = $moreRows !== [];

        $nextUntilUid = null;
        if ($hasMore) {
            $moreUids = [];
            foreach ($moreRows as $row) {
                $uid = (int) ($row['uid'] ?? 0);
                if ($uid > 0) {
                    $moreUids[] = $uid;
                }
            }
            if ($moreUids !== []) {
                $nextUntilUid = $direction === 'asc' ? max($moreUids) : min($moreUids);
            }
        }

        return [
            'messages' => $messages,
            'has_more' => $hasMore,
            'next_until_uid' => $nextUntilUid,
        ];
    }

    /**
     * @param array<int, string> $excludedSenderKeys
     * @return array<int, array<string, mixed>>
     */
    public function listIndexedSenderStatsForFolder(
        int $userId,
        int $accountId,
        string $folderName,
        array $excludedSenderKeys = []
    ): array {
        $where = "user_id = :user_id AND mail_account_id = :mail_account_id AND folder_name = :folder_name";
        $params = [
            'user_id' => $userId,
            'mail_account_id' => $accountId,
            'folder_name' => $folderName,
        ];

        $excludedSenderKeys = array_values(array_filter(array_map(
            static fn (string $key): string => strtolower(trim($key)),
            $excludedSenderKeys
        )));
        if ($excludedSenderKeys !== []) {
            $placeholders = [];
            foreach ($excludedSenderKeys as $index => $key) {
                $paramName = 'excluded_' . $index;
                $placeholders[] = ':' . $paramName;
                $params[$paramName] = $key;
            }
            $where .= ' AND sender_key NOT IN (' . implode(',', $placeholders) . ')';
        }

        $statement = $this->pdo->prepare(
            "SELECT sender_key,
                    MAX(sender_label) AS sender_label,
                    COUNT(*) AS total_count,
                    SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread_count,
                    MAX(message_timestamp) AS latest_timestamp
             FROM mail_message_index
             WHERE {$where}
             GROUP BY sender_key
             ORDER BY latest_timestamp DESC, sender_label ASC"
        );
        $statement->execute($params);
        $rows = $statement->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $timestamp = (int) ($row['latest_timestamp'] ?? 0);
            $result[] = [
                'sender_key' => (string) ($row['sender_key'] ?? ''),
                'sender_label' => (string) ($row['sender_label'] ?? 'Unbekannt'),
                'total_count' => (int) ($row['total_count'] ?? 0),
                'unread_count' => (int) ($row['unread_count'] ?? 0),
                'latest_timestamp' => $timestamp,
                'latest_date_label' => $timestamp > 0 ? date('Y-m-d H:i', $timestamp) : '-',
            ];
        }

        return $result;
    }

    /**
     * @return array<string, array{total_count:int, unread_count:int}>
     */
    public function listIndexedFolderStatsForAccount(int $userId, int $accountId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT folder_name,
                    COUNT(*) AS total_count,
                    SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread_count
             FROM mail_message_index
             WHERE user_id = :user_id
               AND mail_account_id = :mail_account_id
             GROUP BY folder_name"
        );
        $statement->execute([
            'user_id' => $userId,
            'mail_account_id' => $accountId,
        ]);
        $rows = $statement->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $folderName = (string) ($row['folder_name'] ?? '');
            if ($folderName === '') {
                continue;
            }
            $result[$folderName] = [
                'total_count' => (int) ($row['total_count'] ?? 0),
                'unread_count' => (int) ($row['unread_count'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @return array{total_count:int, unread_count:int}
     */
    public function getIndexedFolderStats(int $userId, int $accountId, string $folderName): array
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) AS total_count,
                    SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread_count
             FROM mail_message_index
             WHERE user_id = :user_id
               AND mail_account_id = :mail_account_id
               AND folder_name = :folder_name"
        );
        $statement->execute([
            'user_id' => $userId,
            'mail_account_id' => $accountId,
            'folder_name' => $folderName,
        ]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return ['total_count' => 0, 'unread_count' => 0];
        }

        return [
            'total_count' => (int) ($row['total_count'] ?? 0),
            'unread_count' => (int) ($row['unread_count'] ?? 0),
        ];
    }

    private function nextSortOrder(int $userId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(MAX(sort_order), 0) AS max_sort
             FROM mail_accounts
             WHERE user_id = :user_id'
        );
        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch();
        $maxSort = is_array($row) ? (int) ($row['max_sort'] ?? 0) : 0;

        return $maxSort + 10;
    }
}
