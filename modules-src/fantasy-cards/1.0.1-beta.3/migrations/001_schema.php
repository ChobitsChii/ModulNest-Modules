<?php

declare(strict_types=1);

use Modulon\Core\Database\Migration;
use Modulon\Core\Database\SchemaHelper;

return new class implements Migration {
    public function key(): string
    {
        return 'modulnest.fantasy-cards_001_schema';
    }

    public function scope(): string
    {
        return 'module';
    }

    public function moduleKey(): ?string
    {
        return 'modulnest.fantasy-cards';
    }

    public function description(): string
    {
        return 'Erstellt das native Fantasy-Cards-v2-Schema.';
    }

    public function up(PDO $pdo, SchemaHelper $schema): void
    {
        $schema->runSqlFile(__DIR__ . '/schema.sql');
    }
};
