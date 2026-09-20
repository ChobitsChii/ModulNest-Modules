<?php

declare(strict_types=1);

use Modulon\Core\Database\Migration;
use Modulon\Core\Database\SchemaHelper;

return new class implements Migration
{
    public function key(): string
    {
        return 'modulnest.mail-client_002_sync_progress';
    }

    public function scope(): string
    {
        return 'module';
    }

    public function moduleKey(): string
    {
        return 'modulnest.mail-client';
    }

    public function description(): string
    {
        return 'Mail-Client: Sync-Fortschritts-Tabelle für progressiven IMAP-Hintergrund-Sync.';
    }

    public function up(PDO $pdo, SchemaHelper $schema): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS mail_client_sync_progress (
                account_id    INT UNSIGNED  NOT NULL,
                folder_name   VARCHAR(255)  NOT NULL,
                total_uids    INT UNSIGNED  NOT NULL DEFAULT 0,
                synced_uids   INT UNSIGNED  NOT NULL DEFAULT 0,
                sync_offset   INT UNSIGNED  NOT NULL DEFAULT 0,
                pending_uids  MEDIUMTEXT    NULL COMMENT 'JSON-Array der noch zu ladenden UIDs (absteigend sortiert)',
                status        ENUM('idle','syncing','complete','error') NOT NULL DEFAULT 'idle',
                error_message TEXT          NULL,
                started_at    TIMESTAMP     NULL,
                updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (account_id, folder_name(200))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
};

