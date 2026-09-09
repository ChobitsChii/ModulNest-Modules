<?php

declare(strict_types=1);

namespace ModulNest\News;

use PDO;

final class NewsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPublished(): array
    {
        $statement = $this->pdo->query(
            "SELECT id, title, slug, excerpt, content, type, version, status, published_at, created_at, updated_at
             FROM news_entries
             WHERE status = 'published' AND (published_at IS NULL OR published_at <= CURRENT_TIMESTAMP)
             ORDER BY COALESCE(published_at, created_at) DESC, id DESC"
        );

        $rows = $statement->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPublishedBySlug(string $slug): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, title, slug, excerpt, content, type, version, status, published_at, created_at, updated_at
             FROM news_entries
             WHERE slug = :slug
               AND status = 'published'
               AND (published_at IS NULL OR published_at <= CURRENT_TIMESTAMP)
             LIMIT 1"
        );
        $statement->execute(['slug' => $slug]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAllForAdmin(): array
    {
        $statement = $this->pdo->query(
            "SELECT id, title, slug, excerpt, type, version, status, published_at, created_at, updated_at,
                    CASE
                        WHEN status <> 'published' THEN 'draft'
                        WHEN published_at IS NOT NULL AND published_at > CURRENT_TIMESTAMP THEN 'scheduled'
                        ELSE 'published'
                    END AS effective_status
             FROM news_entries
             ORDER BY created_at DESC, id DESC"
        );

        $rows = $statement->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, title, slug, excerpt, content, type, version, status, published_at, created_by, updated_by, created_at, updated_at
             FROM news_entries
             WHERE id = :id
             LIMIT 1"
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function slugExists(string $slug, int $excludeId = 0): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM news_entries WHERE slug = :slug AND id <> :exclude_id LIMIT 1'
        );
        $statement->execute([
            'slug' => $slug,
            'exclude_id' => $excludeId,
        ]);

        return $statement->fetch() !== false;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO news_entries (title, slug, excerpt, content, type, version, status, published_at, created_by, updated_by)
             VALUES (:title, :slug, :excerpt, :content, :type, :version, :status, :published_at, :created_by, :updated_by)"
        );
        $statement->execute([
            'title' => $data['title'],
            'slug' => $data['slug'],
            'excerpt' => $data['excerpt'],
            'content' => $data['content'],
            'type' => $data['type'],
            'version' => $data['version'],
            'status' => $data['status'],
            'published_at' => $data['published_at'],
            'created_by' => $data['created_by'],
            'updated_by' => $data['updated_by'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE news_entries
             SET title = :title,
                 slug = :slug,
                 excerpt = :excerpt,
                 content = :content,
                 type = :type,
                 version = :version,
                 status = :status,
                 published_at = :published_at,
                 updated_by = :updated_by,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $statement->execute([
            'id' => $id,
            'title' => $data['title'],
            'slug' => $data['slug'],
            'excerpt' => $data['excerpt'],
            'content' => $data['content'],
            'type' => $data['type'],
            'version' => $data['version'],
            'status' => $data['status'],
            'published_at' => $data['published_at'],
            'updated_by' => $data['updated_by'],
        ]);
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM news_entries WHERE id = :id');
        $statement->execute(['id' => $id]);
    }
}
