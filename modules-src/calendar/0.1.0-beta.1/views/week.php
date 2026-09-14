<?php
declare(strict_types=1);

/**
 * Wochenansicht mit Multi-Kalender Sidebar.
 *
 * @var array $appointments
 * @var array $calendars
 * @var \DateTime $weekStart
 * @var string $weekLabel
 * @var array $days
 * @var \DateTime $prevWeek
 * @var \DateTime $nextWeek
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
            <h1 class="h3 mb-0"><?= htmlspecialchars($weekLabel, ENT_QUOTES, 'UTF-8') ?></h1>
            <div class="d-flex align-items-center gap-2">
                <a class="btn btn-outline-secondary btn-sm" href="<?= $calBasePath ?>/week?date=<?= $prevWeek->format('Y-m-d') ?>" title="Vorherige Woche">
                    &larr; Vorherige Woche
                </a>
                <a class="btn btn-outline-primary btn-sm" href="<?= $calBasePath ?>/week?date=<?= (new \DateTime())->format('Y-m-d') ?>" title="Aktuelle Woche">
                    Heute
                </a>
                <a class="btn btn-outline-secondary btn-sm" href="<?= $calBasePath ?>/week?date=<?= $nextWeek->format('Y-m-d') ?>" title="Nächste Woche">
                    Nächste Woche &rarr;
                </a>
            </div>
        </div>

        <div class="calendar-week">
            <?php foreach ($days as $index => $day): ?>
                <?php $dayStr = $day->format('Y-m-d'); ?>
                <div class="calendar-day-column" data-date="<?= $dayStr ?>">
                    <div class="calendar-day-header <?= $dayStr === (new DateTime())->format('Y-m-d') ? 'text-primary fw-bold' : '' ?>">
                        <a href="<?= $calBasePath ?>/day?date=<?= $dayStr ?>" class="text-decoration-none text-reset">
                            <?= $day->format('D') ?> <?= $day->format('d.m.') ?>
                        </a>
                    </div>
                    <div class="calendar-day-events">
                        <?php foreach ($appointments as $appointment): ?>
                            <?php if ($appointment->startAt->format('Y-m-d') === $dayStr && !$appointment->allDay): ?>
                                <?php require __DIR__ . '/partials/appointment-block.php'; ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <div class="calendar-day-all-day">
                        <?php foreach ($appointments as $appointment): ?>
                            <?php if ($appointment->startAt->format('Y-m-d') === $dayStr && $appointment->allDay): ?>
                                <a class="badge text-decoration-none text-truncate"
                                   style="background-color: <?= htmlspecialchars($appointment->color ?? '#3B82F6', ENT_QUOTES, 'UTF-8') ?>; color: #fff; max-width: 100%; display: inline-block;"
                                   href="<?= $calBasePath ?>/appointment/<?= $appointment->id ?>/edit">
                                    <?= htmlspecialchars($appointment->title, ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
