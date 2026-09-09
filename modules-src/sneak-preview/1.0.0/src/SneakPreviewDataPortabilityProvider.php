<?php

declare(strict_types=1);

namespace ModulNest\SneakPreview;

use Modulon\Core\Modules\DataPortability\DataPortabilityArchiveReader;
use Modulon\Core\Modules\DataPortability\DataPortabilityFileCollector;
use Modulon\Core\Modules\DataPortability\DataPortabilityProviderInterface;
use PDO;
use Throwable;

final class SneakPreviewDataPortabilityProvider implements DataPortabilityProviderInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $basePath,
    ) {
    }

    public function key(): string
    {
        return 'sneak';
    }

    public function label(): string
    {
        return 'Sneak Preview';
    }

    public function routePrefix(): string
    {
        return '/sneak-preview';
    }

    public function description(): string
    {
        return 'Sneak-Preview-Einträge, Anzeige-Einstellungen und lokal gespeicherte Poster.';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function hasFiles(): bool
    {
        return true;
    }

    public function sensitivityNote(): string
    {
        return 'Sneak-Preview-Export enthält öffentliche Filmdaten und lokale Posterdateien.';
    }

    public function supportsReplaceImport(): bool
    {
        return true;
    }

    public function scopes(): array
    {
        return ['admin'];
    }

    public function export(int $userId, DataPortabilityFileCollector $files): array
    {
        $entries = $this->fetchAll('SELECT * FROM sneak_preview_entries ORDER BY sneak_date DESC, id DESC');
        $settings = $this->fetchAll('SELECT `key` AS setting_key, `value` AS setting_value FROM sneak_preview_settings ORDER BY `key`');

        foreach ($entries as &$entry) {
            $entry['stable_key'] = $this->stableKey($entry);
            $poster = trim((string) ($entry['poster_path'] ?? ''));
            if ($poster !== '') {
                $absolute = $this->publicPath($poster);
                if ($absolute !== null) {
                    $stored = $files->addImage($absolute, 'posters/' . basename($poster));
                    if ($stored !== null) {
                        $entry['poster_export_file'] = $stored;
                    }
                }
            }
            unset($entry['id']);
        }
        unset($entry);

        return [
            'files' => [
                'entries.json' => [
                    'schema_version' => $this->schemaVersion(),
                    'entries' => $entries,
                    'settings' => $settings,
                ],
            ],
            'counts' => [
                'entries' => count($entries),
                'settings' => count($settings),
                'files' => $files->count(),
            ],
            'warnings' => [],
        ];
    }

    public function previewImport(array $payload, array $manifestModule, DataPortabilityArchiveReader $archive, int $targetUserId): array
    {
        $data = is_array($payload['entries'] ?? null) ? $payload['entries'] : [];

        return [
            'counts' => [
                'entries' => count($data['entries'] ?? []),
                'settings' => count($data['settings'] ?? []),
                'files' => (int) ($manifestModule['file_count'] ?? 0),
            ],
            'warnings' => ['Sneak Preview ist adminweit. Import ordnet Daten keinem einzelnen Benutzer zu. Bestehende Einträge werden per TMDB-ID oder Datum/Titel/Ort aktualisiert.'],
            'can_import' => true,
        ];
    }

    public function import(array $payload, array $manifestModule, DataPortabilityArchiveReader $archive, int $targetUserId, string $importMode = 'merge'): array
    {
        $data = is_array($payload['entries'] ?? null) ? $payload['entries'] : [];
        $entriesCreated = 0;
        $entriesUpdated = 0;
        $settingsUpdated = 0;
        $filesImported = 0;
        $skipped = 0;
        $replaced = [];

        $this->pdo->beginTransaction();
        try {
            if ($importMode === 'replace') {
                $replaced = $this->clearTargetData();
            }

            foreach (($data['settings'] ?? []) as $setting) {
                if (!is_array($setting) || !isset($setting['setting_key'])) {
                    $skipped++;
                    continue;
                }
                $this->upsertSetting((string) $setting['setting_key'], (string) ($setting['setting_value'] ?? ''));
                $settingsUpdated++;
            }

            foreach (($data['entries'] ?? []) as $entry) {
                if (!is_array($entry)) {
                    $skipped++;
                    continue;
                }
                $posterPath = $this->importPoster($entry, $archive) ?? $this->nullable($entry['poster_path'] ?? null);
                $existing = $this->findExistingEntry($entry);
                $row = [
                    'sneak_date' => (string) ($entry['sneak_date'] ?? ''),
                    'title' => (string) ($entry['title'] ?? ''),
                    'location' => (string) ($entry['location'] ?? ''),
                    'release_date_de' => $this->nullable($entry['release_date_de'] ?? null),
                    'poster_path' => $posterPath,
                    'poster_tmdb_path' => $this->nullable($entry['poster_tmdb_path'] ?? null),
                    'tmdb_id' => $this->nullable($entry['tmdb_id'] ?? null),
                    'overview' => $this->nullable($entry['overview'] ?? null),
                    'genres' => $this->nullable($entry['genres'] ?? null),
                    'runtime' => $this->nullable($entry['runtime'] ?? null),
                    'certification' => $this->nullable($entry['certification'] ?? null),
                    'original_language' => $this->nullable($entry['original_language'] ?? null),
                    'production_countries' => $this->nullable($entry['production_countries'] ?? null),
                    'vote_average' => $this->nullable($entry['vote_average'] ?? null),
                    'trailer_key' => $this->nullable($entry['trailer_key'] ?? null),
                    'updated_by' => $targetUserId,
                ];

                if ($existing) {
                    $row['id'] = (int) $existing['id'];
                    $this->updateEntry($row);
                    $entriesUpdated++;
                } else {
                    $row['created_by'] = $targetUserId;
                    $this->insertEntry($row);
                    $entriesCreated++;
                }
                if ($posterPath !== null && isset($entry['poster_export_file'])) {
                    $filesImported++;
                }
            }

            $this->pdo->commit();
        } catch (Throwable $throwable) {
            $this->pdo->rollBack();
            throw $throwable;
        }

        return [
            'created' => $entriesCreated,
            'updated' => $entriesUpdated + $settingsUpdated,
            'skipped' => $skipped,
            'import_mode' => $importMode,
            'replaced' => $replaced,
            'details' => [
                'entries_created' => $entriesCreated,
                'entries_updated' => $entriesUpdated,
                'settings_updated' => $settingsUpdated,
                'files_imported' => $filesImported,
            ],
            'summary' => ($importMode === 'replace'
                    ? 'ersetzt, gelöscht: Einträge ' . (int) ($replaced['entries'] ?? 0)
                        . ', Einstellungen ' . (int) ($replaced['settings'] ?? 0)
                        . ', Posterdateien ' . (int) ($replaced['poster_files'] ?? 0)
                        . '; '
                    : '')
                . 'Einträge neu ' . $entriesCreated
                . ', Einträge aktualisiert ' . $entriesUpdated
                . ', Einstellungen aktualisiert ' . $settingsUpdated
                . ', Dateien importiert ' . $filesImported
                . ', übersprungen ' . $skipped,
            'warnings' => [],
        ];
    }

    /**
     * @return array{entries:int,settings:int,poster_files:int}
     */
    private function clearTargetData(): array
    {
        $entries = (int) $this->pdo->query('SELECT COUNT(*) FROM sneak_preview_entries')->fetchColumn();
        $settings = (int) $this->pdo->query('SELECT COUNT(*) FROM sneak_preview_settings')->fetchColumn();
        $posterFiles = $this->clearPosterFiles();

        $this->pdo->exec('DELETE FROM sneak_preview_entries');
        $this->pdo->exec('DELETE FROM sneak_preview_settings');

        return ['entries' => $entries, 'settings' => $settings, 'poster_files' => $posterFiles];
    }

    private function clearPosterFiles(): int
    {
        $directory = rtrim($this->basePath, '/') . '/storage/modules/modulnest.sneak-preview/posters';
        $root = realpath($directory);
        if ($root === false || !is_dir($root)) {
            return 0;
        }

        $deleted = 0;
        foreach (new \DirectoryIterator($root) as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $extension = strtolower($file->getExtension());
            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                continue;
            }
            $path = $file->getPathname();
            if (str_starts_with((string) realpath($path), $root) && @unlink($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function importPoster(array $entry, DataPortabilityArchiveReader $archive): ?string
    {
        $file = trim((string) ($entry['poster_export_file'] ?? ''));
        if ($file === '') {
            return null;
        }

        $zipPath = 'modules/sneak/files/' . str_replace('\\', '/', $file);
        $filename = $archive->extractImage($zipPath, rtrim($this->basePath, '/') . '/storage/modules/modulnest.sneak-preview/posters', basename($file));

        return $filename !== null ? '/sneak-preview/posters/' . $filename : null;
    }

    private function findExistingEntry(array $entry): ?array
    {
        $tmdbId = (int) ($entry['tmdb_id'] ?? 0);
        if ($tmdbId > 0) {
            $existing = $this->findOne('SELECT id FROM sneak_preview_entries WHERE tmdb_id = :tmdb_id LIMIT 1', ['tmdb_id' => $tmdbId]);
            if ($existing) {
                return $existing;
            }
        }

        return $this->findOne(
            'SELECT id FROM sneak_preview_entries WHERE sneak_date = :date AND title = :title AND location = :location LIMIT 1',
            [
                'date' => (string) ($entry['sneak_date'] ?? ''),
                'title' => (string) ($entry['title'] ?? ''),
                'location' => (string) ($entry['location'] ?? ''),
            ]
        );
    }

    private function upsertSetting(string $key, string $value): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO sneak_preview_settings (`key`, `value`, created_at, updated_at)
             VALUES (:setting_key, :setting_value, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = VALUES(updated_at)'
        );
        $statement->execute([
            'setting_key' => $key,
            'setting_value' => $value,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
    }

    private function insertEntry(array $row): void
    {
        $row['created_at'] = $this->now();
        $row['updated_at'] = $this->now();
        $columns = array_keys($row);
        $statement = $this->pdo->prepare('INSERT INTO sneak_preview_entries (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')');
        $statement->execute($row);
    }

    private function updateEntry(array $row): void
    {
        $id = (int) $row['id'];
        unset($row['id']);
        $row['updated_at'] = $this->now();
        $assignments = [];
        foreach (array_keys($row) as $column) {
            $assignments[] = $column . ' = :' . $column;
        }
        $row['id'] = $id;
        $statement = $this->pdo->prepare('UPDATE sneak_preview_entries SET ' . implode(', ', $assignments) . ' WHERE id = :id');
        $statement->execute($row);
    }

    private function publicPath(string $webPath): ?string
    {
        $webPath = '/' . ltrim($webPath, '/');
        if (!str_starts_with($webPath, '/sneak-preview/posters/')) {
            return null;
        }
        $absolute = rtrim($this->basePath, '/') . '/storage/modules/modulnest.sneak-preview/posters/' . basename($webPath);
        $real = realpath($absolute);
        $root = realpath(rtrim($this->basePath, '/') . '/storage/modules/modulnest.sneak-preview/posters');

        return $real !== false && $root !== false && str_starts_with($real, $root) ? $real : null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchAll(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function findOne(string $sql, array $params): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function stableKey(array $entry): string
    {
        $tmdbId = (int) ($entry['tmdb_id'] ?? 0);
        if ($tmdbId > 0) {
            return 'tmdb:' . $tmdbId;
        }

        return 'entry:' . hash('sha256', implode('|', [
            (string) ($entry['sneak_date'] ?? ''),
            mb_strtolower(trim((string) ($entry['title'] ?? ''))),
            mb_strtolower(trim((string) ($entry['location'] ?? ''))),
        ]));
    }

    private function nullable(mixed $value): mixed
    {
        return $value === '' || $value === null ? null : $value;
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
