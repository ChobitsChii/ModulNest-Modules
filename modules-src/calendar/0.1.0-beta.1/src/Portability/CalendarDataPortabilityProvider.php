<?php

declare(strict_types=1);

namespace ModulNest\Calendar\Portability;

use Modulon\Core\Modules\DataPortability\DataPortabilityArchiveReader;
use Modulon\Core\Modules\DataPortability\DataPortabilityFileCollector;
use Modulon\Core\Modules\DataPortability\DataPortabilityProviderInterface;
use PDO;
use Throwable;

final class CalendarDataPortabilityProvider implements DataPortabilityProviderInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function key(): string
    {
        return 'calendar';
    }

    public function label(): string
    {
        return 'Kalender';
    }

    public function routePrefix(): string
    {
        return '/calendar';
    }

    public function description(): string
    {
        return 'Termine und Kalender des aktuellen Benutzers.';
    }

    public function schemaVersion(): int
    {
        return 2;
    }

    public function hasFiles(): bool
    {
        return false;
    }

    public function sensitivityNote(): string
    {
        return 'Enthält persönliche Termine mit Zeit, Ort und Beschreibung sowie Kalenderlisten.';
    }

    public function supportsReplaceImport(): bool
    {
        return true;
    }

    public function scopes(): array
    {
        return ['user'];
    }

    public function export(int $userId, DataPortabilityFileCollector $files): array
    {
        $calendars = $this->fetchAll(
            'SELECT * FROM `calendars` WHERE `user_id` = ? ORDER BY `is_default` DESC, `id` ASC',
            [$userId]
        );

        $appointments = $this->fetchAll(
            'SELECT * FROM `calendar_appointments` WHERE `user_id` = ? ORDER BY `start_at` ASC',
            [$userId]
        );

        return [
            'files' => [
                'calendars.json' => [
                    'schema_version' => $this->schemaVersion(),
                    'calendars' => $calendars,
                ],
                'appointments.json' => [
                    'schema_version' => $this->schemaVersion(),
                    'appointments' => $appointments,
                ],
            ],
            'counts' => [
                'calendars' => count($calendars),
                'appointments' => count($appointments),
            ],
            'warnings' => [$this->sensitivityNote()],
        ];
    }

    public function previewImport(array $payload, array $manifestModule, DataPortabilityArchiveReader $archive, int $targetUserId): array
    {
        $appointments = $payload['appointments']['appointments'] ?? [];
        $calendars = $payload['calendars']['calendars'] ?? [];

        return [
            'counts' => [
                'calendars' => count($calendars),
                'appointments' => count($appointments),
            ],
            'warnings' => [
                $this->sensitivityNote(),
                'Import löscht keine bestehenden Termine (Merge-Modus), außer bei explizitem Replace.',
            ],
            'can_import' => true,
        ];
    }

    public function import(array $payload, array $manifestModule, DataPortabilityArchiveReader $archive, int $targetUserId, string $importMode = 'merge'): array
    {
        $appointments = $payload['appointments']['appointments'] ?? [];
        $calendars = $payload['calendars']['calendars'] ?? [];

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $warnings = [];

        $this->pdo->beginTransaction();
        try {
            if ($importMode === 'replace') {
                $this->clearTargetData($targetUserId);
            }

            // Ensure a default calendar exists
            $calMap = [];
            foreach ($calendars as $cal) {
                if (!is_array($cal)) continue;
                $oldId = (int) ($cal['id'] ?? 0);
                $stmt = $this->pdo->prepare('INSERT INTO `calendars` (`user_id`, `name`, `color`, `is_visible`, `is_default`, `source`) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $targetUserId,
                    (string) ($cal['name'] ?? 'Kalender'),
                    (string) ($cal['color'] ?? '#3B82F6'),
                    !empty($cal['is_visible']) ? 1 : 0,
                    !empty($cal['is_default']) ? 1 : 0,
                    (string) ($cal['source'] ?? 'local'),
                ]);
                $newId = (int) $this->pdo->lastInsertId();
                if ($oldId > 0) {
                    $calMap[$oldId] = $newId;
                }
            }

            foreach ($appointments as $appointment) {
                if (!is_array($appointment)) {
                    $skipped++;
                    $warnings[] = 'Ungültiger Termineintrag übersprungen.';
                    continue;
                }

                $calId = isset($appointment['calendar_id']) ? ($calMap[(int) $appointment['calendar_id']] ?? null) : null;
                $appointment['calendar_id'] = $calId;

                try {
                    $this->insert($targetUserId, $appointment);
                    $created++;
                } catch (Throwable $e) {
                    $skipped++;
                    $warnings[] = 'Termin "' . (string) ($appointment['title'] ?? 'unbekannt') . '": ' . $e->getMessage();
                }
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'warnings' => $warnings,
        ];
    }

    private function insert(int $userId, array $appointment): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO `calendar_appointments`
             (`user_id`, `calendar_id`, `title`, `description`, `location`, `start_at`, `end_at`, `all_day`, `color`, `created_at`, `updated_at`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $appointment['calendar_id'] ?? null,
            (string) ($appointment['title'] ?? ''),
            $this->nullable($appointment['description'] ?? null),
            $this->nullable($appointment['location'] ?? null),
            (string) ($appointment['start_at'] ?? ''),
            (string) ($appointment['end_at'] ?? ''),
            !empty($appointment['all_day']) ? 1 : 0,
            $this->nullable($appointment['color'] ?? null),
            (string) ($appointment['created_at'] ?? $this->now()),
            (string) ($appointment['updated_at'] ?? $this->now()),
        ]);
    }

    private function clearTargetData(int $userId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM `calendar_appointments` WHERE `user_id` = ?');
        $stmt->execute([$userId]);

        $stmt2 = $this->pdo->prepare('DELETE FROM `calendars` WHERE `user_id` = ?');
        $stmt2->execute([$userId]);
    }

    private function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function nullable(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (string) $value;
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
