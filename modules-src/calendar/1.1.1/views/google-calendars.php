<?php
declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$googleCalendars = is_array($google_calendars ?? null) ? $google_calendars : [];
$googleEmail = (string) ($google_email ?? '');
$csrfToken = (string) ($csrf_token ?? '');
?>

<div class="row justify-content-center">
    <div class="col-12 col-md-8 col-lg-6">
        <div class="card shadow-sm border-0 app-card">
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="rounded-circle bg-primary bg-opacity-10 p-3 text-primary d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                        <i class="bi bi-google fs-4"></i>
                    </div>
                    <div>
                        <h1 class="h4 mb-0">Google Kalender auswählen</h1>
                        <p class="text-body-secondary small mb-0">Verbunden als: <strong><?= $e($googleEmail) ?></strong></p>
                    </div>
                </div>

                <p class="text-body-secondary small mb-4">
                    Wähle nach dem Vorbild von Thunderbird aus, welche Kalender deines Google-Kontos in ModulNest eingebunden werden sollen. Ausgewählte Kalender werden als separate Kalender angezeigt und synchronisiert.
                </p>

                <?php if (empty($googleCalendars)): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-1"></i> Keine Kalender in deinem Google-Konto gefunden oder Zugriff verweigert.
                    </div>
                    <div class="text-end">
                        <a href="/calendar" class="btn btn-outline-secondary">Zurück zum Kalender</a>
                    </div>
                <?php else: ?>
                    <form method="post" action="/calendar/google/select-calendars">
                        <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">

                        <div class="list-group mb-4">
                            <?php foreach ($googleCalendars as $gCal): ?>
                                <?php
                                $gId = (string) ($gCal['id'] ?? '');
                                $gSummary = (string) ($gCal['summary'] ?? '');
                                $gDesc = (string) ($gCal['description'] ?? '');
                                $gColor = (string) ($gCal['color'] ?? '#3B82F6');
                                $gPrimary = !empty($gCal['primary']);
                                $gSelected = !empty($gCal['selected']);
                                ?>
                                <label class="list-group-item list-group-item-action d-flex align-items-start gap-3 p-3 cursor-pointer">
                                    <input class="form-check-input flex-shrink-0 mt-1" type="checkbox" name="selected_calendars[]" value="<?= $e($gId) ?>" <?= $gSelected ? 'checked' : '' ?>>
                                    <div class="flex-grow-1">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="rounded-circle d-inline-block" style="width: 12px; height: 12px; background-color: <?= $e($gColor) ?>;"></span>
                                            <strong class="text-body"><?= $e($gSummary) ?></strong>
                                            <?php if ($gPrimary): ?>
                                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle small">Hauptkalender</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($gDesc !== ''): ?>
                                            <p class="text-body-secondary small mb-0 mt-1"><?= $e($gDesc) ?></p>
                                        <?php endif; ?>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <div class="d-flex justify-content-between align-items-center">
                            <a href="/calendar" class="btn btn-outline-secondary">Abbrechen</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i> Auswahl speichern &amp; synchronisieren
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
