<?php

declare(strict_types=1);

use Modulon\Core\Database\Migration;
use Modulon\Core\Database\SchemaHelper;

return new class implements Migration {
    public function key(): string
    {
        return 'modulnest.calendar_002_google_and_recurring';
    }

    public function scope(): string
    {
        return 'module';
    }

    public function moduleKey(): ?string
    {
        return 'modulnest.calendar';
    }

    public function description(): string
    {
        return 'Google OAuth Accounts, Wiederholungsregeln und Google-Event-IDs für Kalender hinzufügen.';
    }

    public function up(PDO $pdo, SchemaHelper $schema): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        // 1. Create calendar_google_accounts table
        if ($driver === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS calendar_google_accounts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL UNIQUE,
                    google_email TEXT NOT NULL DEFAULT '',
                    access_token TEXT NOT NULL,
                    refresh_token TEXT,
                    token_expires_at DATETIME,
                    scopes TEXT,
                    last_synced_at DATETIME,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
            ");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `calendar_google_accounts` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` BIGINT UNSIGNED NOT NULL,
                    `google_email` VARCHAR(255) NOT NULL DEFAULT '',
                    `access_token` TEXT NOT NULL,
                    `refresh_token` TEXT NULL,
                    `token_expires_at` DATETIME NULL,
                    `scopes` TEXT NULL,
                    `last_synced_at` DATETIME NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `idx_cga_user_id` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        }

        // 2. Add columns to calendar_appointments
        $columnsToAdd = [
            'recurrence_rule' => 'VARCHAR(255) NULL',
            'recurrence_parent_id' => 'BIGINT UNSIGNED NULL',
            'google_event_id' => 'VARCHAR(255) NULL',
            'google_etag' => 'VARCHAR(255) NULL',
        ];

        foreach ($columnsToAdd as $column => $type) {
            $hasColumn = false;
            if ($driver === 'sqlite') {
                $check = $pdo->query("PRAGMA table_info(calendar_appointments)")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($check as $col) {
                    if (($col['name'] ?? '') === $column) {
                        $hasColumn = true;
                        break;
                    }
                }
            } else {
                $hasColumn = $schema->columnExists('calendar_appointments', $column);
            }

            if (!$hasColumn) {
                if ($driver === 'sqlite') {
                    $sqliteType = str_starts_with($type, 'BIGINT') ? 'INTEGER' : 'TEXT';
                    $pdo->exec("ALTER TABLE calendar_appointments ADD COLUMN {$column} {$sqliteType} NULL");
                } else {
                    $pdo->exec("ALTER TABLE `calendar_appointments` ADD COLUMN `{$column}` {$type}");
                }
            }
        }
    }
};
