<?php

declare(strict_types=1);

namespace ModulNest\Calendar\Service;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Modulon\Modules\Admin\AppSettingRepository;
use ModulNest\Calendar\DTO\AppointmentDTO;
use ModulNest\Calendar\DTO\CalendarDTO;
use ModulNest\Calendar\Repository\AppointmentRepository;
use ModulNest\Calendar\Repository\CalendarRepository;
use ModulNest\Calendar\Repository\GoogleAccountRepository;
use RuntimeException;
use Throwable;

final class GoogleCalendarService
{
    private const OAUTH_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const USERINFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';
    private const CALENDAR_API_BASE = 'https://www.googleapis.com/calendar/v3';

    private const SCOPES = 'https://www.googleapis.com/auth/calendar https://www.googleapis.com/auth/userinfo.email';

    public function __construct(
        private readonly AppSettingRepository $settings,
        private readonly GoogleAccountRepository $googleAccounts,
        private readonly CalendarRepository $calendars,
        private readonly AppointmentRepository $appointments,
    ) {
    }

    public function isConfigured(): bool
    {
        $enabled = $this->settings->getBool('calendar.google_enabled', false);
        $clientId = trim((string) ($this->settings->get('calendar.google_client_id') ?? ''));
        $clientSecret = trim((string) ($this->settings->get('calendar.google_client_secret') ?? ''));

        return $enabled && $clientId !== '' && $clientSecret !== '';
    }

    public function clientId(): string
    {
        return trim((string) ($this->settings->get('calendar.google_client_id') ?? ''));
    }

    public function clientSecret(): string
    {
        return trim((string) ($this->settings->get('calendar.google_client_secret') ?? ''));
    }

    public function isAccountConnected(int $userId): bool
    {
        return $this->googleAccounts->findByUserId($userId) !== null;
    }

    public function getConnectedAccount(int $userId): ?array
    {
        return $this->googleAccounts->findByUserId($userId);
    }

    public function getAuthUrl(string $redirectUri, string $state): string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Google Calendar Integration ist nicht konfiguriert.');
        }

        $params = [
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ];

        return self::OAUTH_AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * @return array{access_token: string, refresh_token: ?string, expires_in: int, email: string}
     */
    public function handleCallback(string $code, string $redirectUri, int $userId): array
    {
        $tokenData = $this->exchangeCode($code, $redirectUri);
        $accessToken = (string) ($tokenData['access_token'] ?? '');
        $refreshToken = isset($tokenData['refresh_token']) && (string) $tokenData['refresh_token'] !== ''
            ? (string) $tokenData['refresh_token']
            : null;
        $expiresIn = (int) ($tokenData['expires_in'] ?? 3600);
        $expiresAt = (new DateTimeImmutable())->modify("+{$expiresIn} seconds");

        $email = $this->fetchUserEmail($accessToken);

        $this->googleAccounts->save(
            $userId,
            $email,
            $accessToken,
            $refreshToken,
            $expiresAt,
            (string) ($tokenData['scope'] ?? self::SCOPES)
        );

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $expiresIn,
            'email' => $email,
        ];
    }

    public function disconnectAccount(int $userId): void
    {
        $allCalendars = $this->calendars->findByUserId($userId);
        foreach ($allCalendars as $cal) {
            if ($cal->source === 'google' && $cal->id !== null) {
                $this->calendars->delete($cal->id, $userId);
            }
        }

        $this->googleAccounts->deleteByUserId($userId);
    }

    /**
     * @return list<array{id: string, summary: string, description: string, color: string, primary: bool, selected: bool}>
     */
    public function fetchGoogleCalendars(int $userId): array
    {
        $accessToken = $this->ensureValidAccessToken($userId);
        if ($accessToken === null) {
            return [];
        }

        $url = self::CALENDAR_API_BASE . '/users/me/calendarList';
        $response = $this->httpRequest('GET', $url, [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ]);

        if (($response['status'] ?? 0) !== 200) {
            return [];
        }

        $data = json_decode((string) ($response['body'] ?? '{}'), true);
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];

        // Fetch currently imported Google calendars in local DB
        $localCalendars = $this->calendars->findByUserId($userId);
        $localExternalIds = [];
        foreach ($localCalendars as $cal) {
            if ($cal->source === 'google' && $cal->externalId !== null) {
                $localExternalIds[$cal->externalId] = true;
            }
        }

        $result = [];
        foreach ($items as $item) {
            $extId = (string) ($item['id'] ?? '');
            if ($extId === '') {
                continue;
            }

            $summary = (string) ($item['summary'] ?? 'Google Kalender');
            $description = (string) ($item['description'] ?? '');
            $color = (string) ($item['backgroundColor'] ?? '#3B82F6');
            $isPrimary = !empty($item['primary']);
            $isSelected = isset($localExternalIds[$extId]);

            $result[] = [
                'id' => $extId,
                'summary' => $summary,
                'description' => $description,
                'color' => $color,
                'primary' => $isPrimary,
                'selected' => $isSelected,
            ];
        }

        return $result;
    }

    /**
     * Updates which Google calendars are imported for the user.
     *
     * @param list<string> $selectedExternalIds
     * @return list<CalendarDTO>
     */
    public function syncGoogleCalendarList(int $userId, array $selectedExternalIds): array
    {
        $googleCals = $this->fetchGoogleCalendars($userId);
        $googleMap = [];
        foreach ($googleCals as $gCal) {
            $googleMap[$gCal['id']] = $gCal;
        }

        $localCals = $this->calendars->findByUserId($userId);
        $localMap = [];
        foreach ($localCals as $lCal) {
            if ($lCal->source === 'google' && $lCal->externalId !== null) {
                $localMap[$lCal->externalId] = $lCal;
            }
        }

        // 1. Remove unchecked Google calendars
        foreach ($localMap as $extId => $lCal) {
            if (!in_array($extId, $selectedExternalIds, true) && $lCal->id !== null) {
                $this->calendars->delete($lCal->id, $userId);
            }
        }

        // 2. Add or update selected Google calendars
        $saved = [];
        foreach ($selectedExternalIds as $extId) {
            $gCal = $googleMap[$extId] ?? null;
            if ($gCal === null) {
                continue;
            }

            if (isset($localMap[$extId])) {
                // Already imported
                $saved[] = $localMap[$extId];
            } else {
                // Create new Google calendar in local DB
                $newCal = new CalendarDTO(
                    id: null,
                    userId: $userId,
                    name: $gCal['summary'],
                    color: $gCal['color'],
                    isVisible: true,
                    isDefault: false,
                    source: 'google',
                    externalId: $extId,
                );
                $saved[] = $this->calendars->create($newCal);
            }
        }

        return $saved;
    }

    /**
     * Performs a sync of events for the specified date range.
     *
     * @return array{success: bool, message: string, events_synced: int, rate_limited?: bool}
     */
    public function syncEvents(
        int $userId,
        bool $force = false,
        ?DateTimeInterface $start = null,
        ?DateTimeInterface $end = null
    ): array {
        $account = $this->googleAccounts->findByUserId($userId);
        if ($account === null) {
            return ['success' => false, 'message' => 'Kein Google-Konto verbunden.', 'events_synced' => 0];
        }

        // Rate limit: minimum 60s between manual syncs
        if (!$force && !empty($account['last_synced_at'])) {
            try {
                $lastSync = new DateTimeImmutable((string) $account['last_synced_at']);
                $diff = (new DateTimeImmutable())->getTimestamp() - $lastSync->getTimestamp();
                if ($diff < 60) {
                    $retryIn = 60 - $diff;
                    return [
                        'success' => false,
                        'rate_limited' => true,
                        'message' => "Kalender wurden erst vor kurzem synchronisiert. Nächster Abruf in {$retryIn} Sekunden möglich.",
                        'events_synced' => 0,
                    ];
                }
            } catch (Throwable) {
                // Proceed on date parse failure
            }
        }

        $accessToken = $this->ensureValidAccessToken($userId);
        if ($accessToken === null) {
            return ['success' => false, 'message' => 'Google-Zugriffstoken ungültig oder abgelaufen.', 'events_synced' => 0];
        }

        // Rolling 3-month window around requested dates
        $rangeStart = $start !== null
            ? DateTimeImmutable::createFromInterface($start)
            : (new DateTimeImmutable('first day of this month'))->modify('-1 month')->setTime(0, 0, 0);

        $rangeEnd = $end !== null
            ? DateTimeImmutable::createFromInterface($end)
            : (new DateTimeImmutable('last day of this month'))->modify('+2 months')->setTime(23, 59, 59);

        $allCalendars = $this->calendars->findByUserId($userId);
        $googleCalendars = array_filter($allCalendars, static fn (CalendarDTO $c): bool => $c->source === 'google' && !empty($c->externalId));

        $totalSynced = 0;

        foreach ($googleCalendars as $cal) {
            $extId = (string) $cal->externalId;
            $calId = (int) $cal->id;

            $params = [
                'singleEvents' => 'true',
                'timeMin' => $rangeStart->format(DateTimeInterface::RFC3339),
                'timeMax' => $rangeEnd->format(DateTimeInterface::RFC3339),
                'maxResults' => '250',
            ];

            $url = self::CALENDAR_API_BASE . '/calendars/' . urlencode($extId) . '/events?' . http_build_query($params);
            $response = $this->httpRequest('GET', $url, [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
            ]);

            if (($response['status'] ?? 0) !== 200) {
                continue;
            }

            $body = json_decode((string) ($response['body'] ?? '{}'), true);
            $items = is_array($body['items'] ?? null) ? $body['items'] : [];

            foreach ($items as $item) {
                $status = (string) ($item['status'] ?? 'confirmed');
                $gEventId = (string) ($item['id'] ?? '');
                if ($gEventId === '') {
                    continue;
                }

                $existing = $this->appointments->findByGoogleEventId($userId, $gEventId);

                if ($status === 'cancelled') {
                    if ($existing !== null && $existing->id !== null) {
                        $this->appointments->delete($existing->id, $userId);
                    }
                    continue;
                }

                $title = trim((string) ($item['summary'] ?? '(Ohne Titel)'));
                $description = isset($item['description']) && (string) $item['description'] !== ''
                    ? (string) $item['description']
                    : null;
                $location = isset($item['location']) && (string) $item['location'] !== ''
                    ? (string) $item['location']
                    : null;
                $etag = isset($item['etag']) && (string) $item['etag'] !== ''
                    ? (string) $item['etag']
                    : null;

                // Dates
                $isAllDay = !empty($item['start']['date']);
                try {
                    if ($isAllDay) {
                        $startAt = new DateTime((string) $item['start']['date'] . ' 00:00:00');
                        $endAt = new DateTime((string) ($item['end']['date'] ?? $item['start']['date']) . ' 23:59:59');
                    } else {
                        $startAt = new DateTime((string) ($item['start']['dateTime'] ?? 'now'));
                        $endAt = new DateTime((string) ($item['end']['dateTime'] ?? 'now'));
                    }
                } catch (Throwable) {
                    continue;
                }

                $recurrenceRule = null;
                if (!empty($item['recurringEventId'])) {
                    $recurrenceRule = 'RECURRENCE_INSTANCE';
                }

                if ($existing !== null) {
                    // Update existing
                    $updated = new AppointmentDTO(
                        id: $existing->id,
                        userId: $userId,
                        calendarId: $calId,
                        title: $title,
                        description: $description,
                        location: $location,
                        startAt: $startAt,
                        endAt: $endAt,
                        allDay: $isAllDay,
                        color: $cal->color,
                        createdAt: $existing->createdAt,
                        updatedAt: new DateTime(),
                        recurrenceRule: $recurrenceRule,
                        recurrenceParentId: $existing->recurrenceParentId,
                        googleEventId: $gEventId,
                        googleEtag: $etag,
                    );
                    $this->appointments->update($updated);
                } else {
                    // Create new
                    $newApt = new AppointmentDTO(
                        id: null,
                        userId: $userId,
                        calendarId: $calId,
                        title: $title,
                        description: $description,
                        location: $location,
                        startAt: $startAt,
                        endAt: $endAt,
                        allDay: $isAllDay,
                        color: $cal->color,
                        createdAt: new DateTime(),
                        updatedAt: new DateTime(),
                        recurrenceRule: $recurrenceRule,
                        recurrenceParentId: null,
                        googleEventId: $gEventId,
                        googleEtag: $etag,
                    );
                    $this->appointments->create($newApt);
                }

                $totalSynced++;
            }

            $this->calendars->touchLastSynced($calId);
        }

        $this->googleAccounts->touchLastSynced($userId);

        return [
            'success' => true,
            'message' => "Synchronisation erfolgreich abgeschlossen ({$totalSynced} Termine synchronisiert).",
            'events_synced' => $totalSynced,
        ];
    }

    /**
     * Outbound sync: pushes a created appointment to Google Calendar.
     */
    public function createEventOnGoogle(int $userId, int $calendarId, AppointmentDTO $appointment): ?string
    {
        $cal = $this->calendars->findById($calendarId, $userId);
        if ($cal === null || $cal->source !== 'google' || empty($cal->externalId)) {
            return null;
        }

        $accessToken = $this->ensureValidAccessToken($userId);
        if ($accessToken === null) {
            return null;
        }

        $payload = $this->formatEventPayload($appointment);

        $url = self::CALENDAR_API_BASE . '/calendars/' . urlencode((string) $cal->externalId) . '/events';
        $response = $this->httpRequest('POST', $url, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ], json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if (($response['status'] ?? 0) === 200 || ($response['status'] ?? 0) === 201) {
            $data = json_decode((string) ($response['body'] ?? '{}'), true);
            return isset($data['id']) ? (string) $data['id'] : null;
        }

        return null;
    }

    /**
     * Outbound sync: pushes an updated appointment to Google Calendar.
     */
    public function updateEventOnGoogle(int $userId, int $calendarId, AppointmentDTO $appointment): bool
    {
        if (empty($appointment->googleEventId)) {
            return false;
        }

        $cal = $this->calendars->findById($calendarId, $userId);
        if ($cal === null || $cal->source !== 'google' || empty($cal->externalId)) {
            return false;
        }

        $accessToken = $this->ensureValidAccessToken($userId);
        if ($accessToken === null) {
            return false;
        }

        $payload = $this->formatEventPayload($appointment);

        $url = self::CALENDAR_API_BASE . '/calendars/' . urlencode((string) $cal->externalId) . '/events/' . urlencode((string) $appointment->googleEventId);
        $response = $this->httpRequest('PATCH', $url, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ], json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return ($response['status'] ?? 0) === 200;
    }

    /**
     * Outbound sync: deletes an appointment from Google Calendar.
     */
    public function deleteEventOnGoogle(int $userId, int $calendarId, string $googleEventId): bool
    {
        $cal = $this->calendars->findById($calendarId, $userId);
        if ($cal === null || $cal->source !== 'google' || empty($cal->externalId)) {
            return false;
        }

        $accessToken = $this->ensureValidAccessToken($userId);
        if ($accessToken === null) {
            return false;
        }

        $url = self::CALENDAR_API_BASE . '/calendars/' . urlencode((string) $cal->externalId) . '/events/' . urlencode($googleEventId);
        $response = $this->httpRequest('DELETE', $url, [
            'Authorization: Bearer ' . $accessToken,
        ]);

        return in_array($response['status'] ?? 0, [200, 204, 404, 410], true);
    }

    private function formatEventPayload(AppointmentDTO $appointment): array
    {
        $payload = [
            'summary' => $appointment->title,
            'description' => $appointment->description ?? '',
            'location' => $appointment->location ?? '',
        ];

        if ($appointment->allDay) {
            $payload['start'] = ['date' => $appointment->startAt->format('Y-m-d')];
            $payload['end'] = ['date' => $appointment->endAt->format('Y-m-d')];
        } else {
            $payload['start'] = ['dateTime' => $appointment->startAt->format(DateTimeInterface::RFC3339)];
            $payload['end'] = ['dateTime' => $appointment->endAt->format(DateTimeInterface::RFC3339)];
        }

        if ($appointment->recurrenceRule !== null && $appointment->recurrenceRule !== '' && $appointment->recurrenceRule !== 'RECURRENCE_INSTANCE') {
            $rrule = $appointment->recurrenceRule;
            if (!str_starts_with($rrule, 'RRULE:')) {
                $rrule = 'RRULE:' . $rrule;
            }
            $payload['recurrence'] = [$rrule];
        }

        return $payload;
    }

    private function ensureValidAccessToken(int $userId): ?string
    {
        $account = $this->googleAccounts->findByUserId($userId);
        if ($account === null) {
            return null;
        }

        $accessToken = $account['access_token'];
        $expiresAtStr = $account['token_expires_at'];

        $isExpired = false;
        if ($expiresAtStr !== null) {
            try {
                $expiresAt = new DateTimeImmutable($expiresAtStr);
                // Refresh if expiring within next 5 minutes
                if ((new DateTimeImmutable())->modify('+300 seconds') >= $expiresAt) {
                    $isExpired = true;
                }
            } catch (Throwable) {
                $isExpired = true;
            }
        }

        if (!$isExpired) {
            return $accessToken;
        }

        $refreshToken = $account['refresh_token'];
        if ($refreshToken === null) {
            return null;
        }

        // Exchange refresh token
        try {
            $refreshData = $this->refreshAccessToken($refreshToken);
            $newAccessToken = (string) ($refreshData['access_token'] ?? '');
            if ($newAccessToken === '') {
                return null;
            }

            $expiresIn = (int) ($refreshData['expires_in'] ?? 3600);
            $newExpiresAt = (new DateTimeImmutable())->modify("+{$expiresIn} seconds");

            $this->googleAccounts->updateAccessToken($userId, $newAccessToken, $newExpiresAt);

            return $newAccessToken;
        } catch (Throwable) {
            return null;
        }
    }

    private function exchangeCode(string $code, string $redirectUri): array
    {
        $body = http_build_query([
            'code' => $code,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        $response = $this->httpRequest('POST', self::OAUTH_TOKEN_URL, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ], $body);

        if (($response['status'] ?? 0) !== 200) {
            $msg = 'Token-Austausch mit Google fehlgeschlagen: ' . ($response['body'] ?? 'HTTP ' . ($response['status'] ?? 0));
            throw new RuntimeException($msg);
        }

        $data = json_decode((string) ($response['body'] ?? '{}'), true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new RuntimeException('Ungültige Token-Antwort von Google.');
        }

        return $data;
    }

    private function refreshAccessToken(string $refreshToken): array
    {
        $body = http_build_query([
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'grant_type' => 'refresh_token',
        ]);

        $response = $this->httpRequest('POST', self::OAUTH_TOKEN_URL, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ], $body);

        if (($response['status'] ?? 0) !== 200) {
            throw new RuntimeException('Token-Aktualisierung mit Google fehlgeschlagen.');
        }

        return (array) json_decode((string) ($response['body'] ?? '{}'), true);
    }

    private function fetchUserEmail(string $accessToken): string
    {
        $response = $this->httpRequest('GET', self::USERINFO_URL, [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ]);

        if (($response['status'] ?? 0) === 200) {
            $data = json_decode((string) ($response['body'] ?? '{}'), true);
            return (string) ($data['email'] ?? '');
        }

        return '';
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body: string}
     */
    private function httpRequest(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_USERAGENT, 'ModulNest-Calendar/1.1');

            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            $responseBody = curl_exec($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return [
                'status' => $statusCode,
                'body' => is_string($responseBody) ? $responseBody : '',
            ];
        }

        // Fallback to stream context
        $opts = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => 15,
                'ignore_errors' => true,
                'user_agent' => 'ModulNest-Calendar/1.1',
            ],
        ];

        $context = stream_context_create($opts);
        $responseBody = @file_get_contents($url, false, $context);

        $status = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $hdr) {
                if (preg_match('/^HTTP\/\d\.\d\s+(\d+)/', $hdr, $m)) {
                    $status = (int) $m[1];
                    break;
                }
            }
        }

        return [
            'status' => $status,
            'body' => is_string($responseBody) ? $responseBody : '',
        ];
    }
}
