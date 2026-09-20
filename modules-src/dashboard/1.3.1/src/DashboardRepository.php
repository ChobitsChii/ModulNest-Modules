<?php

declare(strict_types=1);

namespace ModulNest\Dashboard;

use PDO;

final class DashboardRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listWidgetsForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, user_id, widget_type, title, sort_order, layout_width, is_active, created_at, updated_at
             FROM dashboard_widgets
             WHERE user_id = :user_id
             ORDER BY sort_order ASC, id ASC"
        );
        $statement->execute(['user_id' => $userId]);
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listActiveWidgetsForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, user_id, widget_type, title, sort_order, layout_width, is_active, created_at, updated_at
             FROM dashboard_widgets
             WHERE user_id = :user_id
               AND is_active = 1
             ORDER BY sort_order ASC, id ASC"
        );
        $statement->execute(['user_id' => $userId]);
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, int>
     */
    public function countWidgetsByType(int $userId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT widget_type, COUNT(*) AS cnt
             FROM dashboard_widgets
             WHERE user_id = :user_id
             GROUP BY widget_type"
        );
        $statement->execute(['user_id' => $userId]);
        $rows = $statement->fetchAll();

        $counts = [
            'links' => 0,
            'tasks' => 0,
            'notes' => 0,
        ];

        if (!is_array($rows)) {
            return $counts;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $type = strtolower((string) ($row['widget_type'] ?? ''));
            if (!array_key_exists($type, $counts)) {
                continue;
            }

            $counts[$type] = (int) ($row['cnt'] ?? 0);
        }

        return $counts;
    }

    public function ensureDefaultLinksWidgetForUser(int $userId): int
    {
        $statement = $this->pdo->prepare(
            "SELECT id
             FROM dashboard_widgets
             WHERE user_id = :user_id AND widget_type = 'links'
             ORDER BY sort_order ASC, id ASC
             LIMIT 1"
        );
        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch();
        if (is_array($row) && isset($row['id'])) {
            return (int) $row['id'];
        }

        $insert = $this->pdo->prepare(
            "INSERT INTO dashboard_widgets (user_id, widget_type, title, sort_order, layout_width, is_active)
             VALUES (:user_id, 'links', :title, 0, 6, 1)"
        );
        $insert->execute([
            'user_id' => $userId,
            'title' => 'Meine Links',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function ensureDefaultWidgetForUser(int $userId, string $widgetType, string $title, int $layoutWidth = 6): int
    {
        $widgetType = strtolower(trim($widgetType));
        if (!in_array($widgetType, ['links', 'tasks', 'notes'], true)) {
            $widgetType = 'links';
        }

        if (!in_array($layoutWidth, [4, 6, 12], true)) {
            $layoutWidth = 6;
        }

        $statement = $this->pdo->prepare(
            "SELECT id
             FROM dashboard_widgets
             WHERE user_id = :user_id AND widget_type = :widget_type
             ORDER BY sort_order ASC, id ASC
             LIMIT 1"
        );
        $statement->execute([
            'user_id' => $userId,
            'widget_type' => $widgetType,
        ]);
        $row = $statement->fetch();
        if (is_array($row) && isset($row['id'])) {
            return (int) $row['id'];
        }

        $sortStatement = $this->pdo->prepare(
            "SELECT COALESCE(MAX(sort_order), 0) AS max_sort
             FROM dashboard_widgets
             WHERE user_id = :user_id"
        );
        $sortStatement->execute(['user_id' => $userId]);
        $sortRow = $sortStatement->fetch();
        $nextSort = (is_array($sortRow) ? (int) ($sortRow['max_sort'] ?? 0) : 0) + 10;

        $insert = $this->pdo->prepare(
            "INSERT INTO dashboard_widgets (user_id, widget_type, title, sort_order, layout_width, is_active)
             VALUES (:user_id, :widget_type, :title, :sort_order, :layout_width, 1)"
        );
        $insert->execute([
            'user_id' => $userId,
            'widget_type' => $widgetType,
            'title' => $title,
            'sort_order' => $nextSort,
            'layout_width' => $layoutWidth,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function createWidget(int $userId, string $widgetType, string $title, int $layoutWidth = 6): int
    {
        $widgetType = strtolower(trim($widgetType));
        if (!in_array($widgetType, ['links', 'tasks', 'notes'], true)) {
            $widgetType = 'links';
        }
        if (!in_array($layoutWidth, [6, 12], true)) {
            $layoutWidth = 6;
        }

        $sortStatement = $this->pdo->prepare(
            "SELECT COALESCE(MAX(sort_order), 0) AS max_sort
             FROM dashboard_widgets
             WHERE user_id = :user_id"
        );
        $sortStatement->execute(['user_id' => $userId]);
        $sortRow = $sortStatement->fetch();
        $nextSort = (is_array($sortRow) ? (int) ($sortRow['max_sort'] ?? 0) : 0) + 10;

        $insert = $this->pdo->prepare(
            "INSERT INTO dashboard_widgets (user_id, widget_type, title, sort_order, layout_width, is_active)
             VALUES (:user_id, :widget_type, :title, :sort_order, :layout_width, 1)"
        );
        $insert->execute([
            'user_id' => $userId,
            'widget_type' => $widgetType,
            'title' => $title,
            'sort_order' => $nextSort,
            'layout_width' => $layoutWidth,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findWidgetForUser(int $widgetId, int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, user_id, widget_type, title, sort_order, layout_width, is_active, created_at, updated_at
             FROM dashboard_widgets
             WHERE id = :id AND user_id = :user_id
             LIMIT 1"
        );
        $statement->execute([
            'id' => $widgetId,
            'user_id' => $userId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function updateWidgetForUser(int $widgetId, int $userId, ?string $title, ?int $layoutWidth, ?bool $isActive): bool
    {
        $sets = [];
        $params = [
            'id' => $widgetId,
            'user_id' => $userId,
        ];

        if ($title !== null) {
            $sets[] = 'title = :title';
            $params['title'] = $title;
        }
        if ($layoutWidth !== null) {
            $sets[] = 'layout_width = :layout_width';
            $params['layout_width'] = $layoutWidth;
        }
        if ($isActive !== null) {
            $sets[] = 'is_active = :is_active';
            $params['is_active'] = $isActive ? 1 : 0;
        }

        if ($sets === []) {
            return true;
        }

        $statement = $this->pdo->prepare(
            'UPDATE dashboard_widgets
             SET ' . implode(', ', $sets) . '
             WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute($params);

        return $statement->rowCount() > 0;
    }

    public function deleteWidgetForUser(int $widgetId, int $userId): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM dashboard_widgets WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([
            'id' => $widgetId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * @param array<int, int> $widgetIds
     */
    public function reorderWidgetsForUser(int $userId, array $widgetIds): void
    {
        $widgetIds = array_values(array_unique(array_filter(array_map('intval', $widgetIds), static fn (int $id): bool => $id > 0)));
        if ($widgetIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($widgetIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT id
             FROM dashboard_widgets
             WHERE user_id = ?
               AND id IN ($placeholders)"
        );
        $statement->execute(array_merge([$userId], $widgetIds));
        $ownedIds = array_map('intval', array_column($statement->fetchAll() ?: [], 'id'));
        $ownedLookup = array_fill_keys($ownedIds, true);

        $update = $this->pdo->prepare(
            'UPDATE dashboard_widgets
             SET sort_order = :sort_order
             WHERE id = :id AND user_id = :user_id'
        );
        $sort = 10;
        foreach ($widgetIds as $widgetId) {
            if (!isset($ownedLookup[$widgetId])) {
                continue;
            }
            $update->execute([
                'sort_order' => $sort,
                'id' => $widgetId,
                'user_id' => $userId,
            ]);
            $sort += 10;
        }
    }

    public function ensureDefaultFolderForWidget(int $widgetId): int
    {
        $statement = $this->pdo->prepare(
            "SELECT id
             FROM dashboard_link_folders
             WHERE widget_id = :widget_id
             ORDER BY is_default DESC, sort_order ASC, id ASC
             LIMIT 1"
        );
        $statement->execute(['widget_id' => $widgetId]);
        $row = $statement->fetch();
        if (is_array($row) && isset($row['id'])) {
            return (int) $row['id'];
        }

        $insert = $this->pdo->prepare(
            "INSERT INTO dashboard_link_folders (widget_id, name, sort_order, is_default)
             VALUES (:widget_id, :name, 0, 1)"
        );
        $insert->execute([
            'widget_id' => $widgetId,
            'name' => 'Allgemein',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function createFolder(int $widgetId, string $name): int
    {
        $sortStatement = $this->pdo->prepare(
            "SELECT COALESCE(MAX(sort_order), 0) AS max_sort
             FROM dashboard_link_folders
             WHERE widget_id = :widget_id"
        );
        $sortStatement->execute(['widget_id' => $widgetId]);
        $sortRow = $sortStatement->fetch();
        $nextSort = (is_array($sortRow) ? (int) ($sortRow['max_sort'] ?? 0) : 0) + 10;

        $insert = $this->pdo->prepare(
            "INSERT INTO dashboard_link_folders (widget_id, name, sort_order, is_default)
             VALUES (:widget_id, :name, :sort_order, 0)"
        );
        $insert->execute([
            'widget_id' => $widgetId,
            'name' => $name,
            'sort_order' => $nextSort,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function folderBelongsToWidget(int $folderId, int $widgetId): bool
    {
        if ($folderId <= 0 || $widgetId <= 0) {
            return false;
        }

        $statement = $this->pdo->prepare(
            "SELECT id
             FROM dashboard_link_folders
             WHERE id = :folder_id
               AND widget_id = :widget_id
             LIMIT 1"
        );
        $statement->execute([
            'folder_id' => $folderId,
            'widget_id' => $widgetId,
        ]);

        return $statement->fetch() !== false;
    }

    /**
     * @param array<int, int> $widgetIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function listFoldersByWidgetIds(array $widgetIds): array
    {
        $widgetIds = array_values(array_filter(array_map('intval', $widgetIds), static fn (int $id): bool => $id > 0));
        if ($widgetIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($widgetIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT id, widget_id, name, sort_order, is_default
             FROM dashboard_link_folders
             WHERE widget_id IN ($placeholders)
             ORDER BY widget_id ASC, sort_order ASC, id ASC"
        );
        $statement->execute($widgetIds);
        $rows = $statement->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $grouped = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $widgetId = (int) ($row['widget_id'] ?? 0);
            if ($widgetId <= 0) {
                continue;
            }

            $grouped[$widgetId][] = $row;
        }

        return $grouped;
    }

    /**
     * @param array<int, int> $widgetIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function listLinksByWidgetIds(array $widgetIds): array
    {
        $widgetIds = array_values(array_filter(array_map('intval', $widgetIds), static fn (int $id): bool => $id > 0));
        if ($widgetIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($widgetIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT l.id, l.widget_id, l.folder_id, l.title, l.url, l.sort_order, l.is_active, l.favicon_url, f.name AS folder_name
             FROM dashboard_links l
             LEFT JOIN dashboard_link_folders f ON f.id = l.folder_id
             WHERE l.widget_id IN ($placeholders)
             ORDER BY l.widget_id ASC, COALESCE(f.sort_order, 0) ASC, l.sort_order ASC, l.id ASC"
        );
        $statement->execute($widgetIds);
        $rows = $statement->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $grouped = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $widgetId = (int) ($row['widget_id'] ?? 0);
            if ($widgetId <= 0) {
                continue;
            }

            $grouped[$widgetId][] = $row;
        }

        return $grouped;
    }

    public function createLink(
        int $widgetId,
        ?int $folderId,
        string $title,
        string $url,
        ?string $faviconUrl,
        ?string $faviconHost
    ): int {
        $sortStatement = $this->pdo->prepare(
            "SELECT COALESCE(MAX(sort_order), 0) AS max_sort
             FROM dashboard_links
             WHERE widget_id = :widget_id"
        );
        $sortStatement->execute(['widget_id' => $widgetId]);
        $sortRow = $sortStatement->fetch();
        $nextSort = (is_array($sortRow) ? (int) ($sortRow['max_sort'] ?? 0) : 0) + 10;

        $insert = $this->pdo->prepare(
            "INSERT INTO dashboard_links (widget_id, folder_id, title, url, sort_order, is_active, favicon_url, favicon_host, favicon_last_checked_at)
             VALUES (:widget_id, :folder_id, :title, :url, :sort_order, 1, :favicon_url, :favicon_host, CURRENT_TIMESTAMP)"
        );
        $insert->execute([
            'widget_id' => $widgetId,
            'folder_id' => $folderId,
            'title' => $title,
            'url' => $url,
            'sort_order' => $nextSort,
            'favicon_url' => $faviconUrl,
            'favicon_host' => $faviconHost,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findLinkForUser(int $linkId, int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT l.id, l.widget_id, l.folder_id, l.title, l.url, l.sort_order, l.is_active, l.favicon_url, l.favicon_host
             FROM dashboard_links l
             INNER JOIN dashboard_widgets w ON w.id = l.widget_id
             WHERE l.id = :link_id
               AND w.user_id = :user_id
             LIMIT 1"
        );
        $statement->execute([
            'link_id' => $linkId,
            'user_id' => $userId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function updateLink(
        int $linkId,
        ?int $folderId,
        string $title,
        string $url,
        ?string $faviconUrl,
        ?string $faviconHost
    ): void {
        $statement = $this->pdo->prepare(
            "UPDATE dashboard_links
             SET folder_id = :folder_id,
                 title = :title,
                 url = :url,
                 favicon_url = :favicon_url,
                 favicon_host = :favicon_host,
                 favicon_last_checked_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $statement->execute([
            'id' => $linkId,
            'folder_id' => $folderId,
            'title' => $title,
            'url' => $url,
            'favicon_url' => $faviconUrl,
            'favicon_host' => $faviconHost,
        ]);
    }

    public function deleteLink(int $linkId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM dashboard_links WHERE id = :id');
        $statement->execute(['id' => $linkId]);
    }

    /**
     * @param array<int, int> $widgetIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function listTasksByWidgetIds(array $widgetIds): array
    {
        return $this->listTasksByWidgetIdsAndArchiveState($widgetIds, false);
    }

    /**
     * @param array<int, int> $widgetIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function listArchivedTasksByWidgetIds(array $widgetIds): array
    {
        return $this->listTasksByWidgetIdsAndArchiveState($widgetIds, true);
    }

    /**
     * @param array<int, int> $widgetIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function listTasksByWidgetIdsAndArchiveState(array $widgetIds, bool $archived): array
    {
        $widgetIds = array_values(array_filter(array_map('intval', $widgetIds), static fn (int $id): bool => $id > 0));
        if ($widgetIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($widgetIds), '?'));
        $archiveCondition = $archived ? 'archived_at IS NOT NULL' : 'archived_at IS NULL';
        $statement = $this->pdo->prepare(
            "SELECT id, widget_id, title, details, link_url, priority, due_at, is_active, is_done, done_at, sort_order,
                    repeat_type, repeat_time, repeat_weekday, repeat_month_mode, repeat_month_day, repeat_month_ordinal, repeat_month_weekday,
                    archived_at
             FROM dashboard_tasks
             WHERE widget_id IN ($placeholders)
               AND {$archiveCondition}
             ORDER BY widget_id ASC, is_done ASC, sort_order ASC, id ASC"
        );
        $statement->execute($widgetIds);
        $rows = $statement->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $grouped = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $widgetId = (int) ($row['widget_id'] ?? 0);
            if ($widgetId <= 0) {
                continue;
            }

            $grouped[$widgetId][] = $row;
        }

        return $grouped;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createTask(int $widgetId, array $data): int
    {
        $sortStatement = $this->pdo->prepare(
            "SELECT COALESCE(MAX(sort_order), 0) AS max_sort
             FROM dashboard_tasks
             WHERE widget_id = :widget_id"
        );
        $sortStatement->execute(['widget_id' => $widgetId]);
        $sortRow = $sortStatement->fetch();
        $nextSort = (is_array($sortRow) ? (int) ($sortRow['max_sort'] ?? 0) : 0) + 10;

        $insert = $this->pdo->prepare(
            "INSERT INTO dashboard_tasks (
                widget_id, title, details, link_url, priority, due_at, is_active, is_done, done_at, sort_order,
                repeat_type, repeat_time, repeat_weekday, repeat_month_mode, repeat_month_day, repeat_month_ordinal, repeat_month_weekday
             ) VALUES (
                :widget_id, :title, :details, :link_url, :priority, :due_at, :is_active, 0, NULL, :sort_order,
                :repeat_type, :repeat_time, :repeat_weekday, :repeat_month_mode, :repeat_month_day, :repeat_month_ordinal, :repeat_month_weekday
             )"
        );
        $insert->execute([
            'widget_id' => $widgetId,
            'title' => $data['title'],
            'details' => $data['details'],
            'link_url' => $data['link_url'],
            'priority' => $data['priority'],
            'due_at' => $data['due_at'],
            'is_active' => $data['is_active'],
            'sort_order' => $nextSort,
            'repeat_type' => $data['repeat_type'],
            'repeat_time' => $data['repeat_time'],
            'repeat_weekday' => $data['repeat_weekday'],
            'repeat_month_mode' => $data['repeat_month_mode'],
            'repeat_month_day' => $data['repeat_month_day'],
            'repeat_month_ordinal' => $data['repeat_month_ordinal'],
            'repeat_month_weekday' => $data['repeat_month_weekday'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findTaskForUser(int $taskId, int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT t.id, t.widget_id, t.title, t.details, t.link_url, t.priority, t.due_at, t.is_active, t.is_done, t.done_at, t.sort_order,
                    t.repeat_type, t.repeat_time, t.repeat_weekday, t.repeat_month_mode, t.repeat_month_day, t.repeat_month_ordinal, t.repeat_month_weekday,
                    t.archived_at
             FROM dashboard_tasks t
             INNER JOIN dashboard_widgets w ON w.id = t.widget_id
             WHERE t.id = :task_id
               AND w.user_id = :user_id
             LIMIT 1"
        );
        $statement->execute([
            'task_id' => $taskId,
            'user_id' => $userId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function setTaskDone(int $taskId, bool $isDone): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE dashboard_tasks
             SET is_done = :is_done,
                 done_at = :done_at,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $statement->execute([
            'id' => $taskId,
            'is_done' => $isDone ? 1 : 0,
            'done_at' => $isDone ? date('Y-m-d H:i:s') : null,
        ]);
    }

    public function reopenTask(int $taskId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE dashboard_tasks
             SET is_done = 0,
                 done_at = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $statement->execute(['id' => $taskId]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateTask(int $taskId, array $data): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE dashboard_tasks
             SET title = :title,
                 details = :details,
                 link_url = :link_url,
                 is_active = :is_active,
                 repeat_type = :repeat_type,
                 repeat_time = :repeat_time,
                 repeat_weekday = :repeat_weekday,
                 repeat_month_mode = :repeat_month_mode,
                 repeat_month_day = :repeat_month_day,
                 repeat_month_ordinal = :repeat_month_ordinal,
                 repeat_month_weekday = :repeat_month_weekday,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $statement->execute([
            'id' => $taskId,
            'title' => $data['title'],
            'details' => $data['details'],
            'link_url' => $data['link_url'],
            'is_active' => $data['is_active'],
            'repeat_type' => $data['repeat_type'],
            'repeat_time' => $data['repeat_time'],
            'repeat_weekday' => $data['repeat_weekday'],
            'repeat_month_mode' => $data['repeat_month_mode'],
            'repeat_month_day' => $data['repeat_month_day'],
            'repeat_month_ordinal' => $data['repeat_month_ordinal'],
            'repeat_month_weekday' => $data['repeat_month_weekday'],
        ]);
    }

    public function deleteTask(int $taskId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM dashboard_tasks WHERE id = :id');
        $statement->execute(['id' => $taskId]);
    }

    public function setTaskArchived(int $taskId, bool $archived): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE dashboard_tasks
             SET archived_at = :archived_at,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $statement->execute([
            'id' => $taskId,
            'archived_at' => $archived ? gmdate('Y-m-d H:i:s') : null,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listRecurringDoneTasksForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT t.id, t.repeat_type, t.repeat_time, t.repeat_weekday, t.repeat_month_mode, t.repeat_month_day, t.repeat_month_ordinal, t.repeat_month_weekday, t.done_at
             FROM dashboard_tasks t
             INNER JOIN dashboard_widgets w ON w.id = t.widget_id
             WHERE w.user_id = :user_id
               AND t.is_active = 1
               AND t.is_done = 1
               AND t.archived_at IS NULL
               AND t.repeat_type <> 'none'"
        );
        $statement->execute(['user_id' => $userId]);
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<int, int> $widgetIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function listNotesByWidgetIds(array $widgetIds): array
    {
        return $this->listNotesByWidgetIdsAndArchiveState($widgetIds, false);
    }

    /**
     * @param array<int, int> $widgetIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function listArchivedNotesByWidgetIds(array $widgetIds): array
    {
        return $this->listNotesByWidgetIdsAndArchiveState($widgetIds, true);
    }

    /**
     * @param array<int, int> $widgetIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function listNotesByWidgetIdsAndArchiveState(array $widgetIds, bool $archived): array
    {
        $widgetIds = array_values(array_filter(array_map('intval', $widgetIds), static fn (int $id): bool => $id > 0));
        if ($widgetIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($widgetIds), '?'));
        $archiveCondition = $archived ? 'archived_at IS NOT NULL' : 'archived_at IS NULL';
        $statement = $this->pdo->prepare(
            "SELECT id, widget_id, title, content, textarea_height, sort_order, is_pinned, is_archived, archived_at, created_at, updated_at
             FROM dashboard_notes
             WHERE widget_id IN ($placeholders)
               AND {$archiveCondition}
             ORDER BY widget_id ASC, is_pinned DESC, sort_order ASC, id ASC"
        );
        $statement->execute($widgetIds);
        $rows = $statement->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $grouped = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $widgetId = (int) ($row['widget_id'] ?? 0);
            if ($widgetId <= 0) {
                continue;
            }

            $grouped[$widgetId][] = $row;
        }

        return $grouped;
    }

    public function createNote(int $widgetId, ?string $title, string $content, ?int $textareaHeight = null): int
    {
        $sortStatement = $this->pdo->prepare(
            "SELECT COALESCE(MAX(sort_order), 0) AS max_sort
             FROM dashboard_notes
             WHERE widget_id = :widget_id"
        );
        $sortStatement->execute(['widget_id' => $widgetId]);
        $sortRow = $sortStatement->fetch();
        $nextSort = (is_array($sortRow) ? (int) ($sortRow['max_sort'] ?? 0) : 0) + 10;

        $insert = $this->pdo->prepare(
            "INSERT INTO dashboard_notes (widget_id, title, content, textarea_height, sort_order, is_pinned, is_archived, archived_at)
             VALUES (:widget_id, :title, :content, :textarea_height, :sort_order, 0, 0, NULL)"
        );
        $insert->execute([
            'widget_id' => $widgetId,
            'title' => $title,
            'content' => $content,
            'textarea_height' => $textareaHeight,
            'sort_order' => $nextSort,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findNoteForUser(int $noteId, int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT n.id, n.widget_id, n.title, n.content, n.textarea_height, n.sort_order, n.is_pinned, n.is_archived, n.archived_at
             FROM dashboard_notes n
             INNER JOIN dashboard_widgets w ON w.id = n.widget_id
             WHERE n.id = :note_id
               AND w.user_id = :user_id
             LIMIT 1"
        );
        $statement->execute([
            'note_id' => $noteId,
            'user_id' => $userId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function updateNote(int $noteId, ?string $title, string $content, ?int $textareaHeight = null): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE dashboard_notes
             SET title = :title,
                 content = :content,
                 textarea_height = :textarea_height,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $statement->execute([
            'id' => $noteId,
            'title' => $title,
            'content' => $content,
            'textarea_height' => $textareaHeight,
        ]);
    }

    public function deleteNote(int $noteId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM dashboard_notes WHERE id = :id');
        $statement->execute(['id' => $noteId]);
    }

    public function setNoteArchived(int $noteId, bool $archived): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE dashboard_notes
             SET is_archived = :is_archived,
                 archived_at = :archived_at,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $statement->execute([
            'id' => $noteId,
            'is_archived' => $archived ? 1 : 0,
            'archived_at' => $archived ? gmdate('Y-m-d H:i:s') : null,
        ]);
    }
}
