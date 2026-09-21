<?php

declare(strict_types=1);

namespace ModulNest\MailClient\Repository;

use PDO;

final class AliasRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function listForAccount(int $accountId, int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, account_id, user_id, display_name, email_address, created_at, updated_at
             FROM mail_client_aliases
             WHERE account_id = :aid AND user_id = :uid
             ORDER BY id ASC'
        );
        $stmt->execute(['aid' => $accountId, 'uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /** @return array<int, array<string, mixed>> */
    public function listForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, account_id, user_id, display_name, email_address, created_at, updated_at
             FROM mail_client_aliases
             WHERE user_id = :uid
             ORDER BY account_id ASC, id ASC'
        );
        $stmt->execute(['uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /** @return array<string, mixed>|null */
    public function findForUser(int $aliasId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, account_id, user_id, display_name, email_address, created_at, updated_at
             FROM mail_client_aliases
             WHERE id = :id AND user_id = :uid LIMIT 1'
        );
        $stmt->execute(['id' => $aliasId, 'uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function create(int $accountId, int $userId, string $displayName, string $emailAddress): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO mail_client_aliases (account_id, user_id, display_name, email_address)
             VALUES (:aid, :uid, :name, :email)'
        );
        $stmt->execute([
            'aid'   => $accountId,
            'uid'   => $userId,
            'name'  => trim($displayName),
            'email' => strtolower(trim($emailAddress)),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $aliasId, int $userId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM mail_client_aliases WHERE id = :id AND user_id = :uid');
        $stmt->execute(['id' => $aliasId, 'uid' => $userId]);
    }

    /**
     * Synchronisiert Aliase für ein Konto aus einem Array von ['name' => ..., 'email' => ...].
     *
     * @param array<int, array{name?: string, email?: string}> $aliasList
     */
    public function syncForAccount(int $accountId, int $userId, array $aliasList): void
    {
        $this->pdo->beginTransaction();
        try {
            $deleteStmt = $this->pdo->prepare('DELETE FROM mail_client_aliases WHERE account_id = :aid AND user_id = :uid');
            $deleteStmt->execute(['aid' => $accountId, 'uid' => $userId]);

            $insertStmt = $this->pdo->prepare(
                'INSERT INTO mail_client_aliases (account_id, user_id, display_name, email_address)
                 VALUES (:aid, :uid, :name, :email)'
            );

            foreach ($aliasList as $item) {
                $email = strtolower(trim((string) ($item['email'] ?? '')));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                $name = trim((string) ($item['name'] ?? ''));
                $insertStmt->execute([
                    'aid'   => $accountId,
                    'uid'   => $userId,
                    'name'  => $name,
                    'email' => $email,
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
