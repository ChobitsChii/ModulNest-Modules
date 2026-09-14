<?php
declare(strict_types=1);

/**
 * Partial: Sidebar mit Kalenderliste, Farbpunkten, Sichtbarkeits-Checkboxen und Modal.
 *
 * @var array $calendars
 * @var string $base_path
 * @var string $current_path
 * @var string $csrf_token
 */

$calList = is_array($calendars ?? null) ? $calendars : [];
$calBasePath = (string) ($base_path ?? '/calendar');
$calCsrf = (string) ($csrf_token ?? '');
$calCurrentUrl = (string) ($_SERVER['REQUEST_URI'] ?? $calBasePath);

$presetColors = [
    '#3B82F6' => 'Blau',
    '#10B981' => 'Grün',
    '#F59E0B' => 'Gelb/Amber',
    '#EF4444' => 'Rot',
    '#8B5CF6' => 'Lila',
    '#EC4899' => 'Pink',
    '#06B6D4' => 'Türkis',
    '#64748B' => 'Schiefergrau',
];
?>

<div class="calendar-sidebar card border shadow-sm p-3 mb-3">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h6 class="fw-bold mb-0 text-body">
            <i class="bi bi-calendar3 me-1 text-primary"></i> Meine Kalender
        </h6>
        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#createCalendarModal" title="Neuen Kalender anlegen">
            <i class="bi bi-plus-lg"></i>
        </button>
    </div>

    <div class="calendar-list d-flex flex-column gap-2">
        <?php if (empty($calList)): ?>
            <p class="text-body-secondary small mb-0">Keine Kalender vorhanden.</p>
        <?php endif; ?>

        <?php foreach ($calList as $cal): ?>
            <?php
            $cId = (int) $cal->id;
            $cName = (string) $cal->name;
            $cColor = (string) $cal->color;
            $cVisible = (bool) $cal->isVisible;
            $cIsDefault = (bool) $cal->isDefault;
            $cSource = (string) $cal->source;
            ?>
            <div class="calendar-item d-flex align-items-center justify-content-between p-2 rounded" style="background: color-mix(in srgb, <?= htmlspecialchars($cColor, ENT_QUOTES, 'UTF-8') ?> 8%, var(--app-surface, #fff));">
                <label class="d-flex align-items-center gap-2 mb-0 flex-grow-1 cursor-pointer" style="user-select: none;">
                    <input type="checkbox"
                           class="form-check-input mt-0 calendar-visibility-toggle"
                           data-cal-id="<?= $cId ?>"
                           data-csrf="<?= htmlspecialchars($calCsrf, ENT_QUOTES, 'UTF-8') ?>"
                           data-toggle-url="<?= $calBasePath ?>/toggle-calendar"
                           style="background-color: <?= $cVisible ? htmlspecialchars($cColor, ENT_QUOTES, 'UTF-8') : '' ?>; border-color: <?= htmlspecialchars($cColor, ENT_QUOTES, 'UTF-8') ?>;"
                           <?= $cVisible ? 'checked' : '' ?>>
                    <span class="calendar-color-dot" style="background-color: <?= htmlspecialchars($cColor, ENT_QUOTES, 'UTF-8') ?>;"></span>
                    <span class="fw-semibold small text-truncate" title="<?= htmlspecialchars($cName, ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($cName, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <?php if ($cSource === 'google'): ?>
                        <span class="badge bg-light text-dark border small" style="font-size: 0.65rem;">Google</span>
                    <?php endif; ?>
                </label>
                <div class="dropdown">
                    <button class="btn btn-sm btn-link text-body-secondary p-0 px-1" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-three-dots-vertical"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li>
                            <button type="button" class="dropdown-item small" data-bs-toggle="modal" data-bs-target="#editCalendarModal<?= $cId ?>">
                                <i class="bi bi-pencil me-2 text-primary"></i> Bearbeiten
                            </button>
                        </li>
                        <?php if (count($calList) > 1): ?>
                            <li><hr class="dropdown-divider my-1"></li>
                            <li>
                                <form method="post" action="<?= $calBasePath ?>/calendars/delete" onsubmit="return confirm('Möchtest du diesen Kalender und alle darin enthaltenen Termine wirklich löschen?');">
                                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($calCsrf, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="id" value="<?= $cId ?>">
                                    <input type="hidden" name="return_to" value="<?= htmlspecialchars($calCurrentUrl, ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="dropdown-item small text-danger">
                                        <i class="bi bi-trash me-2"></i> Löschen
                                    </button>
                                </form>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <!-- Edit Modal für jeden Kalender -->
            <div class="modal fade" id="editCalendarModal<?= $cId ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <form method="post" action="<?= $calBasePath ?>/calendars/update">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($calCsrf, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="id" value="<?= $cId ?>">
                            <input type="hidden" name="return_to" value="<?= htmlspecialchars($calCurrentUrl, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="modal-header">
                                <h5 class="modal-title">Kalender bearbeiten</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label">Name des Kalenders</label>
                                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($cName, ENT_QUOTES, 'UTF-8') ?>" required maxlength="100">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Farbe</label>
                                    <div class="d-flex flex-wrap gap-2 mb-2">
                                        <?php foreach ($presetColors as $hex => $label): ?>
                                            <label class="calendar-color-picker-label" title="<?= $label ?>">
                                                <input type="radio" name="color" value="<?= $hex ?>" <?= strtolower($cColor) === strtolower($hex) ? 'checked' : '' ?>>
                                                <span class="color-swatch" style="background-color: <?= $hex ?>;"></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="input-group">
                                        <span class="input-group-text">Eigene Farbe</span>
                                        <input type="color" class="form-control form-control-color" value="<?= htmlspecialchars($cColor, ENT_QUOTES, 'UTF-8') ?>" onchange="this.form.elements['color'].value = this.value;">
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                                <button type="submit" class="btn btn-primary">Speichern</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Modal: Neuer Kalender anlegen -->
<div class="modal fade" id="createCalendarModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="<?= $calBasePath ?>/calendars/create">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($calCsrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="return_to" value="<?= htmlspecialchars($calCurrentUrl, ENT_QUOTES, 'UTF-8') ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-calendar-plus me-1 text-primary"></i> Neuer Kalender</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Name des Kalenders</label>
                        <input type="text" name="name" class="form-control" placeholder="z. B. Arbeit, Privat, Sport" required maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Farbe wählen</label>
                        <div class="d-flex flex-wrap gap-2 mb-2">
                            <?php $first = true; foreach ($presetColors as $hex => $label): ?>
                                <label class="calendar-color-picker-label" title="<?= $label ?>">
                                    <input type="radio" name="color" value="<?= $hex ?>" <?= $first ? 'checked' : '' ?>>
                                    <span class="color-swatch" style="background-color: <?= $hex ?>;"></span>
                                </label>
                            <?php $first = false; endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Kalender erstellen</button>
                </div>
            </form>
        </div>
    </div>
</div>
