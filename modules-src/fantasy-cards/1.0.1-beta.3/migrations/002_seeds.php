<?php

declare(strict_types=1);

use Modulon\Core\Database\Migration;
use Modulon\Core\Database\SchemaHelper;

return new class implements Migration {
    public function key(): string
    {
        return 'modulnest.fantasy-cards_002_seeds';
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
        return 'Legt die versionierten Fantasy-Cards-Startinhalte idempotent an.';
    }

    public function up(PDO $pdo, SchemaHelper $schema): void
    {
        $schema->runSqlFile(__DIR__ . '/seeds.sql');
    }
};
