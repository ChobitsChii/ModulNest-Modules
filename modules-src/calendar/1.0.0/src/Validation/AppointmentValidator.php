<?php

declare(strict_types=1);

namespace ModulNest\Calendar\Validation;

final class AppointmentValidator
{
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
            if (mb_strlen($data['description']) > 65535) {
                $errors['description'] = 'Beschreibung ist zu lang';
            }
        }

        if (isset($data['location']) && $data['location'] !== null) {
            if (mb_strlen($data['location']) > 500) {
                $errors['location'] = 'Ort darf maximal 500 Zeichen lang sein';
            }
        }

        if (!$isUpdate || isset($data['start_at'])) {
            if (!isset($data['start_at']) || !$this->isValidDateTime($data['start_at'])) {
                $errors['start_at'] = 'Ungültiges Startdatum';
            }
        }

        if (!$isUpdate || isset($data['end_at'])) {
            if (!isset($data['end_at']) || !$this->isValidDateTime($data['end_at'])) {
                $errors['end_at'] = 'Ungültiges Enddatum';
            }
        }

        if (isset($data['color']) && $data['color'] !== null) {
            if (!$this->isValidHexColor($data['color'])) {
                $errors['color'] = 'Ungültige Farbe (erwartet: #RRGGBB)';
            }
        }

        return $errors;
    }

    private function isValidDateTime(string $value): bool
    {
        // Das HTML-Formular (datetime-local) sendet "Y-m-d\TH:i" ohne Sekunden,
        // daher werden hier auch Minuten- und DateTime-Werte ohne Sekunden akzeptiert.
        foreach (['Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d\TH:i'] as $format) {
            $dt = \DateTime::createFromFormat($format, $value);
            if ($dt !== false && $dt->format($format) === $value) {
                return true;
            }
        }

        return false;
    }

    private function isValidHexColor(string $color): bool
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1;
    }
}