<?php

declare(strict_types=1);

namespace ModulNest\MailClient\Ics;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Robuster RFC 5545 iCalendar (ICS) Parser für Mail-Terminanhänge und Inline-Kalenderdaten.
 */
final class IcsParserService
{
    /**
     * Parst ICS-Rohdaten und gibt ein Array aller enthaltenen Termine zurück.
     *
     * @param string $rawIcs
     * @param string $userTimezone Standard-Zeitzone des Nutzers (z.B. "Europe/Berlin")
     * @return array<int, array{
     *   uid: string,
     *   title: string,
     *   description: string,
     *   location: string,
     *   start_at: string,
     *   end_at: string,
     *   all_day: bool,
     *   recurrence_rule: ?string,
     *   status: string,
     *   formatted_date: string
     * }>
     */
    public function parse(string $rawIcs, string $userTimezone = 'Europe/Berlin'): array
    {
        $rawIcs = trim($rawIcs);
        if ($rawIcs === '') {
            return [];
        }

        // Line Unfolding nach RFC 5545 (Zeilenumbruch gefolgt von Leerzeichen/Tab)
        $unfolded = preg_replace("/\r\n[ \t]|\r[ \t]|\n[ \t]/", '', $rawIcs);
        if (!is_string($unfolded) || !str_contains(strtoupper($unfolded), 'BEGIN:VEVENT')) {
            return [];
        }

        $events = [];
        preg_match_all('/BEGIN:VEVENT([\s\S]*?)END:VEVENT/i', $unfolded, $matches);

        foreach ($matches[1] as $block) {
            $parsed = $this->parseEventBlock($block, $userTimezone);
            if ($parsed !== null) {
                $events[] = $parsed;
            }
        }

        return $events;
    }

    /**
     * Parst einen einzelnen BEGIN:VEVENT...END:VEVENT Block.
     *
     * @return array{
     *   uid: string,
     *   title: string,
     *   description: string,
     *   location: string,
     *   start_at: string,
     *   end_at: string,
     *   all_day: bool,
     *   recurrence_rule: ?string,
     *   status: string,
     *   formatted_date: string
     * }|null
     */
    private function parseEventBlock(string $block, string $userTimezone): ?array
    {
        $lines = preg_split("/\r\n|\r|\n/", trim($block));
        if (!is_array($lines)) {
            return null;
        }

        $uid             = '';
        $summary         = '';
        $description     = '';
        $location        = '';
        $status          = 'CONFIRMED';
        $rrule           = null;
        $dtStartRaw      = '';
        $dtStartProp     = '';
        $dtEndRaw        = '';
        $dtEndProp       = '';
        $durationRaw     = '';
        $allDay          = false;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }

            [$propPart, $valPart] = explode(':', $line, 2);
            $propName = strtoupper(trim(explode(';', $propPart)[0]));

            // Unescaping nach RFC 5545
            $val = $this->unescapeIcsValue($valPart);

            switch ($propName) {
                case 'UID':
                    $uid = $val;
                    break;
                case 'SUMMARY':
                    $summary = $val;
                    break;
                case 'DESCRIPTION':
                    $description = $val;
                    break;
                case 'LOCATION':
                    $location = $val;
                    break;
                case 'STATUS':
                    $status = strtoupper(trim($val));
                    break;
                case 'RRULE':
                    $rrule = trim($valPart); // Unescaped / original RRULE string
                    break;
                case 'DTSTART':
                    $dtStartRaw  = trim($valPart);
                    $dtStartProp = $propPart;
                    if (str_contains(strtoupper($propPart), 'VALUE=DATE') || strlen($dtStartRaw) === 8) {
                        $allDay = true;
                    }
                    break;
                case 'DTEND':
                    $dtEndRaw  = trim($valPart);
                    $dtEndProp = $propPart;
                    break;
                case 'DURATION':
                    $durationRaw = trim($valPart);
                    break;
            }
        }

        if ($dtStartRaw === '') {
            return null;
        }

        $startAt = $this->parseIcsDate($dtStartRaw, $dtStartProp, $userTimezone);
        if ($startAt === null) {
            return null;
        }

        // DTEND oder DURATION berechnen
        $endAt = null;
        if ($dtEndRaw !== '') {
            $endAt = $this->parseIcsDate($dtEndRaw, $dtEndProp, $userTimezone);
        }

        if ($endAt === null && $durationRaw !== '') {
            try {
                $interval = new DateInterval($durationRaw);
                $endAt    = $startAt->add($interval);
            } catch (Throwable) {
                $endAt = null;
            }
        }

        if ($endAt === null) {
            if ($allDay) {
                $endAt = $startAt->setTime(23, 59, 59);
            } else {
                $endAt = $startAt->modify('+1 hour');
            }
        }

        // Wenn Ganztagsevent nach RFC 5545 mit DTEND = Folgetag definiert ist:
        if ($allDay && $endAt > $startAt && $endAt->format('H:i:s') === '00:00:00') {
            $endAt = $endAt->modify('-1 second');
        }

        // Formatiertes deutsches Datum
        $formattedDate = $this->formatGermanDate($startAt, $endAt, $allDay);

        return [
            'uid'             => $uid !== '' ? $uid : ('mc_' . md5($summary . $dtStartRaw . uniqid('', true))),
            'title'           => $summary !== '' ? $summary : 'Termin',
            'description'     => $description,
            'location'        => $location,
            'start_at'        => $startAt->format('Y-m-d H:i:s'),
            'end_at'          => $endAt->format('Y-m-d H:i:s'),
            'all_day'         => $allDay,
            'recurrence_rule' => $rrule !== '' ? $rrule : null,
            'status'          => $status,
            'formatted_date'  => $formattedDate,
        ];
    }

    /**
     * Parst einen ICS-Datumsstring (z.B. 20260925T100000Z oder 20260925) in ein DateTimeImmutable Objekt.
     */
    private function parseIcsDate(string $val, string $prop, string $userTimezone): ?DateTimeImmutable
    {
        $val = trim($val);
        if ($val === '') {
            return null;
        }

        try {
            $targetTz = new DateTimeZone($userTimezone);
        } catch (Throwable) {
            $targetTz = new DateTimeZone('Europe/Berlin');
        }

        // TZID aus Property extrahieren
        $sourceTz = $targetTz;
        if (preg_match('/TZID=([^;:]+)/i', $prop, $m)) {
            $tzName = trim($m[1], '"\' ');
            try {
                $sourceTz = new DateTimeZone($tzName);
            } catch (Throwable) {
                $sourceTz = $targetTz;
            }
        }

        // 1. Ganztagsdatum: YYYYMMDD (z.B. 20260925)
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $val, $m)) {
            try {
                return new DateTimeImmutable("{$m[1]}-{$m[2]}-{$m[3]} 00:00:00", $targetTz);
            } catch (Throwable) {
                return null;
            }
        }

        // 2. UTC Zeit: YYYYMMDDTHHMMSSZ (z.B. 20260925T100000Z)
        if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z$/i', $val, $m)) {
            try {
                $utc = new DateTimeImmutable("{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}", new DateTimeZone('UTC'));
                return $utc->setTimezone($targetTz);
            } catch (Throwable) {
                return null;
            }
        }

        // 3. Lokale Zeit mit/ohne TZID: YYYYMMDDTHHMMSS (z.B. 20260925T100000)
        if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})$/i', $val, $m)) {
            try {
                $dt = new DateTimeImmutable("{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}", $sourceTz);
                return $dt->setTimezone($targetTz);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Formatiert ein Start- und Enddatum auf Deutsch.
     */
    private function formatGermanDate(DateTimeImmutable $start, DateTimeImmutable $end, bool $allDay): string
    {
        $weekdays = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
        $months   = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

        $wDay = $weekdays[(int) $start->format('w')];
        $day  = (int) $start->format('j');
        $m    = $months[(int) $start->format('n')];
        $year = $start->format('Y');

        if ($allDay) {
            $isSameDay = $start->format('Y-m-d') === $end->format('Y-m-d');
            if ($isSameDay) {
                return "{$wDay}, {$day}. {$m} {$year} (Ganztägig)";
            }
            $endWDay = $weekdays[(int) $end->format('w')];
            $endDay  = (int) $end->format('j');
            $endM    = $months[(int) $end->format('n')];
            $endYear = $end->format('Y');
            return "{$wDay}, {$day}. {$m} {$year} – {$endWDay}, {$endDay}. {$endM} {$endYear} (Ganztägig)";
        }

        $isSameDay = $start->format('Y-m-d') === $end->format('Y-m-d');
        $startTime = $start->format('H:i');
        $endTime   = $end->format('H:i');

        if ($isSameDay) {
            return "{$wDay}, {$day}. {$m} {$year}, {$startTime} – {$endTime} Uhr";
        }

        $endWDay = $weekdays[(int) $end->format('w')];
        $endDay  = (int) $end->format('j');
        $endM    = $months[(int) $end->format('n')];
        $endYear = $end->format('Y');

        return "{$wDay}, {$day}. {$m} {$year}, {$startTime} Uhr – {$endWDay}, {$endDay}. {$endM} {$endYear}, {$endTime} Uhr";
    }

    /**
     * Unescapes ICS string values according to RFC 5545.
     */
    private function unescapeIcsValue(string $val): string
    {
        return str_replace(
            ['\\n', '\\N', '\\,', '\\;', '\\\\'],
            ["\n", "\n", ',', ';', '\\'],
            $val
        );
    }
}
