<?php

declare(strict_types=1);

use Modulon\Core\Database\Migration;
use Modulon\Core\Database\SchemaHelper;

return new class implements Migration {
    public function key(): string { return 'modulnest.sneak-preview_002_storage_paths'; }
    public function scope(): string { return 'module'; }
    public function moduleKey(): ?string { return 'modulnest.sneak-preview'; }
    public function description(): string { return 'Persistente Poster über den Modulstorage ausliefern.'; }
    public function up(PDO $pdo, SchemaHelper $schema): void
    {
        if ($schema->tableExists('sneak_preview_entries')) {
            $pdo->exec("UPDATE sneak_preview_entries SET poster_path=CONCAT('/sneak-preview/posters/',SUBSTRING_INDEX(poster_path,'/',-1)) WHERE poster_path LIKE '/assets/sneak-preview/posters/%'");
        }
    }
};
