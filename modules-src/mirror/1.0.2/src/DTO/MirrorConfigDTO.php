<?php

declare(strict_types=1);

namespace ModulNest\Mirror\DTO;

final class MirrorConfigDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $type, // 'repository' | 'updates'
        public readonly string $sourceType, // 'github' | 'url'
        public readonly string $sourceUrl,
        public readonly string $sourceBranch,
        public readonly string $targetPath,
        public readonly ?string $publicUrl,
        public readonly bool $enabled,
        public readonly ?string $lastSyncedAt,
        public readonly ?string $lastTrigger,
        public readonly string $lastStatus, // 'idle' | 'running' | 'success' | 'error'
        public readonly ?string $lastLog,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    public static function fromArray(array $row): self
    {
        return new self(
            id: (int) ($row['id'] ?? 0),
            name: (string) ($row['name'] ?? ''),
            type: (string) ($row['type'] ?? 'repository'),
            sourceType: (string) ($row['source_type'] ?? 'github'),
            sourceUrl: (string) ($row['source_url'] ?? ''),
            sourceBranch: (string) ($row['source_branch'] ?? 'main'),
            targetPath: (string) ($row['target_path'] ?? ''),
            publicUrl: !empty($row['public_url']) ? (string) $row['public_url'] : null,
            enabled: !empty($row['enabled']),
            lastSyncedAt: !empty($row['last_synced_at']) ? (string) $row['last_synced_at'] : null,
            lastTrigger: !empty($row['last_trigger']) ? (string) $row['last_trigger'] : null,
            lastStatus: (string) ($row['last_status'] ?? 'idle'),
            lastLog: !empty($row['last_log']) ? (string) $row['last_log'] : null,
            createdAt: (string) ($row['created_at'] ?? ''),
            updatedAt: (string) ($row['updated_at'] ?? ''),
        );
    }
}
