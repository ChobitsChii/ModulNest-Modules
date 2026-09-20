<?php

declare(strict_types=1);

namespace ModulNest\Mirror\Portability;

use Modulon\Core\Modules\DataPortability\DataPortabilityArchiveReader;
use Modulon\Core\Modules\DataPortability\DataPortabilityFileCollector;
use Modulon\Core\Modules\DataPortability\DataPortabilityProviderInterface;
use PDO;
use Throwable;

final class MirrorDataPortabilityProvider implements DataPortabilityProviderInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function key(): string
    {
        return 'mirror';
    }

    public function label(): string
    {
        return 'Mirror Manager';
    }

    public function routePrefix(): string
    {
        return '/admin/mirror';
    }

    public function description(): string
    {
        return 'Konfigurierte Repository- und Update-Mirrors.';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function hasFiles(): bool
    {
        return false;
    }

    public function sensitivityNote(): string
    {
        return 'Enthält lokale Dateipfade und Server-Konfigurationen.';
    }

    public function supportsReplaceImport(): bool
    {
        return true;
    }

    public function scopes(): array
    {
        return ['system'];
    }

    public function export(int $userId, DataPortabilityFileCollector $files): array
    {
        $stmt = $this->pdo->query('SELECT * FROM `mirror_configs` ORDER BY `id` ASC');
        $mirrors = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'files' => [
                'mirrors.json' => [
                    'schema_version' => $this->schemaVersion(),
                    'mirrors' => $mirrors,
                ],
            ],
            'counts' => [
                'mirrors' => count($mirrors),
            ],
            'warnings' => [$this->sensitivityNote()],
        ];
    }

    public function previewImport(array $payload, array $manifestModule, DataPortabilityArchiveReader $archive, int $targetUserId): array
    {
        $mirrors = $payload['mirrors']['mirrors'] ?? [];

        return [
            'counts' => [
                'mirrors' => count($mirrors),
            ],
            'warnings' => [
                $this->sensitivityNote(),
                'Import überschreibt keine bestehenden Mirrors (Merge-Modus), außer bei explizitem Replace.',
            ],
            'can_import' => true,
        ];
    }

    public function import(array $payload, array $manifestModule, DataPortabilityArchiveReader $archive, int $targetUserId, string $importMode = 'merge'): array
    {
        $mirrors = $payload['mirrors']['mirrors'] ?? [];
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $warnings = [];

        $this->pdo->beginTransaction();
        try {
            if ($importMode === 'replace') {
                $this->pdo->exec('TRUNCATE TABLE `mirror_configs`');
            }

            foreach ($mirrors as $m) {
                if (!is_array($m)) {
                    $skipped++;
                    continue;
                }

                $stmt = $this->pdo->prepare(
                    'INSERT INTO `mirror_configs`
                     (`name`, `type`, `source_type`, `source_url`, `source_branch`, `target_path`, `public_url`, `enabled`, `last_status`)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    (string) ($m['name'] ?? ''),
                    (string) ($m['type'] ?? 'repository'),
                    (string) ($m['source_type'] ?? 'github'),
                    (string) ($m['source_url'] ?? ''),
                    (string) ($m['source_branch'] ?? 'main'),
                    (string) ($m['target_path'] ?? ''),
                    !empty($m['public_url']) ? (string) $m['public_url'] : null,
                    !empty($m['enabled']) ? 1 : 0,
                    'idle',
                ]);
                $created++;
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'warnings' => $warnings,
        ];
    }
}
