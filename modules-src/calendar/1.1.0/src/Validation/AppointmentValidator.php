<?php

declare(strict_types=1);

namespace ModulNest\Calendar\Validation;

final class AppointmentValidator
{
    private const ALLOWED_FREQS = ['NONE', 'DAILY', 'WEEKDAYS', 'WEEKLY', 'MONTHLY', 'YEARLY'];

    public function validate(array $data, bool $isUpdate = false): array
    {
        $errors = [];

        if (!$isUpdate || isset($data['title'])) {
            $title = trim($data['title'] ?? '');
            if ($title === '') {
                $errors['title'] = 'Titel ist erforderlich';
            } elseif (mb_strlen($title) > 255) {
                $errors['title'] = 'Titel darf maximal 255 Zeichen lang sein';
            }
        }

        if (isset($data['description']) && $data['description'] !== null) {
            if (mb_strlen((string) $data['description']) > 65535) {
                $errors['description'] = 'Beschreibung ist zu lang';
            }
        }

        if (isset($data['location']) && $data['location'] !== null) {
            if (mb_strlen((string) $data['location']) > 500) {
                $errors['location'] = 'Ort darf maximal 500 Zeichen lang sein';
            }
        }

        if (!$isUpdate || isset($data['start_at'])) {
            if (!isset($data['start_at']) || !$this->isValidDateTime((string) $data['start_at'])) {
                $errors['start_at'] = 'Ungültiges Startdatum';
            }
        }

        if (!$isUpdate || isset($data['end_at'])) {
            if (!isset($data['end_at']) || !$this->isValidDateTime((string) $data['end_at'])) {
                $errors['end_at'] = 'Ungültiges Enddatum';
            }
        }

        if (isset($data['color']) && $data['color'] !== null && $data['color'] !== '') {
            if (!$this->isValidHexColor((string) $data['color'])) {
                $errors['color'] = 'Ungültige Farbe (erwartet: #RRGGBB)';
            }
        }

        if (isset($data['recurrence_freq']) && $data['recurrence_freq'] !== '') {
            $freq = strtoupper(trim((string) $data['recurrence_freq']));
            if (!in_array($freq, self::ALLOWED_FREQS, true)) {
                $errors['recurrence_freq'] = 'Ungültige Wiederholungsfrequenz';
            }
        }

        return $errors;
    }

    private function isValidDateTime(string $value): bool
    {
        try {
            new \DateTime($value);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function isValidHexColor(string $value): bool
    {
        return (bool) preg_match('/^#[a-fA-F0-9]{6}$/', $value);
    }
}
