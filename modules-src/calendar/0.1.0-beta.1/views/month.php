<?php
declare(strict_types=1);

/**
 * Monatsansicht mit Multi-Kalender Sidebar.
 *
 * @var array $appointments
 * @var array $calendars
 * @var \DateTime $currentDate
 * @var \DateTime $firstDayOfMonth
 * @var string $monthLabel
 * @var array $weeks
 * @var \DateTime $prevMonth
 * @var \DateTime $nextMonth
 * @var string $base_path
 * @var string $csrf_token
 * @var string $view
 */

$calBasePath = (string) ($base_path ?? '/calendar');
$calCsrf = (string) ($csrf_token ?? '');
?>

<?php require __DIR__ . '/partials/calendar-head.php'; ?>

<div class="row g-4">
    <div class="col-12 col-lg-3 col-xl-2">
        <?php require __DIR__ . '/partials/calendar-sidebar.php'; ?>
    </div>
    <div class="col-12 col-lg-9 col-xl-10">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <h1 class="h3 mb-0"><?= htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8') ?></h1>
            <div class="d-flex align-items-center gap-2">
                <a class="btn btn-outline-secondary btn-sm" href="<?= $calBasePath ?>/month?date=<?= $prevMonth->format('Y-m-d') ?>" title="Vorheriger Monat">
                    &larr; Vorheriger Monat
                </a>
                <a class="btn btn-outline-primary btn-sm" href="<?= $calBasePath ?>/month?date=<?= (new \DateTime())->format('Y-m-d') ?>" title="Zum heutigen Monat">
                    Heute
                </a>
                <a class="btn btn-outline-secondary btn-sm" href="<?= $calBasePath ?>/month?date=<?= $nextMonth->format('Y-m-d') ?>" title="Nächster Monat">
                    Nächster Monat &rarr;
                </a>
            </div>
        </div>

        <div class="calendar-month-container card border shadow-sm">
            <div class="calendar-month-weekdays border-bottom">
                <div class="calendar-weekday-col">Mo</div>
                <div class="calendar-weekday-col">Di</div>
                <div class="calendar-weekday-col">Mi</div>
                <div class="calendar-weekday-col">Do</div>
                <div class="calendar-weekday-col">Fr</div>
                <div class="calendar-weekday-col">Sa</div>
                <div class="calendar-weekday-col">So</div>
            </div>
            <div class="calendar-month-grid">
                <?php foreach ($weeks as $week): ?>
                    <div class="calendar-month-row">
                        <?php foreach ($week as $day): ?>
                            <?php
                            $isCur = (bool) $day['isCurrentMonth'];
                            $isToday = (bool) $day['isToday'];
                            $dateStr = (string) $day['dateString'];
                            $dayNum = (int) $day['dayNumber'];
                            $dayApts = is_array($day['appointments']) ? $day['appointments'] : [];
                            ?>
                            <div class="calendar-month-cell<?= !$isCur ? ' is-other-month' : '' ?><?= $isToday ? ' is-today' : '' ?>" data-date="<?= $dateStr ?>">
                                <div class="calendar-cell-header d-flex align-items-center justify-content-between">
                                    <a href="<?= $calBasePath ?>/day?date=<?= $dateStr ?>" class="calendar-day-num badge <?= $isToday ? 'bg-primary text-white' : 'text-body text-decoration-none' ?>" title="Zur Tagesansicht für diesen Tag">
                                        <?= $dayNum ?>
                                    </a>
                                    <a href="<?= $calBasePath ?>/appointment/create?date=<?= $dateStr ?>" class="btn btn-link p-0 text-decoration-none calendar-add-quick-btn" title="Termin an diesem Tag erstellen">
                                        <i class="bi bi-plus-circle"></i>
                                    </a>
                                </div>
                                <div class="calendar-cell-events">
                                    <?php foreach ($dayApts as $apt): ?>
                                        <?php
                                        $aptColor = (string) ($apt->color ?? '#3B82F6');
                                        $aptTitle = (string) ($apt->title ?? 'Termin');
                                        $isAllDay = (bool) ($apt->allDay ?? false);
                                        $timeLabel = $isAllDay ? 'Ganztägig' : $apt->startAt->format('H:i');
                                        ?>
                                        <a href="<?= $calBasePath ?>/appointment/<?= $apt->id ?>/edit"
                                           class="calendar-month-event"
                                           style="border-left-color: <?= htmlspecialchars($aptColor, ENT_QUOTES, 'UTF-8') ?>;"
                                           title="<?= htmlspecialchars($aptTitle . ($isAllDay ? ' (Ganztägig)' : ' (' . $apt->startAt->format('H:i') . ' - ' . $apt->endAt->format('H:i') . ')'), ENT_QUOTES, 'UTF-8') ?>">
                                            <span class="calendar-event-time"><?= $timeLabel ?></span>
                                            <span class="calendar-event-title"><?= htmlspecialchars($aptTitle, ENT_QUOTES, 'UTF-8') ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
