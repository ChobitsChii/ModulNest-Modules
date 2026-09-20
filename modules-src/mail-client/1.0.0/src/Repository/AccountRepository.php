<?php

declare(strict_types=1);

namespace ModulNest\MailClient\Repository;

use PDO;

/**
 * CRUD für mail_client_accounts.
 * Passwörter werden hier NIE entschlüsselt – das bleibt dem Controller vorbehalten.
 */
final class AccountRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function listForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, display_name, email_address,
                    imap_host, imap_port, imap_encryption, imap_username,
                    smtp_host, smtp_port, smtp_encryption, smtp_username,
                    encrypted_password, is_active, sort_order, uidvalidity_cache,
                    created_at, updated_at
             FROM mail_client_accounts
             WHERE user_id = :uid
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /** @return array<string, mixed>|null */
    public function findForUser(int $accountId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, display_name, email_address,
                    imap_host, imap_port, imap_encryption, imap_username,
                    smtp_host, smtp_port, smtp_encryption, smtp_username,
                    encrypted_password, is_active, sort_order, uidvalidity_cache,
                    created_at, updated_at
             FROM mail_client_accounts
             WHERE id = :id AND user_id = :uid LIMIT 1'
        );
        $stmt->execute(['id' => $accountId, 'uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $data */
    public function create(int $userId, array $data): int
    {
        $sortOrder = $this->nextSortOrder($userId);
        $stmt = $this->pdo->prepare(
            'INSERT INTO mail_client_accounts
                (user_id, display_name, email_address,
                 imap_host, imap_port, imap_encryption, imap_username,
                 smtp_host, smtp_port, smtp_encryption, smtp_username,
                 encrypted_password, is_active, sort_order)
             VALUES
                (:user_id, :display_name, :email_address,
                 :imap_host, :imap_port, :imap_encryption, :imap_username,
                 :smtp_host, :smtp_port, :smtp_encryption, :smtp_username,
                 :encrypted_password, 1, :sort_order)'
        );
        $stmt->execute([
            'user_id'            => $userId,
            'display_name'       => (string) $data['display_name'],
            'email_address'      => strtolower(trim((string) $data['email_address'])),
            'imap_host'          => (string) $data['imap_host'],
            'imap_port'          => (int) $data['imap_port'],
            'imap_encryption'    => (string) $data['imap_encryption'],
            'imap_username'      => (string) $data['imap_username'],
            'smtp_host'          => (string) $data['smtp_host'],
            'smtp_port'          => (int) $data['smtp_port'],
            'smtp_encryption'    => (string) $data['smtp_encryption'],
            'smtp_username'      => (string) $data['smtp_username'],
            'encrypted_password' => (string) $data['encrypted_password'],
            'sort_order'         => $sortOrder,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function update(int $accountId, int $userId, array $data): void
    {
        // Passwort nur updaten wenn ein neues mitgegeben wird
        if (isset($data['encrypted_password']) && $data['encrypted_password'] !== '') {
            $stmt = $this->pdo->prepare(
                'UPDATE mail_client_accounts SET
                    display_name=:display_name, email_address=:email_address,
                    imap_host=:imap_host, imap_port=:imap_port, imap_encryption=:imap_encryption,
                    imap_username=:imap_username,
                    smtp_host=:smtp_host, smtp_port=:smtp_port, smtp_encryption=:smtp_encryption,
                    smtp_username=:smtp_username,
                    encrypted_password=:encrypted_password
                 WHERE id=:id AND user_id=:uid'
            );
            $stmt->execute([
                'display_name'       => (string) $data['display_name'],
                'email_address'      => strtolower(trim((string) $data['email_address'])),
                'imap_host'          => (string) $data['imap_host'],
                'imap_port'          => (int) $data['imap_port'],
                'imap_encryption'    => (string) $data['imap_encryption'],
                'imap_username'      => (string) $data['imap_username'],
                'smtp_host'          => (string) $data['smtp_host'],
                'smtp_port'          => (int) $data['smtp_port'],
                'smtp_encryption'    => (string) $data['smtp_encryption'],
                'smtp_username'      => (string) $data['smtp_username'],
                'encrypted_password' => (string) $data['encrypted_password'],
                'id'  => $accountId,
                'uid' => $userId,
            ]);
        } else {
            $stmt = $this->pdo->prepare(
                'UPDATE mail_client_accounts SET
                    display_name=:display_name, email_address=:email_address,
                    imap_host=:imap_host, imap_port=:imap_port, imap_encryption=:imap_encryption,
                    imap_username=:imap_username,
                    smtp_host=:smtp_host, smtp_port=:smtp_port, smtp_encryption=:smtp_encryption,
                    smtp_username=:smtp_username
                 WHERE id=:id AND user_id=:uid'
            );
            $stmt->execute([
                'display_name'    => (string) $data['display_name'],
                'email_address'   => strtolower(trim((string) $data['email_address'])),
                'imap_host'       => (string) $data['imap_host'],
                'imap_port'       => (int) $data['imap_port'],
                'imap_encryption' => (string) $data['imap_encryption'],
                'imap_username'   => (string) $data['imap_username'],
                'smtp_host'       => (string) $data['smtp_host'],
                'smtp_port'       => (int) $data['smtp_port'],
                'smtp_encryption' => (string) $data['smtp_encryption'],
                'smtp_username'   => (string) $data['smtp_username'],
                'id'  => $accountId,
                'uid' => $userId,
            ]);
        }
    }

    public function delete(int $accountId, int $userId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM mail_client_accounts WHERE id=:id AND user_id=:uid'
        );
        $stmt->execute(['id' => $accountId, 'uid' => $userId]);
    }

    public function updateUidvalidityCache(int $accountId, string $jsonCache): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE mail_client_accounts SET uidvalidity_cache=:cache WHERE id=:id'
        );
        $stmt->execute(['cache' => $jsonCache, 'id' => $accountId]);
    }

    private function nextSortOrder(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(sort_order),0)+1 FROM mail_client_accounts WHERE user_id=:uid'
        );
        $stmt->execute(['uid' => $userId]);
        return (int) $stmt->fetchColumn();
    }
}

