<?php

declare(strict_types=1);

namespace ModulNest\Calendar;

use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Session;
use Modulon\Core\View;
use Modulon\Modules\Admin\AppSettingRepository;

final class CalendarAdminController
{
    public function __construct(
        private readonly AppSettingRepository $settings,
        private readonly Session $session,
    ) {
    }

    public function index(Request $request): Response
    {
        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
                  (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $redirectUri = $scheme . '://' . $host . '/calendar/google/callback';

        return new Response(View::render('@modulnest.calendar/admin', [
            'title' => 'Kalender Administration & Google OAuth',
            'admin_section' => 'calendar',
            'enabled' => $this->settings->getBool('calendar.google_enabled', false),
            'client_id' => (string) ($this->settings->get('calendar.google_client_id') ?? ''),
            'client_secret' => (string) ($this->settings->get('calendar.google_client_secret') ?? ''),
            'redirect_uri' => $redirectUri,
            'message' => $this->session->pullFlash('admin_calendar_success'),
            'error' => $this->session->pullFlash('admin_calendar_error'),
        ]));
    }

    public function saveSettings(Request $request): Response
    {
        $enabled = !empty($request->post('google_enabled'));
        $clientId = trim((string) $request->post('google_client_id', ''));
        $clientSecret = trim((string) $request->post('google_client_secret', ''));

        $this->settings->setBool('calendar.google_enabled', $enabled);
        $this->settings->set('calendar.google_client_id', $clientId);
        $this->settings->set('calendar.google_client_secret', $clientSecret);

        $this->session->setFlash('admin_calendar_success', 'Die Einstellungen für die Google Calendar Integration wurden erfolgreich gespeichert.');

        return Response::redirect('/admin/calendar');
    }
}
