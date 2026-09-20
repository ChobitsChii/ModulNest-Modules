<?php

declare(strict_types=1);

namespace ModulNest\Mirror\Repository;

use ModulNest\Mirror\DTO\MirrorConfigDTO;
use PDO;

final class MirrorConfigRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<MirrorConfigDTO>
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM `mirror_configs` ORDER BY `id` ASC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[] = MirrorConfigDTO::fromArray($row);
        }

        return $result;
    }

    public function findById(int $id): ?MirrorConfigDTO
    {
        $stmt = $this->pdo->prepare('SELECT * FROM `mirror_configs` WHERE `id` = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? MirrorConfigDTO::fromArray($row) : null;
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO `mirror_configs`
             (`name`, `type`, `source_type`, `source_url`, `source_branch`, `target_path`, `public_url`, `enabled`, `last_status`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (string) ($data['name'] ?? ''),
            (string) ($data['type'] ?? 'repository'),
            (string) ($data['source_type'] ?? 'github'),
            (string) ($data['source_url'] ?? ''),
            (string) ($data['source_branch'] ?? 'main'),
            (string) ($data['target_path'] ?? ''),
            !empty($data['public_url']) ? (string) $data['public_url'] : null,
            !empty($data['enabled']) ? 1 : 0,
            'idle',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE `mirror_configs` SET
             `name` = ?,
             `type` = ?,
             `source_type` = ?,
             `source_url` = ?,
             `source_branch` = ?,
             `target_path` = ?,
             `public_url` = ?,
             `enabled` = ?
             WHERE `id` = ?'
        );

        return $stmt->execute([
            (string) ($data['name'] ?? ''),
            (string) ($data['type'] ?? 'repository'),
            (string) ($data['source_type'] ?? 'github'),
            (string) ($data['source_url'] ?? ''),
            (string) ($data['source_branch'] ?? 'main'),
            (string) ($data['target_path'] ?? ''),
            !empty($data['public_url']) ? (string) $data['public_url'] : null,
            !empty($data['enabled']) ? 1 : 0,
            $id,
        ]);
    }

    public function toggleEnabled(int $id): bool
    {
        $stmt = $this->pdo->prepare('UPDATE `mirror_configs` SET `enabled` = NOT `enabled` WHERE `id` = ?');
        return $stmt->execute([$id]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM `mirror_configs` WHERE `id` = ?');
        return $stmt->execute([$id]);
    }

    public function updateStatus(int $id, string $status, ?string $log = null, ?string $trigger = null): void
    {
        if ($status === 'running') {
            $stmt = $this->pdo->prepare('UPDATE `mirror_configs` SET `last_status` = ? WHERE `id` = ?');
            $stmt->execute([$status, $id]);
            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE `mirror_configs` SET `last_status` = ?, `last_synced_at` = CURRENT_TIMESTAMP, `last_log` = ?, `last_trigger` = ? WHERE `id` = ?'
        );
        $stmt->execute([$status, $log, $trigger, $id]);
    }
}
