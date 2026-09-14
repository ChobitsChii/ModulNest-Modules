<?php
declare(strict_types=1);

/**
 * Termin-Formular (Neu + Bearbeiten). Fragment im zentralen Layout.
 *
 * @var \ModulNest\Calendar\DTO\AppointmentDTO|null $appointment
 * @var array $calendars
 * @var int|null $selectedCalendarId
 * @var string $defaultStart
 * @var string $defaultEnd
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

$val = static fn (string $field, string $fallback = ''): string =>
    htmlspecialchars($isEdit ? (string) ($appointment->{$field} ?? '') : $fallback, ENT_QUOTES, 'UTF-8');
?>

<?php require __DIR__ . '/partials/calendar-head.php'; ?>

<div class="row justify-content-center">
    <div class="col-12 col-md-8 col-lg-7">
        <div class="card border shadow-sm p-4">
            <h1 class="h3 mb-4"><?= htmlspecialchars($calTitle, ENT_QUOTES, 'UTF-8') ?></h1>

            <form method="post" action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>" class="appointment-form">
                <?= \Modulon\Core\View::csrfField($calCsrf) ?>

                <div class="mb-3">
                    <label class="form-label" for="calendar_id">Kalender</label>
                    <select class="form-select" id="calendar_id" name="calendar_id" required>
                        <?php foreach ($calList as $cal): ?>
                            <option value="<?= $cal->id ?>"
                                    data-color="<?= htmlspecialchars($cal->color, ENT_QUOTES, 'UTF-8') ?>"
                                    <?= ((int) $cal->id === (int) $currentCalId) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cal->name, ENT_QUOTES, 'UTF-8') ?>
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
                               value="<?= htmlspecialchars($defaultStart, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="end_at">Ende</label>
                        <input type="datetime-local" class="form-control" id="end_at" name="end_at" required
                               value="<?= htmlspecialchars($defaultEnd, ENT_QUOTES, 'UTF-8') ?>">
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
    const calSelect = document.getElementById('calendar_id');
    const colorInput = document.getElementById('color');
    if (calSelect && colorInput && !<?= $isEdit ? 'true' : 'false' ?>) {
        const updateColor = () => {
            const opt = calSelect.options[calSelect.selectedIndex];
            if (opt && opt.dataset.color) {
                colorInput.value = opt.dataset.color;
            }
        };
        calSelect.addEventListener('change', updateColor);
        updateColor();
    }
});
</script>
