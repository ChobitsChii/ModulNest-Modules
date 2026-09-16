<?php
declare(strict_types=1);

/**
 * Termin-Formular (Neu + Bearbeiten). Fragment im zentralen Layout.
 *
 * @var \ModulNest\Calendar\DTO\AppointmentDTO|null $appointment
 * @var array $calendars
 * @var int|null $selectedCalendarId
 * @var string|null $defaultStart
 * @var string|null $defaultEnd
 * @var string $action
 * @var string $base_path
 * @var string $csrf_token
 * @var string $view
 */

$calBasePath = (string) ($base_path ?? '/calendar');
$calCsrf = (string) ($csrf_token ?? '');
$isEdit = $appointment !== null;
$calTitle = $isEdit ? 'Termin bearbeiten' : 'Neuer Termin';
$calList = is_array($calendars ?? null) ? $calendars : [];

$currentCalId = $isEdit ? $appointment->calendarId : ($selectedCalendarId ?? ($calList[0]->id ?? null));

$defDate = (new \DateTime())->format('Y-m-d');
$startVal = $isEdit ? $appointment->startAt->format('Y-m-d\TH:i') : ($defaultStart ?? ($defDate . 'T09:00'));
$endVal = $isEdit ? $appointment->endAt->format('Y-m-d\TH:i') : ($defaultEnd ?? ($defDate . 'T10:00'));

// Parse recurrence rule if existing
$ruleParts = ($isEdit && !empty($appointment->recurrenceRule))
    ? \ModulNest\Calendar\Service\RecurrenceService::parseRule($appointment->recurrenceRule)
    : [];
$currentFreq = $ruleParts['FREQ'] ?? 'NONE';
$currentInterval = isset($ruleParts['INTERVAL']) ? (int) $ruleParts['INTERVAL'] : 1;
$currentUntil = '';
if (!empty($ruleParts['UNTIL'])) {
    $u = $ruleParts['UNTIL'];
    $currentUntil = strlen($u) === 8
        ? substr($u, 0, 4) . '-' . substr($u, 4, 2) . '-' . substr($u, 6, 2)
        : substr($u, 0, 10);
}

$val = static fn (string $field, string $fallback = ''): string =>
    htmlspecialchars($isEdit ? (string) ($appointment->{$field} ?? '') : $fallback, ENT_QUOTES, 'UTF-8');
?>

<?php require __DIR__ . '/partials/calendar-head.php'; ?>

<div class="row justify-content-center">
    <div class="col-12 col-md-8 col-lg-7">
        <div class="card border shadow-sm p-4">
            <div class="d-flex align-items-center justify-content-between mb-4">
                <h1 class="h3 mb-0"><?= htmlspecialchars($calTitle, ENT_QUOTES, 'UTF-8') ?></h1>
                <?php if ($isEdit && !empty($appointment->googleEventId)): ?>
                    <span class="badge bg-body-secondary text-body border d-inline-flex align-items-center gap-1" title="Mit Google synchronisiert">
                        <i class="bi bi-google text-primary"></i> Mit Google synchronisiert
                    </span>
                <?php endif; ?>
            </div>

            <form method="post" action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>" class="appointment-form">
                <?= \Modulon\Core\View::csrfField($calCsrf) ?>

                <div class="mb-3">
                    <label class="form-label" for="calendar_id">Kalender</label>
                    <select class="form-select" id="calendar_id" name="calendar_id" required>
                        <?php foreach ($calList as $cal): ?>
                            <option value="<?= $cal->id ?>"
                                    data-color="<?= htmlspecialchars($cal->color, ENT_QUOTES, 'UTF-8') ?>"
                                    data-source="<?= htmlspecialchars($cal->source, ENT_QUOTES, 'UTF-8') ?>"
                                    <?= ((int) $cal->id === (int) $currentCalId) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cal->name, ENT_QUOTES, 'UTF-8') ?>
                                <?= ($cal->source === 'google') ? ' (Google)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="title">Titel</label>
                    <input type="text" class="form-control" id="title" name="title" maxlength="255" required
                           value="<?= $val('title') ?>" placeholder="z. B. Meeting, Zahnarzt, Urlaub">
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="start_at">Start</label>
                        <input type="datetime-local" class="form-control" id="start_at" name="start_at" required
                               value="<?= htmlspecialchars($startVal, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="end_at">Ende</label>
                        <input type="datetime-local" class="form-control" id="end_at" name="end_at" required
                               value="<?= htmlspecialchars($endVal, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="all_day" name="all_day" value="1"
                           <?= $isEdit && $appointment->allDay ? 'checked' : '' ?>>
                    <label class="form-check-label" for="all_day">Ganztägig</label>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="location">Ort</label>
                    <input type="text" class="form-control" id="location" name="location" maxlength="500"
                           value="<?= $val('location') ?>" placeholder="z. B. Raum 3, Büro, Online">
                </div>

                <div class="mb-3">
                    <label class="form-label" for="description">Beschreibung</label>
                    <textarea class="form-control" id="description" name="description" rows="3" placeholder="Zusätzliche Notizen..."><?= $val('description') ?></textarea>
                </div>

                <!-- Wiederkehrende Termine -->
                <div class="card bg-body-tertiary border-0 p-3 mb-3">
                    <div class="mb-2">
                        <label class="form-label fw-semibold" for="recurrence_freq">
                            <i class="bi bi-arrow-repeat me-1 text-primary"></i> Wiederholung
                        </label>
                        <select class="form-select" id="recurrence_freq" name="recurrence_freq">
                            <option value="NONE" <?= $currentFreq === 'NONE' ? 'selected' : '' ?>>Nicht wiederholen</option>
                            <option value="DAILY" <?= $currentFreq === 'DAILY' ? 'selected' : '' ?>>Täglich</option>
                            <option value="WEEKDAYS" <?= $currentFreq === 'WEEKDAYS' ? 'selected' : '' ?>>Jeden Werktag (Mo–Fr)</option>
                            <option value="WEEKLY" <?= $currentFreq === 'WEEKLY' ? 'selected' : '' ?>>Wöchentlich</option>
                            <option value="MONTHLY" <?= $currentFreq === 'MONTHLY' ? 'selected' : '' ?>>Monatlich</option>
                            <option value="YEARLY" <?= $currentFreq === 'YEARLY' ? 'selected' : '' ?>>Jährlich</option>
                        </select>
                    </div>
                    <div id="recurrence_options" class="row g-2 mt-1 <?= $currentFreq === 'NONE' ? 'd-none' : '' ?>">
                        <div class="col-sm-6">
                            <label class="form-label small" for="recurrence_interval">Wiederholen alle</label>
                            <div class="input-group input-group-sm">
                                <input type="number" class="form-control" id="recurrence_interval" name="recurrence_interval" min="1" max="99" value="<?= $currentInterval ?>">
                                <span class="input-group-text" id="interval_label">Einheiten</span>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small" for="recurrence_until">Endet am (optional)</label>
                            <input type="date" class="form-control form-control-sm" id="recurrence_until" name="recurrence_until" value="<?= htmlspecialchars($currentUntil, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label" for="color">Terminfarbe</label>
                    <div class="d-flex align-items-center gap-2">
                        <input type="color" class="form-control form-control-color" id="color" name="color"
                               value="<?= $isEdit ? htmlspecialchars($appointment->color ?? '#3B82F6', ENT_QUOTES, 'UTF-8') : '#3B82F6' ?>">
                        <span class="text-body-secondary small">Standardmäßig die Farbe des Kalenders, kann individuell angepasst werden.</span>
                    </div>
                </div>

                <div class="d-flex align-items-center justify-content-between">
                    <div class="d-flex gap-2">
                        <a class="btn btn-outline-secondary" href="<?= $calBasePath ?>/month">Abbrechen</a>
                        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Speichern' : 'Termin erstellen' ?></button>
                    </div>
                </div>
            </form>

            <?php if ($isEdit): ?>
                <hr class="my-4">
                <form method="post" action="<?= $calBasePath ?>/appointment/<?= $appointment->id ?>/delete"
                      onsubmit="return confirm('Möchtest du diesen Termin wirklich unwiderruflich löschen?');">
                    <?= \Modulon\Core\View::csrfField($calCsrf) ?>
                    <button type="submit" class="btn btn-outline-danger btn-sm">
                        <i class="bi bi-trash me-1"></i> Termin löschen
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const freqSelect = document.getElementById('recurrence_freq');
    const optionsDiv = document.getElementById('recurrence_options');
    const intervalLabel = document.getElementById('interval_label');

    const updateRecurrenceUI = () => {
        const val = freqSelect ? freqSelect.value : 'NONE';
        if (val === 'NONE') {
            optionsDiv?.classList.add('d-none');
        } else {
            optionsDiv?.classList.remove('d-none');
            if (intervalLabel) {
                switch (val) {
                    case 'DAILY':
                    case 'WEEKDAYS':
                        intervalLabel.textContent = 'Tage';
                        break;
                    case 'WEEKLY':
                        intervalLabel.textContent = 'Wochen';
                        break;
                    case 'MONTHLY':
                        intervalLabel.textContent = 'Monate';
                        break;
                    case 'YEARLY':
                        intervalLabel.textContent = 'Jahre';
                        break;
                    default:
                        intervalLabel.textContent = 'Einheiten';
                }
            }
        }
    };

    if (freqSelect) {
        freqSelect.addEventListener('change', updateRecurrenceUI);
        updateRecurrenceUI();
    }
});
</script>
