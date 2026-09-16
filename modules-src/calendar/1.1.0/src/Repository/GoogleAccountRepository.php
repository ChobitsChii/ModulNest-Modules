<?php

declare(strict_types=1);

namespace ModulNest\Calendar\Repository;

use DateTimeInterface;
use PDO;

final class GoogleAccountRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{
     *     id: int,
     *     user_id: int,
     *     google_email: string,
     *     access_token: string,
     *     refresh_token: ?string,
     *     token_expires_at: ?string,
     *     scopes: ?string,
     *     last_synced_at: ?string,
     *     created_at: string,
     *     updated_at: string
     * }|null
     */
    public function findByUserId(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM `calendar_google_accounts` WHERE `user_id` = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'google_email' => (string) ($row['google_email'] ?? ''),
            'access_token' => (string) ($row['access_token'] ?? ''),
            'refresh_token' => isset($row['refresh_token']) && $row['refresh_token'] !== '' ? (string) $row['refresh_token'] : null,
            'token_expires_at' => isset($row['token_expires_at']) && $row['token_expires_at'] !== '' ? (string) $row['token_expires_at'] : null,
            'scopes' => isset($row['scopes']) && $row['scopes'] !== '' ? (string) $row['scopes'] : null,
            'last_synced_at' => isset($row['last_synced_at']) && $row['last_synced_at'] !== '' ? (string) $row['last_synced_at'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    public function save(
        int $userId,
        string $email,
        string $accessToken,
        ?string $refreshToken,
        ?DateTimeInterface $expiresAt,
        ?string $scopes
    ): void {
        $existing = $this->findByUserId($userId);
        $finalRefreshToken = $refreshToken ?? ($existing['refresh_token'] ?? null);

        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $stmt = $this->pdo->prepare(
                'INSERT INTO `calendar_google_accounts`
                 (`user_id`, `google_email`, `access_token`, `refresh_token`, `token_expires_at`, `scopes`, `updated_at`)
                 VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                 ON CONFLICT(`user_id`) DO UPDATE SET
                    `google_email` = excluded.`google_email`,
                    `access_token` = excluded.`access_token`,
                    `refresh_token` = excluded.`refresh_token`,
                    `token_expires_at` = excluded.`token_expires_at`,
                    `scopes` = excluded.`scopes`,
                    `updated_at` = CURRENT_TIMESTAMP'
            );
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT INTO `calendar_google_accounts`
                 (`user_id`, `google_email`, `access_token`, `refresh_token`, `token_expires_at`, `scopes`)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    `google_email` = VALUES(`google_email`),
                    `access_token` = VALUES(`access_token`),
                    `refresh_token` = VALUES(`refresh_token`),
                    `token_expires_at` = VALUES(`token_expires_at`),
                    `scopes` = VALUES(`scopes`),
                    `updated_at` = CURRENT_TIMESTAMP'
            );
        }

        $stmt->execute([
            $userId,
            $email,
            $accessToken,
            $finalRefreshToken,
            $expiresAt?->format('Y-m-d H:i:s'),
            $scopes,
        ]);
    }

    public function updateAccessToken(int $userId, string $accessToken, ?DateTimeInterface $expiresAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE `calendar_google_accounts` SET
                `access_token` = ?,
                `token_expires_at` = ?,
                `updated_at` = CURRENT_TIMESTAMP
             WHERE `user_id` = ?'
        );
        $stmt->execute([
            $accessToken,
            $expiresAt?->format('Y-m-d H:i:s'),
            $userId,
        ]);
    }

    public function touchLastSynced(int $userId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE `calendar_google_accounts` SET `last_synced_at` = CURRENT_TIMESTAMP WHERE `user_id` = ?'
        );
        $stmt->execute([$userId]);
    }

    public function deleteByUserId(int $userId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM `calendar_google_accounts` WHERE `user_id` = ?');
        return $stmt->execute([$userId]);
    }
}
