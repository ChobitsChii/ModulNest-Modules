<?php

declare(strict_types=1);

namespace ModulNest\Calendar;

use DateTime;
use DateTimeImmutable;
use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Session;
use Modulon\Core\View;
use Modulon\Modules\Auth\AuthService;
use ModulNest\Calendar\DTO\AppointmentDTO;
use ModulNest\Calendar\DTO\CalendarDTO;
use ModulNest\Calendar\Service\CalendarService;
use ModulNest\Calendar\Service\GoogleCalendarService;
use Throwable;

final class CalendarController
{
    private const BASE_PATH = '/calendar';

    public function __construct(
        private readonly CalendarService $service,
        private readonly Session $session,
        private readonly ?AuthService $auth = null,
        private readonly ?GoogleCalendarService $google = null,
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
        $googleConfigured = $this->google?->isConfigured() ?? false;
        $googleAccount = ($userId !== null && $this->google !== null) ? $this->google->getConnectedAccount($userId) : null;

        $data = array_merge([
            'title' => 'Kalender',
            'base_path' => self::BASE_PATH,
            'calendars' => $calendars,
            'google_configured' => $googleConfigured,
            'google_account' => $googleAccount,
            
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
        return $this->month($request);
    }

    public function day(Request $request): Response
    {
        $dateParam = $request->query('date');
        $date = $dateParam !== null && $dateParam !== '' ? new DateTime($dateParam) : new DateTime();

        $userId = $this->userId();
        $appointments = $userId !== null ? $this->service->getAppointmentsForDay($userId, $date) : [];
        $calendars = $userId !== null ? $this->service->getCalendars($userId) : [];

        $prevDay = (clone $date)->modify('-1 day');
        $nextDay = (clone $date)->modify('+1 day');

        $allDayAppointments = array_filter($appointments, static fn (AppointmentDTO $a): bool => $a->allDay);
        $timeAppointments = array_filter($appointments, static fn (AppointmentDTO $a): bool => !$a->allDay);

        return $this->view('day', [
            'current_path' => $request->path(),
            'appointments' => $appointments,
            'allDayAppointments' => $allDayAppointments,
            'timeAppointments' => $timeAppointments,
            'calendars' => $calendars,
            'currentDate' => $date,
            'prevDay' => $prevDay,
            'nextDay' => $nextDay,
            'view' => 'day',
        ]);
    }

    public function week(Request $request): Response
    {
        $dateParam = $request->query('date');
        $date = $dateParam !== null && $dateParam !== '' ? new DateTime($dateParam) : new DateTime();

        $weekStart = clone $date;
        $weekStart->modify('monday this week');
        $weekStart->setTime(0, 0, 0);

        $userId = $this->userId();
        $appointments = $userId !== null ? $this->service->getAppointmentsForWeek($userId, $weekStart) : [];
        $calendars = $userId !== null ? $this->service->getCalendars($userId) : [];

        $prevWeek = (clone $weekStart)->modify('-7 days');
        $nextWeek = (clone $weekStart)->modify('+7 days');

        $days = [];
        $todayStr = (new DateTime())->format('Y-m-d');
        for ($i = 0; $i < 7; $i++) {
            $dayDate = (clone $weekStart)->modify("+{$i} days");
            $dayStr = $dayDate->format('Y-m-d');
            $dayAppointments = [];
            foreach ($appointments as $apt) {
                if ($apt->startAt->format('Y-m-d') === $dayStr) {
                    $dayAppointments[] = $apt;
                }
            }
            $days[] = [
                'date' => $dayDate,
                'isToday' => $dayStr === $todayStr,
                'dateString' => $dayStr,
                'appointments' => $dayAppointments,
            ];
        }

        return $this->view('week', [
            'current_path' => $request->path(),
            'appointments' => $appointments,
            'calendars' => $calendars,
            'currentDate' => $date,
            'weekStart' => $weekStart,
            'days' => $days,
            'prevWeek' => $prevWeek,
            'nextWeek' => $nextWeek,
            'view' => 'week',
        ]);
    }

    public function month(Request $request): Response
    {
        $dateParam = $request->query('date');
        $date = $dateParam !== null && $dateParam !== '' ? new DateTime($dateParam) : new DateTime();

        $firstDayOfMonth = (clone $date)->modify('first day of this month');
        $firstDayOfMonth->setTime(0, 0, 0);

        $lastDayOfMonth = (clone $date)->modify('last day of this month');
        $lastDayOfMonth->setTime(23, 59, 59);

        $gridStart = (clone $firstDayOfMonth)->modify('monday this week');
        $gridStart->setTime(0, 0, 0);

        $gridEnd = (clone $lastDayOfMonth)->modify('sunday this week');
        $gridEnd->setTime(23, 59, 59);

        $userId = $this->userId();
        $appointments = $userId !== null ? $this->service->getAppointmentsForMonth($userId, $date) : [];
        $calendars = $userId !== null ? $this->service->getCalendars($userId) : [];

        $prevMonth = (clone $firstDayOfMonth)->modify('-1 month');
        $nextMonth = (clone $firstDayOfMonth)->modify('+1 month');

        $weeks = [];
        $currentCursor = clone $gridStart;
        $monthNum = (int) $firstDayOfMonth->format('m');
        $todayStr = (new DateTime())->format('Y-m-d');

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
            $this->session->flash('calendar_error', $result['errors']['name'] ?? 'Kalender konnte nicht aktualisiert werden');
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

    // --- Google OAuth & Sync Actions ---

    public function googleConnect(Request $request): Response
    {
        $userId = $this->userId();
        if ($userId === null) {
            return Response::redirect('/login');
        }

        if ($this->google === null || !$this->google->isConfigured()) {
            $this->session->flash('calendar_error', 'Die Google Calendar Integration ist noch nicht konfiguriert.');
            return Response::redirect(self::BASE_PATH);
        }

        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
                  (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $redirectUri = $scheme . '://' . $host . '/calendar/google/callback';

        $state = bin2hex(random_bytes(16));
        $this->session->set('google_oauth_state', $state);

        try {
            $authUrl = $this->google->getAuthUrl($redirectUri, $state);
            return Response::redirect($authUrl);
        } catch (Throwable $e) {
            $this->session->flash('calendar_error', 'Fehler beim Starten der Authentifizierung: ' . $e->getMessage());
            return Response::redirect(self::BASE_PATH);
        }
    }

    public function googleCallback(Request $request): Response
    {
        $userId = $this->userId();
        if ($userId === null) {
            return Response::redirect('/login');
        }

        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');
        $savedState = (string) $this->session->get('google_oauth_state', '');

        if ($code === '' || $state === '' || $savedState === '' || !hash_equals($savedState, $state)) {
            $this->session->flash('calendar_error', 'Ungültige oder abgelaufene OAuth-Anfrage.');
            return Response::redirect(self::BASE_PATH);
        }
        $this->session->remove('google_oauth_state');

        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
                  (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $redirectUri = $scheme . '://' . $host . '/calendar/google/callback';

        try {
            $result = $this->google?->handleCallback($code, $redirectUri, $userId);
            $email = $result['email'] ?? '';
            $this->session->flash('calendar_info', 'Google-Konto (' . htmlspecialchars($email) . ') erfolgreich verbunden! Wähle jetzt deine Kalender aus.');
            return Response::redirect(self::BASE_PATH . '/google/select-calendars');
        } catch (Throwable $e) {
            $this->session->flash('calendar_error', 'Verbindung fehlgeschlagen: ' . $e->getMessage());
            return Response::redirect(self::BASE_PATH);
        }
    }

    public function googleSelectCalendars(Request $request): Response
    {
        $userId = $this->userId();
        if ($userId === null) {
            return Response::redirect('/login');
        }

        $account = $this->google?->getConnectedAccount($userId);
        if ($account === null) {
            $this->session->flash('calendar_error', 'Kein Google-Konto verbunden.');
            return Response::redirect(self::BASE_PATH);
        }

        $googleCalendars = $this->google->fetchGoogleCalendars($userId);

        return new Response(View::render('@modulnest.calendar/google-calendars', [
            'title' => 'Google Kalender auswählen',
            'base_path' => self::BASE_PATH,
            'google_email' => $account['google_email'],
            'google_calendars' => $googleCalendars,
            
        ]));
    }

    public function googleSaveCalendars(Request $request): Response
    {
        $userId = $this->userId();
        if ($userId === null) {
            return Response::redirect('/login');
        }

        $selected = $request->post('selected_calendars');
        $selectedIds = is_array($selected) ? array_values(array_map('strval', $selected)) : [];

        $this->google?->syncGoogleCalendarList($userId, $selectedIds);

        // Initial sync of appointments
        $syncResult = $this->google?->syncEvents($userId, true);

        $msg = 'Google Kalender wurden aktualisiert.';
        if (!empty($syncResult['events_synced'])) {
            $msg .= ' ' . $syncResult['events_synced'] . ' Termine synchronisiert.';
        }
        $this->session->flash('calendar_info', $msg);

        return Response::redirect(self::BASE_PATH);
    }

    public function googleSync(Request $request): Response
    {
        $userId = $this->userId();
        if ($userId === null) {
            return Response::redirect('/login');
        }

        if ($this->google === null) {
            return Response::redirect(self::BASE_PATH);
        }

        $result = $this->google->syncEvents($userId, false);
        if (!empty($result['rate_limited'])) {
            $this->session->flash('calendar_error', $result['message']);
        } elseif ($result['success']) {
            $this->session->flash('calendar_info', $result['message']);
        } else {
            $this->session->flash('calendar_error', $result['message']);
        }

        return Response::redirect(self::BASE_PATH);
    }

    public function googleAutoSync(Request $request): Response
    {
        $userId = $this->userId();
        if ($userId === null || $this->google === null) {
            return $this->json(['synced' => false, 'reason' => 'not_authenticated']);
        }

        $account = $this->google->getConnectedAccount($userId);
        if ($account === null) {
            return $this->json(['synced' => false, 'reason' => 'no_account']);
        }

        // Check if last sync was older than 15 minutes (900 seconds)
        if (!empty($account['last_synced_at'])) {
            try {
                $lastSync = new DateTimeImmutable((string) $account['last_synced_at']);
                $diff = time() - $lastSync->getTimestamp();
                if ($diff < 900) {
                    return $this->json(['synced' => false, 'reason' => 'recently_synced', 'diff_seconds' => $diff]);
                }
            } catch (Throwable) {
            }
        }

        $result = $this->google->syncEvents($userId, true);

        return $this->json([
            'synced' => $result['success'] ?? false,
            'events_synced' => $result['events_synced'] ?? 0,
            'message' => $result['message'] ?? '',
        ]);
    }

    public function googleDisconnect(Request $request): Response
    {
        $userId = $this->userId();
        if ($userId === null) {
            return Response::redirect('/login');
        }

        $this->google?->disconnectAccount($userId);
        $this->session->flash('calendar_info', 'Das Google-Konto wurde getrennt und alle synchronisierten Google-Kalender wurden entfernt.');

        return Response::redirect(self::BASE_PATH);
    }

    // --- Appointment CRUD Actions ---

    public function createForm(Request $request): Response
    {
        $userId = $this->userId();
        if ($userId === null) {
            return Response::redirect('/login');
        }

        $dateParam = $request->query('date');
        $defaultDate = $dateParam !== null && $dateParam !== '' ? $dateParam : (new DateTime())->format('Y-m-d');
        $calendars = $this->service->getCalendars($userId);

        return $this->view('appointment-form', [
            'current_path' => $request->path(),
            'appointment' => null,
            'calendars' => $calendars,
            'defaultDate' => $defaultDate,
            'isEdit' => false,
            'title' => 'Neuer Termin',
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
            $this->session->flash('calendar_info', 'Termin wurde angelegt');
            if ($request->expectsJson()) {
                return $this->json(['success' => true, 'appointment' => $result['appointment']->toJsonArray()], 201);
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
        if ($userId === null) {
            return Response::redirect('/login');
        }

        $id = $this->pathId($request->path());
        if ($id === null) {
            return new Response(View::render('errors/404', ['title' => '404 Not Found', 'current_path' => $request->path()]), 404);
        }

        $appointment = $this->service->getAppointment($id, $userId);
        if (!$appointment) {
            return new Response(View::render('errors/404', ['title' => '404 Not Found', 'current_path' => $request->path()]), 404);
        }

        $calendars = $this->service->getCalendars($userId);

        return $this->view('appointment-form', [
            'current_path' => $request->path(),
            'appointment' => $appointment,
            'calendars' => $calendars,
            'isEdit' => true,
            'title' => 'Termin bearbeiten',
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
            $startAt = new DateTime((string) $payload['start']);
            $endAt = new DateTime((string) $payload['end']);
        } catch (Throwable) {
            return $this->json(['success' => false, 'errors' => ['general' => 'Ungültige Zeitangabe']], 400);
        }

        $result = $this->service->moveAppointment($id, $startAt, $endAt, $userId);

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
            $start = new DateTime($startParam);
            $end = new DateTime($endParam);
        } catch (Throwable) {
            return $this->json(['success' => false, 'errors' => ['general' => 'Ungültige Zeitangabe']], 400);
        }

        $appointments = $this->service->getAppointmentsForRange($userId, $start, $end);

        return $this->json([
            'success' => true,
            'appointments' => array_values(array_map(
                static fn (AppointmentDTO $a): array => $a->toJsonArray(),
                $appointments
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

    // --- Helpers ---

    private function formData(Request $request): array
    {
        $start = $request->input('start_at', '');
        $end = $request->input('end_at', '');

        return [
            'title' => $request->input('title', ''),
            'description' => $request->input('description'),
            'location' => $request->input('location'),
            'start_at' => $start,
            'end_at' => $end,
            'all_day' => !empty($request->input('all_day')),
            'color' => $request->input('color'),
            'calendar_id' => $request->input('calendar_id'),
            'recurrence_freq' => $request->input('recurrence_freq', 'NONE'),
            'recurrence_interval' => (int) $request->input('recurrence_interval', 1),
            'recurrence_until' => $request->input('recurrence_until'),
        ];
    }

    private function pathId(string $path): ?int
    {
        $parts = explode('/', trim($path, '/'));
        foreach ($parts as $part) {
            if (ctype_digit($part)) {
                return (int) $part;
            }
        }
        return null;
    }

    private function jsonBody(Request $request): array
    {
        $content = (string) file_get_contents('php://input');
        if ($content === '') {
            return [];
        }
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function firstError(array $errors): string
    {
        $first = reset($errors);
        return is_string($first) ? $first : 'Ein Fehler ist aufgetreten';
    }
}
