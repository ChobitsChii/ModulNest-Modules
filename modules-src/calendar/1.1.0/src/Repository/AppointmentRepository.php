<?php

declare(strict_types=1);

namespace ModulNest\Calendar\Repository;

use DateTimeInterface;
use ModulNest\Calendar\DTO\AppointmentDTO;
use ModulNest\Calendar\Service\RecurrenceService;
use PDO;

final class AppointmentRepository
{
    private PDO $pdo;
    private RecurrenceService $recurrence;

    public function __construct(PDO $pdo, ?RecurrenceService $recurrence = null)
    {
        $this->pdo = $pdo;
        $this->recurrence = $recurrence ?? new RecurrenceService();
    }

    public function findById(int $id, int $userId): ?AppointmentDTO
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, COALESCE(NULLIF(a.color, ""), c.color, "#3B82F6") AS color
             FROM `calendar_appointments` a
             LEFT JOIN `calendars` c ON c.id = a.calendar_id
             WHERE a.`id` = ? AND a.`user_id` = ?'
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? AppointmentDTO::fromArray($row) : null;
    }

    public function findByGoogleEventId(int $userId, string $googleEventId): ?AppointmentDTO
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, COALESCE(NULLIF(a.color, ""), c.color, "#3B82F6") AS color
             FROM `calendar_appointments` a
             LEFT JOIN `calendars` c ON c.id = a.calendar_id
             WHERE a.`user_id` = ? AND a.`google_event_id` = ? LIMIT 1'
        );
        $stmt->execute([$userId, $googleEventId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? AppointmentDTO::fromArray($row) : null;
    }

    public function findByDateRange(int $userId, DateTimeInterface $start, DateTimeInterface $end): array
    {
        // 1. Direct appointments within range
        $stmt = $this->pdo->prepare(
            'SELECT a.*, COALESCE(NULLIF(a.color, ""), c.color, "#3B82F6") AS color
             FROM `calendar_appointments` a
             LEFT JOIN `calendars` c ON c.id = a.calendar_id
             WHERE a.`user_id` = ?
             AND (c.id IS NULL OR c.is_visible = 1)
             AND a.`start_at` < ?
             AND a.`end_at` > ?
             ORDER BY a.`all_day` DESC, a.`start_at` ASC'
        );
        $stmt->execute([
            $userId,
            $end->format('Y-m-d H:i:s'),
            $start->format('Y-m-d H:i:s'),
        ]);

        $directAppointments = array_map(
            static fn (array $row): AppointmentDTO => AppointmentDTO::fromArray($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );

        // 2. Fetch recurring parents that started before the range end
        $stmtRecurring = $this->pdo->prepare(
            'SELECT a.*, COALESCE(NULLIF(a.color, ""), c.color, "#3B82F6") AS color
             FROM `calendar_appointments` a
             LEFT JOIN `calendars` c ON c.id = a.calendar_id
             WHERE a.`user_id` = ?
             AND (c.id IS NULL OR c.is_visible = 1)
             AND a.`recurrence_rule` IS NOT NULL
             AND a.`recurrence_rule` != ""
             AND a.`recurrence_parent_id` IS NULL
             AND a.`start_at` < ?'
        );
        $stmtRecurring->execute([
            $userId,
            $end->format('Y-m-d H:i:s'),
        ]);

        $recurringParents = array_map(
            static fn (array $row): AppointmentDTO => AppointmentDTO::fromArray($row),
            $stmtRecurring->fetchAll(PDO::FETCH_ASSOC)
        );

        // Combine unique events by ID
        $combined = [];
        foreach ($directAppointments as $apt) {
            $combined[$apt->id ?? spl_object_id($apt)] = $apt;
        }
        foreach ($recurringParents as $apt) {
            $combined[$apt->id ?? spl_object_id($apt)] = $apt;
        }

        return $this->recurrence->expandOccurrences(array_values($combined), $start, $end);
    }

    public function findByDay(int $userId, DateTimeInterface $date): array
    {
        $dayStart = clone $date;
        $dayStart->setTime(0, 0, 0);
        $dayEnd = clone $date;
        $dayEnd->setTime(23, 59, 59);

        return $this->findByDateRange($userId, $dayStart, $dayEnd);
    }

    public function findByWeek(int $userId, DateTimeInterface $weekStart): array
    {
        $start = clone $weekStart;
        $start->setTime(0, 0, 0);
        $end = clone $weekStart;
        $end->modify('+6 days');
        $end->setTime(23, 59, 59);

        return $this->findByDateRange($userId, $start, $end);
    }

    public function findByMonth(int $userId, DateTimeInterface $monthDate): array
    {
        $start = clone $monthDate;
        $start->modify('first day of this month');
        $start->setTime(0, 0, 0);
        $start->modify('monday this week');

        $end = clone $monthDate;
        $end->modify('last day of this month');
        $end->setTime(23, 59, 59);
        $end->modify('sunday this week');

        return $this->findByDateRange($userId, $start, $end);
    }

    public function create(AppointmentDTO $appointment): AppointmentDTO
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO `calendar_appointments`
             (`user_id`, `calendar_id`, `title`, `description`, `location`, `start_at`, `end_at`, `all_day`, `color`, `recurrence_rule`, `recurrence_parent_id`, `google_event_id`, `google_etag`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $appointment->userId,
            $appointment->calendarId,
            $appointment->title,
            $appointment->description,
            $appointment->location,
            $appointment->startAt->format('Y-m-d H:i:s'),
            $appointment->endAt->format('Y-m-d H:i:s'),
            $appointment->allDay ? 1 : 0,
            $appointment->color,
            $appointment->recurrenceRule,
            $appointment->recurrenceParentId,
            $appointment->googleEventId,
            $appointment->googleEtag,
        ]);

        return $appointment->withId((int) $this->pdo->lastInsertId());
    }

    public function update(AppointmentDTO $appointment): bool
    {
        if ($appointment->id === null) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE `calendar_appointments` SET
                `calendar_id` = ?,
                `title` = ?,
                `description` = ?,
                `location` = ?,
                `start_at` = ?,
                `end_at` = ?,
                `all_day` = ?,
                `color` = ?,
                `recurrence_rule` = ?,
                `recurrence_parent_id` = ?,
                `google_event_id` = ?,
                `google_etag` = ?
             WHERE `id` = ? AND `user_id` = ?'
        );

        return $stmt->execute([
            $appointment->calendarId,
            $appointment->title,
            $appointment->description,
            $appointment->location,
            $appointment->startAt->format('Y-m-d H:i:s'),
            $appointment->endAt->format('Y-m-d H:i:s'),
            $appointment->allDay ? 1 : 0,
            $appointment->color,
            $appointment->recurrenceRule,
            $appointment->recurrenceParentId,
            $appointment->googleEventId,
            $appointment->googleEtag,
            $appointment->id,
            $appointment->userId,
        ]);
    }

    public function delete(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM `calendar_appointments` WHERE `id` = ? AND `user_id` = ?'
        );

        return $stmt->execute([$id, $userId]);
    }

    public function deleteByCalendarId(int $calendarId, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM `calendar_appointments` WHERE `calendar_id` = ? AND `user_id` = ?'
        );

        return $stmt->execute([$calendarId, $userId]);
    }
}
