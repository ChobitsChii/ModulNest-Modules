<?php

declare(strict_types=1);

namespace ModulNest\Pages;

use Modulon\Core\Modules\PageLinkProviderInterface;
use PDO;

final class PagesRepository implements PageLinkProviderInterface
{
    public const VISIBILITIES = ['public', 'user', 'admin'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function ensureSchema(): void
    {
        $tableExists = (int) $this->pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'pages_entries'"
        )->fetchColumn() > 0;
        if (!$tableExists) {
            return;
        }

        $hasHeader = (int) $this->pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'pages_entries'
               AND COLUMN_NAME = 'show_in_header'"
        )->fetchColumn() > 0;
        if (!$hasHeader) {
            $this->pdo->exec('ALTER TABLE pages_entries ADD COLUMN show_in_header TINYINT(1) NOT NULL DEFAULT 0 AFTER menu_group');
        }

        $hasFooter = (int) $this->pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'pages_entries'
               AND COLUMN_NAME = 'show_in_footer'"
        )->fetchColumn() > 0;
        if (!$hasFooter) {
            $this->pdo->exec('ALTER TABLE pages_entries ADD COLUMN show_in_footer TINYINT(1) NOT NULL DEFAULT 0 AFTER show_in_header');
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, title, slug, content_markdown, visibility, menu_group, show_in_header, show_in_footer, is_active, sort_order, created_at, updated_at
             FROM pages_entries
             ORDER BY sort_order ASC, id ASC'
        );
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, title, slug, content_markdown, visibility, menu_group, show_in_header, show_in_footer, is_active, sort_order, created_at, updated_at
             FROM pages_entries
             WHERE slug = :slug AND is_active = 1
             LIMIT 1'
        );
        $statement->execute(['slug' => $slug]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPublicByGroup(string $menuGroup): array
    {
        $statement = $this->pdo->prepare(
            'SELECT title, slug
             FROM pages_entries
             WHERE is_active = 1
               AND visibility = :visibility
               AND menu_group = :menu_group
             ORDER BY sort_order ASC, id ASC'
        );
        $statement->execute([
            'visibility' => 'public',
            'menu_group' => $menuGroup,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, string>
     */
    public function listMenuGroups(): array
    {
        $statement = $this->pdo->query(
            "SELECT DISTINCT menu_group
             FROM pages_entries
             WHERE TRIM(menu_group) <> ''
             ORDER BY menu_group ASC"
        );
        $rows = $statement->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($value): string => trim((string) $value), $rows), static fn (string $value): bool => $value !== ''));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPublicHeaderPages(): array
    {
        $statement = $this->pdo->query(
            "SELECT title, slug, menu_group, sort_order
             FROM pages_entries
             WHERE is_active = 1
               AND visibility = 'public'
               AND show_in_header = 1
             ORDER BY
                CASE WHEN TRIM(menu_group) = '' THEN 0 ELSE 1 END ASC,
                menu_group ASC,
                sort_order ASC,
                id ASC"
        );
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPublicFooterPages(): array
    {
        $statement = $this->pdo->query(
            "SELECT title, slug
             FROM pages_entries
             WHERE is_active = 1
               AND visibility = 'public'
               AND show_in_footer = 1
             ORDER BY sort_order ASC, id ASC"
        );
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        if ($exceptId !== null && $exceptId > 0) {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*)
                 FROM pages_entries
                 WHERE slug = :slug
                   AND id <> :id'
            );
            $statement->execute([
                'slug' => $slug,
                'id' => $exceptId,
            ]);

            return (int) $statement->fetchColumn() > 0;
        }

        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM pages_entries WHERE slug = :slug');
        $statement->execute(['slug' => $slug]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * @param array{title:string,slug:string,content_markdown:string,visibility:string,menu_group:string,show_in_header:bool,show_in_footer:bool,is_active:bool} $data
     */
    public function create(array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO pages_entries
                (title, slug, content_markdown, visibility, menu_group, show_in_header, show_in_footer, is_active, sort_order)
             VALUES
                (:title, :slug, :content_markdown, :visibility, :menu_group, :show_in_header, :show_in_footer, :is_active, :sort_order)'
        );
        $statement->execute([
            'title' => $data['title'],
            'slug' => $data['slug'],
            'content_markdown' => $data['content_markdown'],
            'visibility' => $this->normalizeVisibility($data['visibility']),
            'menu_group' => trim($data['menu_group']),
            'show_in_header' => $data['show_in_header'] ? 1 : 0,
            'show_in_footer' => $data['show_in_footer'] ? 1 : 0,
            'is_active' => $data['is_active'] ? 1 : 0,
            'sort_order' => $this->nextSortOrder(),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array{title:string,slug:string,content_markdown:string,visibility:string,menu_group:string,show_in_header:bool,show_in_footer:bool,is_active:bool} $data
     */
    public function update(int $id, array $data): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE pages_entries
             SET title = :title,
                 slug = :slug,
                 content_markdown = :content_markdown,
                 visibility = :visibility,
                 menu_group = :menu_group,
                 show_in_header = :show_in_header,
                 show_in_footer = :show_in_footer,
                 is_active = :is_active,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $id,
            'title' => $data['title'],
            'slug' => $data['slug'],
            'content_markdown' => $data['content_markdown'],
            'visibility' => $this->normalizeVisibility($data['visibility']),
            'menu_group' => trim($data['menu_group']),
            'show_in_header' => $data['show_in_header'] ? 1 : 0,
            'show_in_footer' => $data['show_in_footer'] ? 1 : 0,
            'is_active' => $data['is_active'] ? 1 : 0,
        ]);
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM pages_entries WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE pages_entries
             SET is_active = :is_active,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $id,
            'is_active' => $active ? 1 : 0,
        ]);
    }

    public function move(int $id, string $direction): void
    {
        $current = $this->findById($id);
        if ($current === null) {
            return;
        }

        $operator = $direction === 'up' ? '<' : '>';
        $order = $direction === 'up' ? 'DESC' : 'ASC';
        $statement = $this->pdo->prepare(
            'SELECT id, sort_order
             FROM pages_entries
             WHERE sort_order ' . $operator . ' :sort_order
             ORDER BY sort_order ' . $order . ', id ' . $order . '
             LIMIT 1'
        );
        $statement->execute(['sort_order' => (int) ($current['sort_order'] ?? 0)]);
        $other = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($other)) {
            return;
        }

        $update = $this->pdo->prepare('UPDATE pages_entries SET sort_order = :sort_order WHERE id = :id');
        $this->pdo->beginTransaction();
        try {
            $update->execute([
                'id' => $id,
                'sort_order' => (int) ($other['sort_order'] ?? 0),
            ]);
            $update->execute([
                'id' => (int) ($other['id'] ?? 0),
                'sort_order' => (int) ($current['sort_order'] ?? 0),
            ]);
            $this->pdo->commit();
        } catch (\Throwable $throwable) {
            $this->pdo->rollBack();
            throw $throwable;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, title, slug, content_markdown, visibility, menu_group, show_in_header, show_in_footer, is_active, sort_order, created_at, updated_at
             FROM pages_entries
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function normalizeVisibility(string $visibility): string
    {
        $value = strtolower(trim($visibility));

        return in_array($value, self::VISIBILITIES, true) ? $value : 'public';
    }

    private function nextSortOrder(): int
    {
        $max = $this->pdo->query('SELECT MAX(sort_order) FROM pages_entries')->fetchColumn();
        $currentMax = is_numeric($max) ? (int) $max : 0;

        return $currentMax + 10;
    }
}
