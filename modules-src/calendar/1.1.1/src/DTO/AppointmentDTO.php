<?php

declare(strict_types=1);

namespace ModulNest\Calendar\DTO;

final class AppointmentDTO
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $userId,
        public readonly ?int $calendarId,
        public readonly string $title,
        public readonly ?string $description,
        public readonly ?string $location,
        public readonly \DateTimeInterface $startAt,
        public readonly \DateTimeInterface $endAt,
        public readonly bool $allDay,
        public readonly ?string $color,
        public readonly \DateTimeInterface $createdAt,
        public readonly \DateTimeInterface $updatedAt,
        public readonly ?string $recurrenceRule = null,
        public readonly ?int $recurrenceParentId = null,
        public readonly ?string $googleEventId = null,
        public readonly ?string $googleEtag = null,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            userId: (int) ($row['user_id'] ?? 0),
            calendarId: isset($row['calendar_id']) && $row['calendar_id'] !== null ? (int) $row['calendar_id'] : null,
            title: (string) ($row['title'] ?? ''),
            description: isset($row['description']) && $row['description'] !== '' ? (string) $row['description'] : null,
            location: isset($row['location']) && $row['location'] !== '' ? (string) $row['location'] : null,
            startAt: new \DateTime((string) ($row['start_at'] ?? 'now')),
            endAt: new \DateTime((string) ($row['end_at'] ?? 'now')),
            allDay: !empty($row['all_day']),
            color: isset($row['color']) && $row['color'] !== '' ? (string) $row['color'] : '#3B82F6',
            createdAt: !empty($row['created_at']) ? new \DateTime((string) $row['created_at']) : new \DateTime(),
            updatedAt: !empty($row['updated_at']) ? new \DateTime((string) $row['updated_at']) : new \DateTime(),
            recurrenceRule: isset($row['recurrence_rule']) && $row['recurrence_rule'] !== '' ? (string) $row['recurrence_rule'] : null,
            recurrenceParentId: isset($row['recurrence_parent_id']) && $row['recurrence_parent_id'] !== null ? (int) $row['recurrence_parent_id'] : null,
            googleEventId: isset($row['google_event_id']) && $row['google_event_id'] !== '' ? (string) $row['google_event_id'] : null,
            googleEtag: isset($row['google_etag']) && $row['google_etag'] !== '' ? (string) $row['google_etag'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'calendar_id' => $this->calendarId,
            'title' => $this->title,
            'description' => $this->description,
            'location' => $this->location,
            'start_at' => $this->startAt->format('Y-m-d H:i:s'),
            'end_at' => $this->endAt->format('Y-m-d H:i:s'),
            'all_day' => $this->allDay ? 1 : 0,
            'color' => $this->color,
            'recurrence_rule' => $this->recurrenceRule,
            'recurrence_parent_id' => $this->recurrenceParentId,
            'google_event_id' => $this->googleEventId,
            'google_etag' => $this->googleEtag,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }

    public function toJsonArray(): array
    {
        return [
            'id' => $this->id,
            'calendar_id' => $this->calendarId,
            'title' => $this->title,
            'description' => $this->description,
            'location' => $this->location,
            'start' => $this->startAt->format('Y-m-d\TH:i:s'),
            'end' => $this->endAt->format('Y-m-d\TH:i:s'),
            'allDay' => $this->allDay,
            'color' => $this->color,
            'recurrenceRule' => $this->recurrenceRule,
            'recurrenceParentId' => $this->recurrenceParentId,
            'isGoogle' => $this->googleEventId !== null,
        ];
    }

    public function withId(int $id): self
    {
        return new self(
            id: $id,
            userId: $this->userId,
            calendarId: $this->calendarId,
            title: $this->title,
            description: $this->description,
            location: $this->location,
            startAt: $this->startAt,
            endAt: $this->endAt,
            allDay: $this->allDay,
            color: $this->color,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
            recurrenceRule: $this->recurrenceRule,
            recurrenceParentId: $this->recurrenceParentId,
            googleEventId: $this->googleEventId,
            googleEtag: $this->googleEtag,
        );
    }
}
