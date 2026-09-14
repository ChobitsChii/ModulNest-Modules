<?php

declare(strict_types=1);

namespace ModulNest\Calendar\Repository;

use ModulNest\Calendar\DTO\AppointmentDTO;
use PDO;

final class AppointmentRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
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

    public function findByDateRange(int $userId, \DateTimeInterface $start, \DateTimeInterface $end): array
    {
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

        return array_map(
            fn(array $row) => AppointmentDTO::fromArray($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function findByDay(int $userId, \DateTimeInterface $date): array
    {
        $dayStart = clone $date;
        $dayStart->setTime(0, 0, 0);
        $dayEnd = clone $date;
        $dayEnd->setTime(23, 59, 59);

        return $this->findByDateRange($userId, $dayStart, $dayEnd);
    }

    public function findByWeek(int $userId, \DateTimeInterface $weekStart): array
    {
        $start = clone $weekStart;
        $start->setTime(0, 0, 0);
        $end = clone $weekStart;
        $end->modify('+6 days');
        $end->setTime(23, 59, 59);

        return $this->findByDateRange($userId, $start, $end);
    }

    public function findByMonth(int $userId, \DateTimeInterface $monthDate): array
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
             (`user_id`, `calendar_id`, `title`, `description`, `location`, `start_at`, `end_at`, `all_day`, `color`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
                `color` = ?
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
}
