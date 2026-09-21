<?php

declare(strict_types=1);

use Modulon\Core\Database\Migration;
use Modulon\Core\Database\SchemaHelper;

return new class implements Migration
{
    public function key(): string
    {
        return 'modulnest.mail-client_003_add_recipient_columns';
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
        return 'Mail-Client: Empfänger-Spalten recipient_name und recipient_address für Message Index.';
    }

    public function up(PDO $pdo, SchemaHelper $schema): void
    {
        if (!$schema->columnExists('mail_client_message_index', 'recipient_name')) {
            $pdo->exec("ALTER TABLE mail_client_message_index ADD COLUMN recipient_name VARCHAR(255) DEFAULT NULL AFTER sender_name");
        } else {
            $pdo->exec("ALTER TABLE mail_client_message_index MODIFY recipient_name VARCHAR(255) DEFAULT NULL");
        }
        if (!$schema->columnExists('mail_client_message_index', 'recipient_address')) {
            $pdo->exec("ALTER TABLE mail_client_message_index ADD COLUMN recipient_address VARCHAR(255) DEFAULT NULL AFTER recipient_name");
        } else {
            $pdo->exec("ALTER TABLE mail_client_message_index MODIFY recipient_address VARCHAR(255) DEFAULT NULL");
        }
    }
};
