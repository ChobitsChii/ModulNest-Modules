<?php

declare(strict_types=1);

namespace ModulNest\Calendar;

use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Session;
use Modulon\Core\View;
use Modulon\Modules\Auth\AuthService;
use ModulNest\Calendar\DTO\AppointmentDTO;
use ModulNest\Calendar\DTO\CalendarDTO;
use ModulNest\Calendar\Service\CalendarService;

final class CalendarController
{
    private const BASE_PATH = '/calendar';

    public function __construct(
        private readonly CalendarService $service,
        private readonly Session $session,
        private readonly ?AuthService $auth = null,
    ) {
    }

    private function userId(): ?int
    {
        $user = $this->auth?->currentUser();
        if (!is_array($user)) {
            return null;
        }

        return (int) ($user['id'] ?? 0);
    }

    private function view(string $template, array $data = []): Response
    {
        $userId = $this->userId();
        $calendars = $userId !== null ? $this->service->getCalendars($userId) : [];

        $data = array_merge([
            'title' => 'Kalender',
            'base_path' => self::BASE_PATH,
            'calendars' => $calendars,
            'calendar_info' => $this->session->pullFlash('calendar_info'),
            'calendar_error' => $this->session->pullFlash('calendar_error'),
        ], $data);

        return new Response(View::render('@modulnest.calendar/' . $template, $data));
    }

    private function json(array $data, int $status = 200): Response
    {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new Response(
            is_string($payload) ? $payload : '{}',
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }

    public function index(Request $request): Response
    {
        return Response::redirect(self::BASE_PATH . '/month');
    }

    public function day(Request $request): Response
    {
        $dateParam = $request->query('date');
        $date = $dateParam !== null && $dateParam !== '' ? new \DateTime($dateParam) : new \DateTime();
        $date->setTime(0, 0, 0);

        $userId = $this->userId();
        $appointments = $userId !== null ? $this->service->getAppointmentsForDay($userId, $date) : [];
        $calendars = $userId !== null ? $this->service->getCalendars($userId) : [];

        $prevDay = (clone $date)->modify('-1 day');
        $nextDay = (clone $date)->modify('+1 day');

        return $this->view('day', [
            'current_path' => $request->path(),
            'appointments' => $appointments,
            'calendars' => $calendars,
            'currentDate' => $date,
            'prevDate' => $prevDay,
            'nextDate' => $nextDay,
            'view' => 'day',
        ]);
    }

    public function week(Request $request): Response
    {
        $dateParam = $request->query('date');
        $date = $dateParam !== null && $dateParam !== '' ? new \DateTime($dateParam) : new \DateTime();

        $weekStart = (clone $date)->modify('monday this week');
        $weekStart->setTime(0, 0, 0);

        $userId = $this->userId();
        $appointments = $userId !== null ? $this->service->getAppointmentsForWeek($userId, $weekStart) : [];
        $calendars = $userId !== null ? $this->service->getCalendars($userId) : [];

        $prevWeek = (clone $weekStart)->modify('-1 week');
        $nextWeek = (clone $weekStart)->modify('+1 week');

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $days[] = (clone $weekStart)->modify("+{$i} days");
        }

        $weekLabel = $weekStart->format('d.m.Y') . ' – ' . (clone $weekStart)->modify('+6 days')->format('d.m.Y');

        return $this->view('week', [
            'current_path' => $request->path(),
            'appointments' => $appointments,
            'calendars' => $calendars,
            'weekStart' => $weekStart,
            'weekLabel' => $weekLabel,
            'days' => $days,
            'prevWeek' => $prevWeek,
            'nextWeek' => $nextWeek,
            'view' => 'week',
        ]);
    }

    public function month(Request $request): Response
    {
        $dateParam = $request->query('date');
        $date = $dateParam !== null && $dateParam !== '' ? new \DateTime($dateParam) : new \DateTime();

        $firstDayOfMonth = (clone $date)->modify('first day of this month');
        $firstDayOfMonth->setTime(0, 0, 0);

        $lastDayOfMonth = (clone $date)->modify('last day of this month');
        $lastDayOfMonth->setTime(23, 59, 59);

        // Start grid on Monday of the first week
        $gridStart = (clone $firstDayOfMonth)->modify('monday this week');
        $gridStart->setTime(0, 0, 0);

        // End grid on Sunday of the last week
        $gridEnd = (clone $lastDayOfMonth)->modify('sunday this week');
        $gridEnd->setTime(23, 59, 59);

        $userId = $this->userId();
        $appointments = $userId !== null ? $this->service->getAppointmentsForMonth($userId, $date) : [];
        $calendars = $userId !== null ? $this->service->getCalendars($userId) : [];

        $prevMonth = (clone $firstDayOfMonth)->modify('-1 month');
        $nextMonth = (clone $firstDayOfMonth)->modify('+1 month');

        // Build weeks and days array
        $weeks = [];
        $currentCursor = clone $gridStart;
        $monthNum = (int) $firstDayOfMonth->format('m');
        $todayStr = (new \DateTime())->format('Y-m-d');

        while ($currentCursor <= $gridEnd) {
            $weekDays = [];
            for ($i = 0; $i < 7; $i++) {
                $dayStr = $currentCursor->format('Y-m-d');
                $dayAppointments = [];
                foreach ($appointments as $apt) {
                    $aptDay = $apt->startAt->format('Y-m-d');
                    if ($aptDay === $dayStr) {
                        $dayAppointments[] = $apt;
                    }
                }

                $weekDays[] = [
                    'date' => clone $currentCursor,
                    'dayNumber' => (int) $currentCursor->format('j'),
                    'isCurrentMonth' => ((int) $currentCursor->format('m')) === $monthNum,
                    'isToday' => $dayStr === $todayStr,
                    'dateString' => $dayStr,
                    'appointments' => $dayAppointments,
                ];
                $currentCursor->modify('+1 day');
            }
            $weeks[] = $weekDays;
        }

        $germanMonths = [
            1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April',
            5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember'
        ];
        $monthLabel = $germanMonths[(int) $firstDayOfMonth->format('n')] . ' ' . $firstDayOfMonth->format('Y');

        return $this->view('month', [
            'current_path' => $request->path(),
            'appointments' => $appointments,
            'calendars' => $calendars,
            'currentDate' => $date,
            'firstDayOfMonth' => $firstDayOfMonth,
            'monthLabel' => $monthLabel,
            'weeks' => $weeks,
            'prevMonth' => $prevMonth,
            'nextMonth' => $nextMonth,
            'view' => 'month',
        ]);
    }

    public function toggleCalendar(Request $request): Response
    {
        $userId = $this->userId();
        if ($userId === null) {
            return $this->json(['success' => false, 'message' => 'Nicht angemeldet'], 401);
        }

        $calendarId = (int) $request->input('id');
        $success = $this->service->toggleCalendarVisibility($calendarId, $userId);

        if ($request->expectsJson()) {
            return $this->json(['success' => $success]);
        }

        $returnTo = (string) $request->input('return_to', self::BASE_PATH);
        return Response::redirect($returnTo);
    }

    public function createCalendar(Request $request): Response
    {
        $userId = $this->userId();
        if ($userId === null) {
            return Response::redirect('/login');
        }

        $data = [
            'name' => (string) $request->input('name', ''),
            'color' => (string) $request->input('color', '#3B82F6'),
        ];

        $result = $this->service->createCalendar($data, $userId);

        if ($result['success']) {
            $this->session->flash('calendar_info', 'Kalender "' . htmlspecialchars($data['name']) . '" wurde erstellt');
        } else {
            $this->session->flash('calendar_error', $result['errors']['name'] ?? 'Kalender konnte nicht erstellt werden');
        }

        $returnTo = (string) $request->input('return_to', self::BASE_PATH);
        return Response::redirect($returnTo);
    }

    public function updateCalendar(Request $request): Response
    {
        $userId = $this->userId();
        if ($userId === null) {
            return Response::redirect('/login');
        }

        $id = (int) $request->input('id');
        $data = [
            'name' => (string) $request->input('name', ''),
            'color' => (string) $request->input('color', '#3B82F6'),
        ];

        $result = $this->service->updateCalendar($id, $data, $userId);

        if ($result['success']) {
            $this->session->flash('calendar_info', 'Kalender wurde aktualisiert');
        } else {
            $this->session->flash('calendar_error', $result['errors']['name'] ?? 'Aktualisierung fehlgeschlagen');
        }

        $returnTo = (string) $request->input('return_to', self::BASE_PATH);
        return Response::redirect($returnTo);
    }

    public function deleteCalendar(Request $request): Response
    {
        $userId = $this->userId();
        if ($userId === null) {
            return Response::redirect('/login');
        }

        $id = (int) $request->input('id');
        $result = $this->service->deleteCalendar($id, $userId);

        if ($result['success']) {
            $this->session->flash('calendar_info', 'Kalender wurde gelöscht');
        } else {
            $this->session->flash('calendar_error', $result['errors']['general'] ?? 'Kalender konnte nicht gelöscht werden');
        }

        $returnTo = (string) $request->input('return_to', self::BASE_PATH);
        return Response::redirect($returnTo);
    }

    public function createForm(Request $request): Response
    {
        $dateParam = $request->query('date');
        $timeParam = $request->query('time');
        $calParam = $request->query('calendar_id');

        $startAt = new \DateTime();
        if ($dateParam !== null && $dateParam !== '') {
            $startAt = new \DateTime($dateParam);
        }
        if ($timeParam !== null && $timeParam !== '' && preg_match('/^(\d{2}):(\d{2})$/', $timeParam, $m) === 1) {
            $startAt->setTime((int) $m[1], (int) $m[2]);
        } else {
            $minutes = (int) $startAt->format('i');
            $roundedMinutes = $minutes < 30 ? 30 : 0;
            if ($roundedMinutes === 0) {
                $startAt->modify('+1 hour');
            }
            $startAt->setTime((int) $startAt->format('H'), $roundedMinutes);
        }

        $endAt = (clone $startAt)->modify('+1 hour');

        $userId = $this->userId();
        $calendars = $userId !== null ? $this->service->getCalendars($userId) : [];

        return $this->view('appointment-form', [
            'current_path' => $request->path(),
            'appointment' => null,
            'calendars' => $calendars,
            'selectedCalendarId' => $calParam !== null ? (int) $calParam : null,
            'defaultStart' => $startAt->format('Y-m-d\TH:i'),
            'defaultEnd' => $endAt->format('Y-m-d\TH:i'),
            'action' => self::BASE_PATH . '/appointment/create',
        ]);
    }

    public function create(Request $request): Response
    {
        $userId = $this->userId();
        if ($userId === null) {
            return Response::redirect('/login');
        }

        $data = $this->formData($request);
        $result = $this->service->createAppointment($data, $userId);

        if ($result['success']) {
            $this->session->flash('calendar_info', 'Termin wurde erstellt');
            if ($request->expectsJson()) {
                return $this->json(['success' => true, 'appointment' => $result['appointment']->toJsonArray()]);
            }

            return Response::redirect(self::BASE_PATH . '/month?date=' . $result['appointment']->startAt->format('Y-m-d'));
        }

        if ($request->expectsJson()) {
            return $this->json(['success' => false, 'errors' => $result['errors']], 422);
        }

        $this->session->flash('calendar_error', $this->firstError($result['errors']));
        return Response::redirect(self::BASE_PATH . '/appointment/create');
    }

    public function appointmentForm(Request $request): Response
    {
        $userId = $this->userId();
        $id = $this->pathId($request->path());
        if ($userId === null || $id === null) {
            return Response::redirect('/login');
        }

        $appointment = $this->service->getAppointment($id, $userId);
        if (!$appointment) {
            $this->session->flash('calendar_error', 'Termin nicht gefunden');
            return Response::redirect(self::BASE_PATH . '/month');
        }

        $calendars = $this->service->getCalendars($userId);

        return $this->view('appointment-form', [
            'current_path' => $request->path(),
            'appointment' => $appointment,
            'calendars' => $calendars,
            'selectedCalendarId' => $appointment->calendarId,
            'defaultStart' => $appointment->startAt->format('Y-m-d\TH:i'),
            'defaultEnd' => $appointment->endAt->format('Y-m-d\TH:i'),
            'action' => self::BASE_PATH . "/appointment/{$id}/edit",
        ]);
    }

    public function appointmentPost(Request $request): Response
    {
        $userId = $this->userId();
        if ($userId === null) {
            return Response::redirect('/login');
        }

        $path = rtrim($request->path(), '/');
        $segments = array_values(array_filter(explode('/', $path)));

        if (count($segments) < 4 || $segments[0] !== 'calendar' || $segments[1] !== 'appointment') {
            return new Response(View::render('errors/404', ['title' => '404 Not Found', 'current_path' => $request->path()]), 404);
        }

        $id = (int) $segments[2];
        $action = $segments[3] ?? '';

        if ($action === 'delete') {
            return $this->delete($request, $id, $userId);
        }

        if ($action === 'edit') {
            return $this->update($request, $id, $userId);
        }

        return new Response(View::render('errors/404', ['title' => '404 Not Found', 'current_path' => $request->path()]), 404);
    }

    private function update(Request $request, int $id, int $userId): Response
    {
        $data = $this->formData($request);
        $result = $this->service->updateAppointment($id, $data, $userId);

        if ($result['success']) {
            $this->session->flash('calendar_info', 'Termin wurde aktualisiert');
            if ($request->expectsJson()) {
                return $this->json(['success' => true, 'appointment' => $result['appointment']->toJsonArray()]);
            }

            return Response::redirect(self::BASE_PATH . '/month?date=' . $result['appointment']->startAt->format('Y-m-d'));
        }

        if ($request->expectsJson()) {
            return $this->json(['success' => false, 'errors' => $result['errors']], 422);
        }

        $this->session->flash('calendar_error', $this->firstError($result['errors']));
        return Response::redirect(self::BASE_PATH . "/appointment/{$id}/edit");
    }

    private function delete(Request $request, int $id, int $userId): Response
    {
        $result = $this->service->deleteAppointment($id, $userId);

        if ($request->expectsJson()) {
            return $this->json(['success' => $result], $result ? 200 : 400);
        }

        $this->session->flash($result ? 'calendar_info' : 'calendar_error', $result ? 'Termin wurde gelöscht' : 'Löschen fehlgeschlagen');

        return Response::redirect(self::BASE_PATH . '/month');
    }

    public function appointmentMove(Request $request): Response
    {
        $userId = $this->userId();
        $id = $this->pathId($request->path());
        if ($userId === null || $id === null) {
            return $this->json(['success' => false, 'errors' => ['general' => 'Nicht autorisiert']], 401);
        }

        $payload = $this->jsonBody($request);
        if (!isset($payload['start'], $payload['end'])) {
            return $this->json(['success' => false, 'errors' => ['general' => 'Start und End erforderlich']], 400);
        }

        try {
            $startAt = new \DateTime((string) $payload['start']);
            $endAt = new \DateTime((string) $payload['end']);
        } catch (\Throwable) {
            return $this->json(['success' => false, 'errors' => ['general' => 'Ungültige Zeitangabe']], 400);
        }

        $result = $this->service->moveAppointment($id, $userId, $startAt, $endAt);

        return $this->json($result, $result['success'] ? 200 : 400);
    }

    public function apiAppointments(Request $request): Response
    {
        $userId = $this->userId();
        if ($userId === null) {
            return $this->json(['success' => false, 'errors' => ['general' => 'Nicht autorisiert']], 401);
        }

        $startParam = $request->query('start');
        $endParam = $request->query('end');

        if ($startParam === null || $endParam === null || $startParam === '' || $endParam === '') {
            return $this->json(['success' => false, 'errors' => ['general' => 'Start und End erforderlich']], 400);
        }

        try {
            $start = new \DateTime($startParam);
            $end = new \DateTime($endParam);
        } catch (\Throwable) {
            return $this->json(['success' => false, 'errors' => ['general' => 'Ungültige Zeitangabe']], 400);
        }

        $appointments = $this->service->getAppointmentsForDay($userId, $start);
        $filtered = array_filter($appointments, static fn (AppointmentDTO $a): bool =>
            $a->startAt < $end && $a->endAt > $start
        );

        return $this->json([
            'success' => true,
            'appointments' => array_values(array_map(
                static fn (AppointmentDTO $a): array => $a->toJsonArray(),
                $filtered
            )),
        ]);
    }

    public function apiAppointment(Request $request): Response
    {
        $userId = $this->userId();
        $id = $this->pathId($request->path());
        if ($userId === null || $id === null) {
            return $this->json(['success' => false, 'errors' => ['general' => 'Nicht autorisiert']], 401);
        }

        $appointment = $this->service->getAppointment($id, $userId);
        if (!$appointment) {
            return $this->json(['success' => false, 'errors' => ['general' => 'Termin nicht gefunden']], 404);
        }

        return $this->json(['success' => true, 'appointment' => $appointment->toJsonArray()]);
    }

    private function formData(Request $request): array
    {
        return [
            'calendar_id' => $request->input('calendar_id'),
            'title' => (string) $request->input('title', ''),
            'description' => $request->input('description'),
            'location' => $request->input('location'),
            'start_at' => (string) $request->input('start_at', ''),
            'end_at' => (string) $request->input('end_at', ''),
            'all_day' => (string) $request->input('all_day', ''),
            'color' => (string) $request->input('color', ''),
        ];
    }

    private function firstError(array $errors): string
    {
        foreach ($errors as $error) {
            if (is_string($error)) {
                return $error;
            }
        }

        return 'Ein Fehler ist aufgetreten';
    }

    private function pathId(string $path): ?int
    {
        if (preg_match('#/calendar/(?:api/)?appointment/(\d+)#', $path, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    private function jsonBody(Request $request): array
    {
        $raw = (string) file_get_contents('php://input');
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }
}
