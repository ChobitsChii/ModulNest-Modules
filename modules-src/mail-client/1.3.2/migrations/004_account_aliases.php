<?php

declare(strict_types=1);

use Modulon\Core\Database\Migration;
use Modulon\Core\Database\SchemaHelper;

return new class implements Migration
{
    public function key(): string
    {
        return 'modulnest.mail-client_004_account_aliases';
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
        return 'Mail-Client: Absender-Aliase und Identitäten pro Mail-Konto.';
    }

    public function up(PDO $pdo, SchemaHelper $schema): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS mail_client_aliases (
                id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                account_id    BIGINT UNSIGNED NOT NULL,
                user_id       BIGINT UNSIGNED NOT NULL,
                display_name  VARCHAR(120)    NOT NULL DEFAULT '',
                email_address VARCHAR(190)    NOT NULL,
                created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_mc_aliases_account FOREIGN KEY (account_id) REFERENCES mail_client_accounts (id) ON DELETE CASCADE,
                CONSTRAINT fk_mc_aliases_user    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                INDEX idx_mca_account (account_id),
                INDEX idx_mca_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }
};
