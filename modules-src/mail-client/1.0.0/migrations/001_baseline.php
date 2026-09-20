<?php

declare(strict_types=1);

use Modulon\Core\Database\Migration;
use Modulon\Core\Database\SchemaHelper;

return new class implements Migration {
    public function key(): string { return 'modulnest.mail-client_001_baseline'; }
    public function scope(): string { return 'module'; }
    public function moduleKey(): ?string { return 'modulnest.mail-client'; }
    public function description(): string { return 'Mail-Client Basisschema: Konten, Ordner-Cache, Nachrichten-Index, Absender-Whitelist.'; }
    public function up(PDO $pdo, SchemaHelper $schema): void { $schema->runSqlFile(__DIR__ . '/schema.sql'); }
};

