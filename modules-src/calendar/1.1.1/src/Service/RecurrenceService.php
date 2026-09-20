<?php

declare(strict_types=1);

namespace ModulNest\Calendar\Service;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use ModulNest\Calendar\DTO\AppointmentDTO;

final class RecurrenceService
{
    /**
     * Parses an RRULE string into key-value components.
     * Example: "FREQ=WEEKLY;INTERVAL=2;UNTIL=2026-12-31"
     *
     * @return array<string, string>
     */
    public static function parseRule(?string $rule): array
    {
        if ($rule === null || trim($rule) === '') {
            return [];
        }

        $rule = trim($rule);
        if (str_starts_with($rule, 'RRULE:')) {
            $rule = substr($rule, 6);
        }

        $parts = explode(';', $rule);
        $result = [];
        foreach ($parts as $part) {
            $pair = explode('=', $part, 2);
            if (count($pair) === 2) {
                $result[strtoupper(trim($pair[0]))] = trim($pair[1]);
            }
        }

        return $result;
    }

    /**
     * Builds an RRULE string from parameters.
     */
    public static function buildRule(string $freq, int $interval = 1, ?string $until = null): string
    {
        $freq = strtoupper(trim($freq));
        if ($freq === '' || $freq === 'NONE') {
            return '';
        }

        $interval = max(1, $interval);
        $rule = "FREQ={$freq};INTERVAL={$interval}";

        if ($until !== null && trim($until) !== '') {
            $cleanUntil = str_replace(['-', ':', ' '], '', trim($until));
            $rule .= ";UNTIL={$cleanUntil}";
        }

        return $rule;
    }

    /**
     * Expands recurring appointments into individual occurrences within the target window.
     *
     * @param AppointmentDTO[] $appointments
     * @return AppointmentDTO[]
     */
    public function expandOccurrences(
        array $appointments,
        DateTimeInterface $windowStart,
        DateTimeInterface $windowEnd
    ): array {
        $result = [];
        $wStart = DateTimeImmutable::createFromInterface($windowStart);
        $wEnd = DateTimeImmutable::createFromInterface($windowEnd);

        foreach ($appointments as $apt) {
            $ruleStr = $apt->recurrenceRule;

            // If not recurring or it's a child instance, add as is if within window
            if ($ruleStr === null || $ruleStr === '' || $apt->recurrenceParentId !== null) {
                if ($apt->startAt < $wEnd && $apt->endAt > $wStart) {
                    $result[] = $apt;
                }
                continue;
            }

            $rule = self::parseRule($ruleStr);
            $freq = $rule['FREQ'] ?? '';
            $interval = isset($rule['INTERVAL']) ? max(1, (int) $rule['INTERVAL']) : 1;
            $until = null;
            if (isset($rule['UNTIL'])) {
                try {
                    $untilStr = $rule['UNTIL'];
                    $until = new DateTimeImmutable(
                        strlen($untilStr) === 8
                            ? substr($untilStr, 0, 4) . '-' . substr($untilStr, 4, 2) . '-' . substr($untilStr, 6, 2) . ' 23:59:59'
                            : $untilStr
                    );
                } catch (\Throwable) {
                    $until = null;
                }
            }

            // Duration of the event
            $originalStart = DateTimeImmutable::createFromInterface($apt->startAt);
            $originalEnd = DateTimeImmutable::createFromInterface($apt->endAt);
            $durationSeconds = max(0, $originalEnd->getTimestamp() - $originalStart->getTimestamp());

            // Loop occurrences
            $currentStart = $originalStart;
            $safetyCounter = 0;
            $maxOccurrences = 500;

            while ($safetyCounter < $maxOccurrences) {
                $safetyCounter++;

                if ($until !== null && $currentStart > $until) {
                    break;
                }

                if ($currentStart > $wEnd) {
                    break;
                }

                $currentEnd = $currentStart->modify("+{$durationSeconds} seconds");

                // Check if this occurrence intersects with window
                if ($currentStart < $wEnd && $currentEnd > $wStart) {
                    if ($currentStart == $originalStart) {
                        $result[] = $apt;
                    } else {
                        // Create virtual instance
                        $result[] = new AppointmentDTO(
                            id: $apt->id,
                            userId: $apt->userId,
                            calendarId: $apt->calendarId,
                            title: $apt->title,
                            description: $apt->description,
                            location: $apt->location,
                            startAt: new DateTime($currentStart->format('Y-m-d H:i:s')),
                            endAt: new DateTime($currentEnd->format('Y-m-d H:i:s')),
                            allDay: $apt->allDay,
                            color: $apt->color,
                            createdAt: $apt->createdAt,
                            updatedAt: $apt->updatedAt,
                            recurrenceRule: $apt->recurrenceRule,
                            recurrenceParentId: $apt->id,
                            googleEventId: $apt->googleEventId,
                            googleEtag: $apt->googleEtag,
                        );
                    }
                }

                // Advance according to frequency
                $currentStart = match ($freq) {
                    'DAILY' => $currentStart->modify("+{$interval} days"),
                    'WEEKDAYS' => $this->nextWeekday($currentStart),
                    'WEEKLY' => $currentStart->modify("+{$interval} weeks"),
                    'MONTHLY' => $currentStart->modify("+{$interval} months"),
                    'YEARLY' => $currentStart->modify("+{$interval} years"),
                    default => null,
                };

                if ($currentStart === null) {
                    break;
                }
            }
        }

        // Sort by allDay DESC, startAt ASC
        usort($result, static function (AppointmentDTO $a, AppointmentDTO $b): int {
            if ($a->allDay !== $b->allDay) {
                return $a->allDay ? -1 : 1;
            }
            return $a->startAt <=> $b->startAt;
        });

        return $result;
    }

    private function nextWeekday(DateTimeImmutable $date): DateTimeImmutable
    {
        $next = $date->modify('+1 day');
        $dayOfWeek = (int) $next->format('N'); // 1 = Mon, 7 = Sun
        if ($dayOfWeek === 6) { // Saturday -> Monday
            return $next->modify('+2 days');
        }
        if ($dayOfWeek === 7) { // Sunday -> Monday
            return $next->modify('+1 day');
        }
        return $next;
    }
}
