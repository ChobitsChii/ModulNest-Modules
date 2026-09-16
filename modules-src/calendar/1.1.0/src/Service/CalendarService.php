<?php

declare(strict_types=1);

namespace ModulNest\Calendar\Service;

use DateTime;
use DateTimeInterface;
use ModulNest\Calendar\DTO\AppointmentDTO;
use ModulNest\Calendar\DTO\CalendarDTO;
use ModulNest\Calendar\Repository\AppointmentRepository;
use ModulNest\Calendar\Repository\CalendarRepository;
use ModulNest\Calendar\Validation\AppointmentValidator;

final class CalendarService
{
    private AppointmentRepository $repository;
    private CalendarRepository $calendarRepository;
    private AppointmentValidator $validator;
    private ?GoogleCalendarService $google;

    public function __construct(
        AppointmentRepository $repository,
        CalendarRepository $calendarRepository,
        AppointmentValidator $validator,
        ?GoogleCalendarService $google = null
    ) {
        $this->repository = $repository;
        $this->calendarRepository = $calendarRepository;
        $this->validator = $validator;
        $this->google = $google;
    }

    public function getGoogleService(): ?GoogleCalendarService
    {
        return $this->google;
    }

    // --- Calendar Management ---

    /**
     * @return CalendarDTO[]
     */
    public function getCalendars(int $userId): array
    {
        return $this->calendarRepository->findByUserId($userId);
    }

    /**
     * @return CalendarDTO[]
     */
    public function getVisibleCalendars(int $userId): array
    {
        return $this->calendarRepository->findVisibleByUserId($userId);
    }

    public function getCalendar(int $id, int $userId): ?CalendarDTO
    {
        return $this->calendarRepository->findById($id, $userId);
    }

    public function createCalendar(array $data, int $userId): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return ['success' => false, 'errors' => ['name' => 'Kalendername darf nicht leer sein']];
        }

        $color = trim((string) ($data['color'] ?? '#3B82F6'));
        if (!preg_match('/^#[a-fA-F0-9]{6}$/', $color)) {
            $color = '#3B82F6';
        }

        $cal = new CalendarDTO(
            id: null,
            userId: $userId,
            name: $name,
            color: $color,
            isVisible: true,
            isDefault: false,
            source: 'local',
        );

        $created = $this->calendarRepository->create($cal);

        return ['success' => true, 'calendar' => $created];
    }

    public function updateCalendar(int $id, array $data, int $userId): array
    {
        $cal = $this->calendarRepository->findById($id, $userId);
        if (!$cal) {
            return ['success' => false, 'errors' => ['general' => 'Kalender nicht gefunden']];
        }

        $name = trim((string) ($data['name'] ?? $cal->name));
        if ($name === '') {
            return ['success' => false, 'errors' => ['name' => 'Kalendername darf nicht leer sein']];
        }

        $color = trim((string) ($data['color'] ?? $cal->color));
        if (!preg_match('/^#[a-fA-F0-9]{6}$/', $color)) {
            $color = $cal->color;
        }

        $updated = new CalendarDTO(
            id: $cal->id,
            userId: $userId,
            name: $name,
            color: $color,
            isVisible: isset($data['is_visible']) ? !empty($data['is_visible']) : $cal->isVisible,
            isDefault: $cal->isDefault,
            source: $cal->source,
            externalId: $cal->externalId,
            syncToken: $cal->syncToken,
            lastSyncedAt: $cal->lastSyncedAt,
            createdAt: $cal->createdAt,
            updatedAt: new DateTime(),
        );

        $this->calendarRepository->update($updated);

        return ['success' => true, 'calendar' => $updated];
    }

    public function deleteCalendar(int $id, int $userId): array
    {
        $deleted = $this->calendarRepository->delete($id, $userId);
        if (!$deleted) {
            return ['success' => false, 'errors' => ['general' => 'Der letzte oder Standard-Kalender kann nicht gelöscht werden.']];
        }

        return ['success' => true];
    }

    public function toggleCalendarVisibility(int $id, int $userId): bool
    {
        return $this->calendarRepository->toggleVisibility($id, $userId);
    }

    // --- Appointments ---

    public function getAppointmentsForDay(int $userId, DateTimeInterface $date): array
    {
        return $this->repository->findByDay($userId, $date);
    }

    public function getAppointmentsForWeek(int $userId, DateTimeInterface $weekStart): array
    {
        return $this->repository->findByWeek($userId, $weekStart);
    }

    public function getAppointmentsForMonth(int $userId, DateTimeInterface $monthDate): array
    {
        return $this->repository->findByMonth($userId, $monthDate);
    }

    public function getAppointmentsForRange(int $userId, DateTimeInterface $start, DateTimeInterface $end): array
    {
        return $this->repository->findByDateRange($userId, $start, $end);
    }

    public function getAppointment(int $id, int $userId): ?AppointmentDTO
    {
        return $this->repository->findById($id, $userId);
    }

    public function createAppointment(array $data, int $userId): array
    {
        $errors = $this->validator->validate($data);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        $startAt = new DateTime((string) $data['start_at']);
        $endAt = new DateTime((string) $data['end_at']);

        if ($endAt <= $startAt) {
            return ['success' => false, 'errors' => ['end_at' => 'Endzeit muss nach Startzeit liegen']];
        }

        $calId = isset($data['calendar_id']) && $data['calendar_id'] !== '' ? (int) $data['calendar_id'] : null;
        $targetCal = $calId !== null ? $this->calendarRepository->findById($calId, $userId) : null;
        if ($targetCal === null) {
            $targetCal = $this->calendarRepository->findDefault($userId);
            $calId = $targetCal->id;
        }

        $color = $data['color'] ?? null;
        if (empty($color)) {
            $color = $targetCal->color ?? '#3B82F6';
        }

        // Build recurrence rule if specified
        $recurrenceRule = null;
        if (!empty($data['recurrence_freq']) && strtoupper((string) $data['recurrence_freq']) !== 'NONE') {
            $recurrenceRule = RecurrenceService::buildRule(
                (string) $data['recurrence_freq'],
                isset($data['recurrence_interval']) ? (int) $data['recurrence_interval'] : 1,
                !empty($data['recurrence_until']) ? (string) $data['recurrence_until'] : null
            );
        }

        $googleEventId = null;

        $temporaryDto = new AppointmentDTO(
            id: null,
            userId: $userId,
            calendarId: $calId,
            title: trim((string) $data['title']),
            description: !empty($data['description']) ? trim((string) $data['description']) : null,
            location: !empty($data['location']) ? trim((string) $data['location']) : null,
            startAt: $startAt,
            endAt: $endAt,
            allDay: !empty($data['all_day']),
            color: $color,
            createdAt: new DateTime(),
            updatedAt: new DateTime(),
            recurrenceRule: $recurrenceRule,
            recurrenceParentId: null,
            googleEventId: null,
        );

        // Outbound Google Sync
        if ($targetCal->source === 'google' && $this->google !== null && $calId !== null) {
            try {
                $googleEventId = $this->google->createEventOnGoogle($userId, $calId, $temporaryDto);
            } catch (\Throwable) {
                // Keep local event intact even if Google is unreachable
            }
        }

        $finalDto = new AppointmentDTO(
            id: null,
            userId: $userId,
            calendarId: $calId,
            title: $temporaryDto->title,
            description: $temporaryDto->description,
            location: $temporaryDto->location,
            startAt: $temporaryDto->startAt,
            endAt: $temporaryDto->endAt,
            allDay: $temporaryDto->allDay,
            color: $temporaryDto->color,
            createdAt: $temporaryDto->createdAt,
            updatedAt: $temporaryDto->updatedAt,
            recurrenceRule: $temporaryDto->recurrenceRule,
            recurrenceParentId: null,
            googleEventId: $googleEventId,
        );

        $created = $this->repository->create($finalDto);

        return ['success' => true, 'appointment' => $created];
    }

    public function updateAppointment(int $id, array $data, int $userId): array
    {
        $existing = $this->repository->findById($id, $userId);
        if (!$existing) {
            return ['success' => false, 'errors' => ['general' => 'Termin nicht gefunden']];
        }

        $errors = $this->validator->validate($data, true);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        $startAt = isset($data['start_at']) ? new DateTime((string) $data['start_at']) : $existing->startAt;
        $endAt = isset($data['end_at']) ? new DateTime((string) $data['end_at']) : $existing->endAt;

        if ($endAt <= $startAt) {
            return ['success' => false, 'errors' => ['end_at' => 'Endzeit muss nach Startzeit liegen']];
        }

        $calId = isset($data['calendar_id']) && $data['calendar_id'] !== '' ? (int) $data['calendar_id'] : $existing->calendarId;
        $targetCal = $calId !== null ? $this->calendarRepository->findById($calId, $userId) : null;

        // Recurrence rule
        $recurrenceRule = $existing->recurrenceRule;
        if (isset($data['recurrence_freq'])) {
            if (strtoupper((string) $data['recurrence_freq']) === 'NONE' || empty($data['recurrence_freq'])) {
                $recurrenceRule = null;
            } else {
                $recurrenceRule = RecurrenceService::buildRule(
                    (string) $data['recurrence_freq'],
                    isset($data['recurrence_interval']) ? (int) $data['recurrence_interval'] : 1,
                    !empty($data['recurrence_until']) ? (string) $data['recurrence_until'] : null
                );
            }
        }

        $updated = new AppointmentDTO(
            id: $existing->id,
            userId: $userId,
            calendarId: $calId,
            title: isset($data['title']) ? trim((string) $data['title']) : $existing->title,
            description: array_key_exists('description', $data)
                ? (!empty($data['description']) ? trim((string) $data['description']) : null)
                : $existing->description,
            location: array_key_exists('location', $data)
                ? (!empty($data['location']) ? trim((string) $data['location']) : null)
                : $existing->location,
            startAt: $startAt,
            endAt: $endAt,
            allDay: isset($data['all_day']) ? !empty($data['all_day']) : $existing->allDay,
            color: !empty($data['color']) ? (string) $data['color'] : $existing->color,
            createdAt: $existing->createdAt,
            updatedAt: new DateTime(),
            recurrenceRule: $recurrenceRule,
            recurrenceParentId: $existing->recurrenceParentId,
            googleEventId: $existing->googleEventId,
            googleEtag: $existing->googleEtag,
        );

        $this->repository->update($updated);

        // Outbound Google Sync
        if ($targetCal !== null && $targetCal->source === 'google' && $this->google !== null && !empty($existing->googleEventId) && $calId !== null) {
            try {
                $this->google->updateEventOnGoogle($userId, $calId, $updated);
            } catch (\Throwable) {
                // Keep local update
            }
        }

        return ['success' => true, 'appointment' => $updated];
    }

    public function deleteAppointment(int $id, int $userId): bool
    {
        $existing = $this->repository->findById($id, $userId);
        if ($existing === null) {
            return false;
        }

        // Outbound Google Sync
        if ($existing->calendarId !== null && !empty($existing->googleEventId) && $this->google !== null) {
            try {
                $this->google->deleteEventOnGoogle($userId, $existing->calendarId, $existing->googleEventId);
            } catch (\Throwable) {
                // Proceed with local deletion
            }
        }

        return $this->repository->delete($id, $userId);
    }

    public function moveAppointment(int $id, DateTimeInterface $newStart, DateTimeInterface $newEnd, int $userId): array
    {
        $existing = $this->repository->findById($id, $userId);
        if (!$existing) {
            return ['success' => false, 'error' => 'Termin nicht gefunden'];
        }

        if ($newEnd <= $newStart) {
            return ['success' => false, 'error' => 'Endzeit muss nach Startzeit liegen'];
        }

        $updated = new AppointmentDTO(
            id: $existing->id,
            userId: $userId,
            calendarId: $existing->calendarId,
            title: $existing->title,
            description: $existing->description,
            location: $existing->location,
            startAt: $newStart,
            endAt: $newEnd,
            allDay: $existing->allDay,
            color: $existing->color,
            createdAt: $existing->createdAt,
            updatedAt: new DateTime(),
            recurrenceRule: $existing->recurrenceRule,
            recurrenceParentId: $existing->recurrenceParentId,
            googleEventId: $existing->googleEventId,
            googleEtag: $existing->googleEtag,
        );

        $this->repository->update($updated);

        // Outbound Google Sync
        if ($existing->calendarId !== null && !empty($existing->googleEventId) && $this->google !== null) {
            try {
                $this->google->updateEventOnGoogle($userId, $existing->calendarId, $updated);
            } catch (\Throwable) {
                // Keep local move
            }
        }

        return ['success' => true, 'appointment' => $updated];
    }
}
