<?php
declare(strict_types=1);

/**
 * Tagesansicht mit Multi-Kalender Sidebar.
 *
 * @var array $appointments
 * @var array $calendars
 * @var \DateTime $currentDate
 * @var \DateTime $prevDate
 * @var \DateTime $nextDate
 * @var string $base_path
 * @var string $csrf_token
 * @var string $view
 */

$calBasePath = (string) ($base_path ?? '/calendar');
$calCsrf = (string) ($csrf_token ?? '');
$dateStr = $currentDate->format('Y-m-d');
$displayDate = $currentDate->format('d.m.Y');
$isToday = $currentDate->format('Y-m-d') === (new DateTime())->format('Y-m-d');
$allDayAppointments = array_filter($appointments, static fn ($a) => (bool) $a->allDay);
$timedAppointments = array_filter($appointments, static fn ($a) => !$a->allDay);
?>

<?php require __DIR__ . '/partials/calendar-head.php'; ?>

<div class="row g-4">
    <div class="col-12 col-lg-3 col-xl-2">
        <?php require __DIR__ . '/partials/calendar-sidebar.php'; ?>
    </div>
    <div class="col-12 col-lg-9 col-xl-10">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <h1 class="h3 mb-0">
                <?= $isToday ? 'Heute, ' : '' ?><?= $displayDate ?>
            </h1>
            <div class="d-flex align-items-center gap-2">
                <a class="btn btn-outline-secondary btn-sm" href="<?= $calBasePath ?>/day?date=<?= $prevDate->format('Y-m-d') ?>" title="Vorheriger Tag">
                    &larr; Vorheriger Tag
                </a>
                <a class="btn btn-outline-primary btn-sm" href="<?= $calBasePath ?>/day?date=<?= (new \DateTime())->format('Y-m-d') ?>" title="Heute">
                    Heute
                </a>
                <a class="btn btn-outline-secondary btn-sm" href="<?= $calBasePath ?>/day?date=<?= $nextDate->format('Y-m-d') ?>" title="Nächster Tag">
                    Nächster Tag &rarr;
                </a>
            </div>
        </div>

        <div class="calendar-grid" data-date="<?= $dateStr ?>">
            <div class="calendar-hours">
                <?php for ($hour = 0; $hour < 24; $hour++): ?>
                    <div class="calendar-hour" style="height: 60px;"><?= sprintf('%02d:00', $hour) ?></div>
                <?php endfor; ?>
            </div>
            <div class="calendar-events" data-date="<?= $dateStr ?>">
                <?php foreach ($timedAppointments as $appointment): ?>
                    <?php require __DIR__ . '/partials/appointment-block.php'; ?>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($allDayAppointments !== []): ?>
            <h2 class="h5 mt-4">Ganztägig</h2>
            <div class="list-group">
                <?php foreach ($allDayAppointments as $appointment): ?>
                    <a class="list-group-item list-group-item-action d-flex align-items-center gap-2" href="<?= $calBasePath ?>/appointment/<?= $appointment->id ?>/edit">
                        <span class="calendar-color-dot" style="background-color: <?= htmlspecialchars($appointment->color ?? '#3B82F6', ENT_QUOTES, 'UTF-8') ?>;"></span>
                        <strong><?= htmlspecialchars($appointment->title, ENT_QUOTES, 'UTF-8') ?></strong>
                        <?php if ($appointment->location): ?>
                            <span class="text-body-secondary">— <?= htmlspecialchars($appointment->location, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
