<?php

declare(strict_types=1);

namespace ModulNest\Wiki;

use Modulon\Core\Modules\DataPortability\DataPortabilityArchiveReader;
use Modulon\Core\Modules\DataPortability\DataPortabilityFileCollector;
use Modulon\Core\Modules\DataPortability\DataPortabilityProviderInterface;
use PDO;

final readonly class WikiDataPortabilityProvider implements DataPortabilityProviderInterface
{
    public function __construct(private PDO $pdo) {}
    public function key(): string { return 'modulnest.wiki'; }
    public function label(): string { return 'Wiki'; }
    public function routePrefix(): string { return '/wiki'; }
    public function description(): string { return 'Portable Wiki-Quellenkonfiguration ohne Inhalte, Cache oder Synchronisationshistorie.'; }
    public function schemaVersion(): int { return 1; }
    public function hasFiles(): bool { return false; }
    public function sensitivityNote(): string { return 'Lokale Pfade werden nie exportiert; GitHub-Konfigurationen können Repository-Namen enthalten.'; }
    public function supportsReplaceImport(): bool { return true; }
    public function scopes(): array { return ['admin']; }

    public function export(int $userId, DataPortabilityFileCollector $files): array
    {
        $row = $this->pdo->query('SELECT source_type,repository_owner,repository_name,ref_name,docs_root,enabled FROM wiki_sources ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $warnings = [];
        $source = null;
        if (is_array($row) && $row['source_type'] === 'github') {
            $source = ['source_type'=>'github','owner'=>(string)$row['repository_owner'],'repository'=>(string)$row['repository_name'],'ref'=>(string)$row['ref_name'],'docs_root'=>(string)$row['docs_root'],'enabled'=>(bool)$row['enabled']];
        } elseif (is_array($row)) {
            $warnings[] = 'Die lokale Wiki-Quelle wurde aus Sicherheitsgründen ohne Pfad ausgelassen.';
        }
        return ['files'=>['data.json'=>['schema_version'=>1,'source'=>$source]],'counts'=>['sources'=>$source===null?0:1],'warnings'=>$warnings];
    }

    public function previewImport(array $payload, array $manifestModule, DataPortabilityArchiveReader $archive, int $targetUserId): array
    {
        $source = $payload['data']['source'] ?? null;
        $valid = is_array($source) && ($source['source_type'] ?? null) === 'github';
        return ['counts'=>['sources'=>$valid?1:0],'warnings'=>$valid?['Vorhandene Wiki-Quellenkonfiguration wird ersetzt; synchronisierte Inhalte werden nicht importiert.']:['Keine portable GitHub-Quelle im Archiv.'],'can_import'=>$valid];
    }

    public function import(array $payload, array $manifestModule, DataPortabilityArchiveReader $archive, int $targetUserId, string $importMode = 'merge'): array
    {
        $source = $payload['data']['source'] ?? null;
        if (!is_array($source) || ($source['source_type'] ?? null) !== 'github') return ['created'=>0,'updated'=>0,'skipped'=>1,'warnings'=>['Keine portable Wiki-Quelle importiert.']];
        $data = ['source_type'=>'github','owner'=>$this->safe($source['owner']??'',100),'repo'=>$this->safe($source['repository']??'',100),'ref'=>$this->safe($source['ref']??'main',160),'root'=>$this->root($source['docs_root']??'docs'),'enabled'=>!empty($source['enabled'])?1:0];
        $existing = $this->pdo->query('SELECT id FROM wiki_sources ORDER BY id LIMIT 1')->fetchColumn();
        if ($existing === false) {
            $statement=$this->pdo->prepare('INSERT INTO wiki_sources(source_type,repository_owner,repository_name,ref_name,docs_root,enabled) VALUES(:source_type,:owner,:repo,:ref,:root,:enabled)');
        } else {
            $data['id']=$existing;$statement=$this->pdo->prepare('UPDATE wiki_sources SET source_type=:source_type,repository_owner=:owner,repository_name=:repo,ref_name=:ref,docs_root=:root,enabled=:enabled WHERE id=:id');
        }
        $statement->execute($data);
        return ['created'=>$existing===false?1:0,'updated'=>$existing===false?0:1,'skipped'=>0,'warnings'=>['Nach dem Import ist eine manuelle Wiki-Synchronisierung erforderlich.']];
    }

    private function safe(mixed $value, int $length): string { return mb_substr(trim((string)$value),0,$length); }
    private function root(mixed $value): string { $value=trim(str_replace('\\','/',(string)$value),'/');return $value===''||str_contains($value,'..')?'docs':mb_substr($value,0,255); }
}
