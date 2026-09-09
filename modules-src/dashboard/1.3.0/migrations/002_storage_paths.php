<?php

declare(strict_types=1);

use Modulon\Core\Database\Migration;
use Modulon\Core\Database\SchemaHelper;

return new class implements Migration {
    public function key(): string { return 'modulnest.dashboard_002_storage_paths'; }
    public function scope(): string { return 'module'; }
    public function moduleKey(): ?string { return 'modulnest.dashboard'; }
    public function description(): string { return 'Persistente Favicons über die Dashboard-Route ausliefern.'; }
    public function up(PDO $pdo, SchemaHelper $schema): void
    {
        if ($schema->tableExists('dashboard_links')) {
            $pdo->exec("UPDATE dashboard_links SET favicon_url=CONCAT('/dashboard/favicons/',SUBSTRING_INDEX(favicon_url,'/',-1)) WHERE favicon_url LIKE '/assets/favicons/fav-%'");
        }
    }
};
