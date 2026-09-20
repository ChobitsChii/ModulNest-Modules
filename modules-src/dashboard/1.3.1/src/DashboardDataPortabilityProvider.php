<?php

declare(strict_types=1);

namespace ModulNest\Dashboard;

use Modulon\Core\Modules\DataPortability\DataPortabilityArchiveReader;
use Modulon\Core\Modules\DataPortability\DataPortabilityFileCollector;
use Modulon\Core\Modules\DataPortability\DataPortabilityProviderInterface;
use PDO;
use Throwable;

final class DashboardDataPortabilityProvider implements DataPortabilityProviderInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function key(): string
    {
        return 'dashboard';
    }

    public function label(): string
    {
        return 'Dashboard';
    }

    public function routePrefix(): string
    {
        return '/dashboard';
    }

    public function description(): string
    {
        return 'Persönliche Dashboard-Widgets, Links, Aufgaben und Notizen des aktuellen Benutzers.';
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
        return 'Dashboard-Exporte können persönliche Links, Aufgaben und Notizen enthalten.';
    }

    public function supportsReplaceImport(): bool
    {
        return true;
    }

    public function scopes(): array
    {
        return ['admin', 'user'];
    }

    public function export(int $userId, DataPortabilityFileCollector $files): array
    {
        $widgets = $this->fetchAll('SELECT * FROM dashboard_widgets WHERE user_id = :user_id ORDER BY sort_order, id', ['user_id' => $userId]);
        $widgetIds = array_column($widgets, 'id');
        $folders = $this->fetchByWidgetIds('dashboard_link_folders', $widgetIds);
        $links = $this->fetchByWidgetIds('dashboard_links', $widgetIds);
        $tasks = $this->fetchByWidgetIds('dashboard_tasks', $widgetIds);
        $notes = $this->fetchByWidgetIds('dashboard_notes', $widgetIds);

        return [
            'files' => [
                'data.json' => [
                    'schema_version' => $this->schemaVersion(),
                    'widgets' => $this->withRefs($widgets, 'widget'),
                    'folders' => $this->withWidgetRefs($folders, 'folder'),
                    'links' => $this->withWidgetRefs($links, 'link'),
                    'tasks' => $this->withWidgetRefs($tasks, 'task'),
                    'notes' => $this->withWidgetRefs($notes, 'note'),
                ],
            ],
            'counts' => [
                'widgets' => count($widgets),
                'folders' => count($folders),
                'links' => count($links),
                'tasks' => count($tasks),
                'notes' => count($notes),
                'archived_tasks' => count(array_filter($tasks, static fn (array $task): bool => ($task['archived_at'] ?? null) !== null && (string) ($task['archived_at'] ?? '') !== '')),
                'archived_notes' => count(array_filter($notes, static fn (array $note): bool => ($note['archived_at'] ?? null) !== null && (string) ($note['archived_at'] ?? '') !== '')),
            ],
            'warnings' => [],
        ];
    }

    public function previewImport(array $payload, array $manifestModule, DataPortabilityArchiveReader $archive, int $targetUserId): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        return [
            'counts' => [
                'widgets' => count($data['widgets'] ?? []),
                'folders' => count($data['folders'] ?? []),
                'links' => count($data['links'] ?? []),
                'tasks' => count($data['tasks'] ?? []),
                'notes' => count($data['notes'] ?? []),
            ],
            'warnings' => ['Standardmodus: Dashboard-Daten werden für den Zielbenutzer hinzugefügt; bestehende Widgets werden nicht gelöscht.'],
            'can_import' => true,
        ];
    }

    public function import(array $payload, array $manifestModule, DataPortabilityArchiveReader $archive, int $targetUserId, string $importMode = 'merge'): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $created = 0;
        $skipped = 0;
        $widgetMap = [];
        $folderMap = [];
        $replaced = [];

        $this->pdo->beginTransaction();
        try {
            if ($importMode === 'replace') {
                $replaced = $this->clearTargetData($targetUserId);
            }

            foreach (($data['widgets'] ?? []) as $widget) {
                if (!is_array($widget)) {
                    $skipped++;
                    continue;
                }
                $ref = (string) ($widget['_export_ref'] ?? '');
                $newId = $this->insert('dashboard_widgets', [
                    'user_id' => $targetUserId,
                    'widget_type' => (string) ($widget['widget_type'] ?? 'links'),
                    'title' => (string) ($widget['title'] ?? 'Importiertes Widget'),
                    'sort_order' => (int) ($widget['sort_order'] ?? 0),
                    'layout_width' => (string) ($widget['layout_width'] ?? 'half'),
                    'is_active' => (int) ($widget['is_active'] ?? 1),
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]);
                $widgetMap[$ref] = $newId;
                $created++;
            }

            foreach (($data['folders'] ?? []) as $folder) {
                if (!is_array($folder)) {
                    $skipped++;
                    continue;
                }
                $widgetId = $widgetMap[(string) ($folder['_widget_ref'] ?? '')] ?? null;
                if (!$widgetId) {
                    $skipped++;
                    continue;
                }
                $ref = (string) ($folder['_export_ref'] ?? '');
                $folderMap[$ref] = $this->insert('dashboard_link_folders', [
                    'widget_id' => $widgetId,
                    'name' => (string) ($folder['name'] ?? 'Importiert'),
                    'sort_order' => (int) ($folder['sort_order'] ?? 0),
                    'is_default' => (int) ($folder['is_default'] ?? 0),
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]);
                $created++;
            }

            foreach (($data['links'] ?? []) as $link) {
                if (!is_array($link)) {
                    $skipped++;
                    continue;
                }
                $widgetId = $widgetMap[(string) ($link['_widget_ref'] ?? '')] ?? null;
                if (!$widgetId) {
                    $skipped++;
                    continue;
                }
                $this->insert('dashboard_links', [
                    'widget_id' => $widgetId,
                    'folder_id' => $folderMap[(string) ($link['_folder_ref'] ?? '')] ?? null,
                    'title' => (string) ($link['title'] ?? ''),
                    'url' => (string) ($link['url'] ?? ''),
                    'sort_order' => (int) ($link['sort_order'] ?? 0),
                    'is_active' => (int) ($link['is_active'] ?? 1),
                    'favicon_url' => $this->nullable($link['favicon_url'] ?? null),
                    'favicon_host' => $this->nullable($link['favicon_host'] ?? null),
                    'favicon_last_checked_at' => $this->nullable($link['favicon_last_checked_at'] ?? null),
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]);
                $created++;
            }

            foreach (($data['tasks'] ?? []) as $task) {
                $widgetId = is_array($task) ? ($widgetMap[(string) ($task['_widget_ref'] ?? '')] ?? null) : null;
                if (!is_array($task) || !$widgetId) {
                    $skipped++;
                    continue;
                }
                $this->insert('dashboard_tasks', [
                    'widget_id' => $widgetId,
                    'title' => (string) ($task['title'] ?? ''),
                    'details' => (string) ($task['details'] ?? ''),
                    'link_url' => $this->nullable($task['link_url'] ?? null),
                    'priority' => (string) ($task['priority'] ?? 'normal'),
                    'due_at' => $this->nullable($task['due_at'] ?? null),
                    'is_active' => (int) ($task['is_active'] ?? 1),
                    'is_done' => (int) ($task['is_done'] ?? 0),
                    'done_at' => $this->nullable($task['done_at'] ?? null),
                    'archived_at' => $this->nullable($task['archived_at'] ?? null),
                    'repeat_type' => $this->nullable($task['repeat_type'] ?? null),
                    'repeat_time' => $this->nullable($task['repeat_time'] ?? null),
                    'repeat_weekday' => $this->nullable($task['repeat_weekday'] ?? null),
                    'repeat_month_mode' => (string) ($task['repeat_month_mode'] ?? 'day'),
                    'repeat_month_day' => $this->nullable($task['repeat_month_day'] ?? null),
                    'repeat_month_ordinal' => $this->nullable($task['repeat_month_ordinal'] ?? null),
                    'repeat_month_weekday' => $this->nullable($task['repeat_month_weekday'] ?? null),
                    'sort_order' => (int) ($task['sort_order'] ?? 0),
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]);
                $created++;
            }

            foreach (($data['notes'] ?? []) as $note) {
                $widgetId = is_array($note) ? ($widgetMap[(string) ($note['_widget_ref'] ?? '')] ?? null) : null;
                if (!is_array($note) || !$widgetId) {
                    $skipped++;
                    continue;
                }
                $this->insert('dashboard_notes', [
                    'widget_id' => $widgetId,
                    'title' => (string) ($note['title'] ?? ''),
                    'content' => (string) ($note['content'] ?? ''),
                    'textarea_height' => $this->nullable($note['textarea_height'] ?? null),
                    'sort_order' => (int) ($note['sort_order'] ?? 0),
                    'is_pinned' => (int) ($note['is_pinned'] ?? 0),
                    'is_archived' => (int) ($note['is_archived'] ?? 0),
                    'archived_at' => $this->nullable($note['archived_at'] ?? null),
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]);
                $created++;
            }
            $this->pdo->commit();
        } catch (Throwable $throwable) {
            $this->pdo->rollBack();
            throw $throwable;
        }

        $summary = $importMode === 'replace'
            ? 'ersetzt, gelöscht: Widgets ' . (int) ($replaced['widgets'] ?? 0)
                . ', Ordner ' . (int) ($replaced['folders'] ?? 0)
                . ', Links ' . (int) ($replaced['links'] ?? 0)
                . ', Aufgaben ' . (int) ($replaced['tasks'] ?? 0)
                . ', Notizen ' . (int) ($replaced['notes'] ?? 0)
                . '; neu importiert ' . $created . ', übersprungen ' . $skipped
            : 'neu importiert ' . $created . ', übersprungen ' . $skipped;

        return [
            'created' => $created,
            'updated' => 0,
            'skipped' => $skipped,
            'replaced' => $replaced,
            'summary' => $summary,
            'warnings' => [],
        ];
    }

    /**
     * @return array{widgets:int,folders:int,links:int,tasks:int,notes:int}
     */
    private function clearTargetData(int $targetUserId): array
    {
        $widgetIds = array_map('intval', array_column(
            $this->fetchAll('SELECT id FROM dashboard_widgets WHERE user_id = :user_id', ['user_id' => $targetUserId]),
            'id'
        ));
        $counts = [
            'widgets' => count($widgetIds),
            'folders' => 0,
            'links' => 0,
            'tasks' => 0,
            'notes' => 0,
        ];
        if ($widgetIds === []) {
            return $counts;
        }

        $counts['notes'] = $this->countByWidgetIds('dashboard_notes', $widgetIds);
        $counts['tasks'] = $this->countByWidgetIds('dashboard_tasks', $widgetIds);
        $counts['links'] = $this->countByWidgetIds('dashboard_links', $widgetIds);
        $counts['folders'] = $this->countByWidgetIds('dashboard_link_folders', $widgetIds);

        $this->deleteByWidgetIds('dashboard_notes', $widgetIds);
        $this->deleteByWidgetIds('dashboard_tasks', $widgetIds);
        $this->deleteByWidgetIds('dashboard_links', $widgetIds);
        $this->deleteByWidgetIds('dashboard_link_folders', $widgetIds);
        $this->deleteByIds('dashboard_widgets', $widgetIds);

        return $counts;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    private function fetchAll(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<int,mixed> $widgetIds
     * @return array<int,array<string,mixed>>
     */
    private function fetchByWidgetIds(string $table, array $widgetIds): array
    {
        if ($widgetIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($widgetIds), '?'));

        return $this->fetchAll('SELECT * FROM ' . $table . ' WHERE widget_id IN (' . $placeholders . ') ORDER BY widget_id, sort_order, id', array_values($widgetIds));
    }

    /**
     * @param array<int,int> $widgetIds
     */
    private function countByWidgetIds(string $table, array $widgetIds): int
    {
        if ($widgetIds === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($widgetIds), '?'));
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE widget_id IN (' . $placeholders . ')');
        $statement->execute(array_values($widgetIds));

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array<int,int> $widgetIds
     */
    private function deleteByWidgetIds(string $table, array $widgetIds): void
    {
        if ($widgetIds === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($widgetIds), '?'));
        $statement = $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE widget_id IN (' . $placeholders . ')');
        $statement->execute(array_values($widgetIds));
    }

    /**
     * @param array<int,int> $ids
     */
    private function deleteByIds(string $table, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE id IN (' . $placeholders . ')');
        $statement->execute(array_values($ids));
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function withRefs(array $rows, string $prefix): array
    {
        foreach ($rows as &$row) {
            $row['_export_ref'] = $prefix . '-' . (string) ($row['id'] ?? '');
            unset($row['id'], $row['user_id']);
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function withWidgetRefs(array $rows, string $prefix): array
    {
        foreach ($rows as &$row) {
            $row['_export_ref'] = $prefix . '-' . (string) ($row['id'] ?? '');
            $row['_widget_ref'] = 'widget-' . (string) ($row['widget_id'] ?? '');
            if (isset($row['folder_id'])) {
                $row['_folder_ref'] = $row['folder_id'] !== null ? 'folder-' . (string) $row['folder_id'] : null;
            }
            unset($row['id'], $row['widget_id'], $row['folder_id']);
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    private function nullable(mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }

        return $value;
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
