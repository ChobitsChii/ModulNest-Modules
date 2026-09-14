<?php

declare(strict_types=1);

use Modulon\Core\Database\Migration;
use Modulon\Core\Database\SchemaHelper;

return new class implements Migration {
    public function key(): string
    {
        return 'modulnest.mail_001_schema';
    }

    public function scope(): string
    {
        return 'module';
    }

    public function moduleKey(): ?string
    {
        return 'modulnest.mail';
    }

    public function description(): string
    {
        return 'Erstellt das native Mail-v2-Schema.';
    }

    public function up(PDO $pdo, SchemaHelper $schema): void
    {
        $schema->runSqlFile(__DIR__ . '/schema.sql');
    }
};
