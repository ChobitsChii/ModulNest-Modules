<?php

declare(strict_types=1);

namespace ModulNest\Calendar\DTO;

final class CalendarDTO
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $userId,
        public readonly string $name,
        public readonly string $color,
        public readonly bool $isVisible,
        public readonly bool $isDefault,
        public readonly string $source = 'local',
        public readonly ?string $externalId = null,
        public readonly ?string $syncToken = null,
        public readonly ?\DateTimeInterface $lastSyncedAt = null,
        public readonly ?\DateTimeInterface $createdAt = null,
        public readonly ?\DateTimeInterface $updatedAt = null,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            userId: (int) ($row['user_id'] ?? 0),
            name: (string) ($row['name'] ?? 'Kalender'),
            color: (string) ($row['color'] ?? '#3B82F6'),
            isVisible: !empty($row['is_visible']),
            isDefault: !empty($row['is_default']),
            source: (string) ($row['source'] ?? 'local'),
            externalId: isset($row['external_id']) && $row['external_id'] !== '' ? (string) $row['external_id'] : null,
            syncToken: isset($row['sync_token']) && $row['sync_token'] !== '' ? (string) $row['sync_token'] : null,
            lastSyncedAt: !empty($row['last_synced_at']) ? new \DateTime((string) $row['last_synced_at']) : null,
            createdAt: !empty($row['created_at']) ? new \DateTime((string) $row['created_at']) : new \DateTime(),
            updatedAt: !empty($row['updated_at']) ? new \DateTime((string) $row['updated_at']) : new \DateTime(),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'name' => $this->name,
            'color' => $this->color,
            'is_visible' => $this->isVisible ? 1 : 0,
            'is_default' => $this->isDefault ? 1 : 0,
            'source' => $this->source,
            'external_id' => $this->externalId,
            'sync_token' => $this->syncToken,
            'last_synced_at' => $this->lastSyncedAt?->format('Y-m-d H:i:s'),
            'created_at' => $this->createdAt?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt?->format('Y-m-d H:i:s'),
        ];
    }

    public function withId(int $id): self
    {
        return new self(
            id: $id,
            userId: $this->userId,
            name: $this->name,
            color: $this->color,
            isVisible: $this->isVisible,
            isDefault: $this->isDefault,
            source: $this->source,
            externalId: $this->externalId,
            syncToken: $this->syncToken,
            lastSyncedAt: $this->lastSyncedAt,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
        );
    }
}
