<?php

declare(strict_types=1);

namespace ModulNest\Calendar\Service;

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

    public function __construct(
        AppointmentRepository $repository,
        CalendarRepository $calendarRepository,
        AppointmentValidator $validator
    ) {
        $this->repository = $repository;
        $this->calendarRepository = $calendarRepository;
        $this->validator = $validator;
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
            updatedAt: new \DateTime(),
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

    public function getAppointmentsForDay(int $userId, \DateTimeInterface $date): array
    {
        return $this->repository->findByDay($userId, $date);
    }

    public function getAppointmentsForWeek(int $userId, \DateTimeInterface $weekStart): array
    {
        return $this->repository->findByWeek($userId, $weekStart);
    }

    public function getAppointmentsForMonth(int $userId, \DateTimeInterface $monthDate): array
    {
        return $this->repository->findByMonth($userId, $monthDate);
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

        $startAt = new \DateTime($data['start_at']);
        $endAt = new \DateTime($data['end_at']);

        if ($endAt <= $startAt) {
            return ['success' => false, 'errors' => ['end_at' => 'Endzeit muss nach Startzeit liegen']];
        }

        $calId = isset($data['calendar_id']) && $data['calendar_id'] !== '' ? (int) $data['calendar_id'] : null;
        if ($calId === null || $this->calendarRepository->findById($calId, $userId) === null) {
            $defaultCal = $this->calendarRepository->findDefault($userId);
            $calId = $defaultCal->id;
        }

        $color = $data['color'] ?? null;
        if (empty($color)) {
            $cal = $this->calendarRepository->findById($calId, $userId);
            $color = $cal?->color ?? '#3B82F6';
        }

        $appointment = new AppointmentDTO(
            id: null,
            userId: $userId,
            calendarId: $calId,
            title: $data['title'],
            description: $data['description'] ?? null,
            location: $data['location'] ?? null,
            startAt: $startAt,
            endAt: $endAt,
            allDay: !empty($data['all_day']),
            color: $color,
            createdAt: new \DateTime(),
            updatedAt: new \DateTime(),
        );

        $created = $this->repository->create($appointment);

        return ['success' => true, 'appointment' => $created];
    }

    public function updateAppointment(int $id, array $data, int $userId): array
    {
        $appointment = $this->repository->findById($id, $userId);
        if (!$appointment) {
            return ['success' => false, 'errors' => ['general' => 'Termin nicht gefunden']];
        }

        $errors = $this->validator->validate($data, true);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        $startAt = new \DateTime($data['start_at']);
        $endAt = new \DateTime($data['end_at']);

        if ($endAt <= $startAt) {
            return ['success' => false, 'errors' => ['end_at' => 'Endzeit muss nach Startzeit liegen']];
        }

        $calId = isset($data['calendar_id']) && $data['calendar_id'] !== '' ? (int) $data['calendar_id'] : $appointment->calendarId;
        if ($calId !== null && $this->calendarRepository->findById($calId, $userId) === null) {
            $calId = $appointment->calendarId;
        }

        $color = $data['color'] ?? $appointment->color;

        $updated = new AppointmentDTO(
            id: $appointment->id,
            userId: $appointment->userId,
            calendarId: $calId,
            title: $data['title'],
            description: $data['description'] ?? null,
            location: $data['location'] ?? null,
            startAt: $startAt,
            endAt: $endAt,
            allDay: !empty($data['all_day']),
            color: $color,
            createdAt: $appointment->createdAt,
            updatedAt: new \DateTime(),
        );

        $result = $this->repository->update($updated);

        return $result
            ? ['success' => true, 'appointment' => $updated]
            : ['success' => false, 'errors' => ['general' => 'Aktualisierung fehlgeschlagen']];
    }

    public function moveAppointment(int $id, int $userId, \DateTimeInterface $startAt, \DateTimeInterface $endAt): array
    {
        $appointment = $this->repository->findById($id, $userId);
        if (!$appointment) {
            return ['success' => false, 'errors' => ['general' => 'Termin nicht gefunden']];
        }

        if ($endAt <= $startAt) {
            return ['success' => false, 'errors' => ['end_at' => 'Endzeit muss nach Startzeit liegen']];
        }

        $updated = $appointment->withUpdatedTimes($startAt, $endAt);
        $result = $this->repository->update($updated);

        return $result
            ? ['success' => true, 'appointment' => $updated]
            : ['success' => false, 'errors' => ['general' => 'Verschieben fehlgeschlagen']];
    }

    public function deleteAppointment(int $id, int $userId): bool
    {
        return $this->repository->delete($id, $userId);
    }
}
