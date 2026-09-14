<?php

declare(strict_types=1);

namespace ModulNest\Calendar\Repository;

use ModulNest\Calendar\DTO\CalendarDTO;
use PDO;

final class CalendarRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return CalendarDTO[]
     */
    public function findByUserId(int $userId): array
    {
        $this->ensureDefaultExists($userId);

        $stmt = $this->pdo->prepare(
            'SELECT * FROM `calendars` WHERE `user_id` = ? ORDER BY `is_default` DESC, `id` ASC'
        );
        $stmt->execute([$userId]);

        return array_map(
            static fn (array $row): CalendarDTO => CalendarDTO::fromArray($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * @return CalendarDTO[]
     */
    public function findVisibleByUserId(int $userId): array
    {
        $this->ensureDefaultExists($userId);

        $stmt = $this->pdo->prepare(
            'SELECT * FROM `calendars` WHERE `user_id` = ? AND `is_visible` = 1 ORDER BY `is_default` DESC, `id` ASC'
        );
        $stmt->execute([$userId]);

        return array_map(
            static fn (array $row): CalendarDTO => CalendarDTO::fromArray($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function findById(int $id, int $userId): ?CalendarDTO
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM `calendars` WHERE `id` = ? AND `user_id` = ? LIMIT 1'
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? CalendarDTO::fromArray($row) : null;
    }

    public function findDefault(int $userId): CalendarDTO
    {
        return $this->ensureDefaultExists($userId);
    }

    public function ensureDefaultExists(int $userId): CalendarDTO
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM `calendars` WHERE `user_id` = ? AND `is_default` = 1 LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return CalendarDTO::fromArray($row);
        }

        // Check if any calendar exists
        $stmtAny = $this->pdo->prepare('SELECT * FROM `calendars` WHERE `user_id` = ? ORDER BY `id` ASC LIMIT 1');
        $stmtAny->execute([$userId]);
        $anyRow = $stmtAny->fetch(PDO::FETCH_ASSOC);
        if ($anyRow) {
            $this->pdo->prepare('UPDATE `calendars` SET `is_default` = 1 WHERE `id` = ?')->execute([$anyRow['id']]);
            $anyRow['is_default'] = 1;
            return CalendarDTO::fromArray($anyRow);
        }

        // Create default
        $cal = new CalendarDTO(
            id: null,
            userId: $userId,
            name: 'Mein Kalender',
            color: '#3B82F6',
            isVisible: true,
            isDefault: true,
            source: 'local',
        );

        return $this->create($cal);
    }

    public function create(CalendarDTO $calendar): CalendarDTO
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO `calendars` (`user_id`, `name`, `color`, `is_visible`, `is_default`, `source`, `external_id`, `sync_token`, `last_synced_at`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $calendar->userId,
            $calendar->name,
            $calendar->color,
            $calendar->isVisible ? 1 : 0,
            $calendar->isDefault ? 1 : 0,
            $calendar->source,
            $calendar->externalId,
            $calendar->syncToken,
            $calendar->lastSyncedAt?->format('Y-m-d H:i:s'),
        ]);

        return $calendar->withId((int) $this->pdo->lastInsertId());
    }

    public function update(CalendarDTO $calendar): bool
    {
        if ($calendar->id === null) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE `calendars` SET
                `name` = ?,
                `color` = ?,
                `is_visible` = ?,
                `is_default` = ?,
                `external_id` = ?,
                `sync_token` = ?,
                `last_synced_at` = ?
             WHERE `id` = ? AND `user_id` = ?'
        );

        return $stmt->execute([
            $calendar->name,
            $calendar->color,
            $calendar->isVisible ? 1 : 0,
            $calendar->isDefault ? 1 : 0,
            $calendar->externalId,
            $calendar->syncToken,
            $calendar->lastSyncedAt?->format('Y-m-d H:i:s'),
            $calendar->id,
            $calendar->userId,
        ]);
    }

    public function toggleVisibility(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE `calendars` SET `is_visible` = 1 - `is_visible` WHERE `id` = ? AND `user_id` = ?'
        );

        return $stmt->execute([$id, $userId]);
    }

    public function delete(int $id, int $userId): bool
    {
        // Don't delete if it is the only calendar
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM `calendars` WHERE `user_id` = ?');
        $countStmt->execute([$userId]);
        if ((int) $countStmt->fetchColumn() <= 1) {
            return false;
        }

        $this->pdo->beginTransaction();
        try {
            // Delete appointments in this calendar
            $delAppts = $this->pdo->prepare('DELETE FROM `calendar_appointments` WHERE `calendar_id` = ? AND `user_id` = ?');
            $delAppts->execute([$id, $userId]);

            // Delete calendar
            $delCal = $this->pdo->prepare('DELETE FROM `calendars` WHERE `id` = ? AND `user_id` = ?');
            $delCal->execute([$id, $userId]);

            // Ensure a default calendar still exists
            $this->ensureDefaultExists($userId);

            $this->pdo->commit();
            return true;
        } catch (\Throwable) {
            $this->pdo->rollBack();
            return false;
        }
    }
}
