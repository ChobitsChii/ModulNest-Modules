<?php
declare(strict_types=1);

/**
 * Partial: Sidebar mit Kalenderliste, Farbpunkten, Sichtbarkeits-Checkboxen und Google OAuth Sync.
 *
 * @var array $calendars
 * @var string $base_path
 * @var string $current_path
 * @var string $csrf_token
 * @var bool $google_configured
 * @var array|null $google_account
 */

$calList = is_array($calendars ?? null) ? $calendars : [];
$calBasePath = (string) ($base_path ?? '/calendar');
$calCsrf = (string) ($csrf_token ?? '');
$calCurrentUrl = (string) ($_SERVER['REQUEST_URI'] ?? $calBasePath);
$googleConfigured = (bool) ($google_configured ?? false);
$googleAccount = is_array($google_account ?? null) ? $google_account : null;

$lastSyncLabel = 'Noch nie';
if ($googleAccount !== null && !empty($googleAccount['last_synced_at'])) {
    try {
        $syncTime = new DateTimeImmutable((string) $googleAccount['last_synced_at']);
        $diff = time() - $syncTime->getTimestamp();
        if ($diff < 60) {
            $lastSyncLabel = 'gerade eben';
        } elseif ($diff < 3600) {
            $mins = max(1, (int) floor($diff / 60));
            $lastSyncLabel = "vor {$mins} Minute" . ($mins > 1 ? 'n' : '');
        } elseif ($diff < 86400) {
            $hours = (int) floor($diff / 3600);
            $lastSyncLabel = "vor {$hours} Stunde" . ($hours > 1 ? 'n' : '');
        } else {
            $lastSyncLabel = $syncTime->format('d.m.Y H:i');
        }
    } catch (\Throwable) {
        $lastSyncLabel = (string) $googleAccount['last_synced_at'];
    }
}

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
                        <span class="badge bg-body-secondary text-body border small d-inline-flex align-items-center gap-1" style="font-size: 0.65rem;" title="Mit Google synchronisiert">
                            <i class="bi bi-google text-primary"></i> Google
                        </span>
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
            <div class="modal fade" id=\"editCalendarModal<?= $cId ?>\" tabindex="-1" aria-hidden="true">
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

    <?php if ($googleConfigured): ?>
        <?php if ($googleAccount !== null): ?>
            <div class="google-sync-card mt-3 pt-3 border-top">
                <div class="d-flex align-items-center justify-content-between mb-1">
                    <span class="small fw-bold text-body d-flex align-items-center gap-1">
                        <i class="bi bi-google text-primary"></i> Google-Konto
                    </span>
                    <a href="<?= $calBasePath ?>/google/select-calendars" class="small text-decoration-none" title="Kalender verwalten">
                        <i class="bi bi-sliders me-1"></i>Verwalten
                    </a>
                </div>
                <p class="text-body-secondary small text-truncate mb-2" title="<?= htmlspecialchars($googleAccount['google_email'], ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($googleAccount['google_email'], ENT_QUOTES, 'UTF-8') ?>
                </p>
                <div class="d-flex align-items-center justify-content-between text-body-secondary small mb-2" style="font-size: 0.75rem;">
                    <span>Sync: <strong class="text-body"><?= htmlspecialchars($lastSyncLabel, ENT_QUOTES, 'UTF-8') ?></strong></span>
                </div>
                <div class="d-flex gap-2">
                    <form method="post" action="<?= $calBasePath ?>/google/sync" class="flex-grow-1">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($calCsrf, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn btn-sm btn-outline-secondary w-100 d-flex align-items-center justify-content-center gap-1" title="Jetzt mit Google synchronisieren">
                            <i class="bi bi-arrow-repeat"></i> Jetzt synchronisieren
                        </button>
                    </form>
                    <form method="post" action="<?= $calBasePath ?>/google/disconnect" onsubmit="return confirm('Möchtest du das Google-Konto wirklich trennen? Synchronisierte Google-Kalender werden dabei entfernt.');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($calCsrf, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Google-Konto trennen">
                            <i class="bi bi-box-arrow-right"></i>
                        </button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="google-connect-card mt-3 pt-3 border-top">
                <div class="d-flex align-items-center gap-1 mb-1">
                    <i class="bi bi-google text-primary"></i>
                    <span class="small fw-semibold text-body">Google Calendar</span>
                </div>
                <p class="text-body-secondary small mb-2" style="font-size: 0.75rem;">
                    Verbinde dein Google-Konto zur bidirektionalen Synchronisation deiner Termine.
                </p>
                <a href="<?= $calBasePath ?>/google/connect" class="btn btn-outline-primary btn-sm w-100 d-flex align-items-center justify-content-center gap-2">
                    <i class="bi bi-google"></i> Google verbinden
                </a>
            </div>
        <?php endif; ?>
    <?php endif; ?>
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
