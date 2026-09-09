<?php

declare(strict_types=1);

namespace ModulNest\Banking;

use PDO;
use Throwable;

final class BankingRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @return array<string, int|bool>
     */
    public function nativeSnapshotForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [
                'has_native_tables' => false,
                'transaction_count' => 0,
                'account_count' => 0,
            ];
        }

        if (!$this->tableExists('banking_transactions')) {
            return [
                'has_native_tables' => false,
                'transaction_count' => 0,
                'account_count' => 0,
            ];
        }

        return [
            'has_native_tables' => true,
            'transaction_count' => $this->countRowsForUser('banking_transactions', $userId),
            'account_count' => $this->tableExists('banking_accounts')
                ? $this->countRowsForUser('banking_accounts', $userId)
                : 0,
        ];
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table'
        );
        $statement->execute(['table' => $table]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function countRowsForUser(string $table, int $userId): int
    {
        if (!preg_match('/^[a-z0-9_]+$/', $table)) {
            return 0;
        }

        try {
            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE user_id = :user_id');
            $statement->execute(['user_id' => $userId]);

            return (int) $statement->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }
}
