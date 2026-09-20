<?php
declare(strict_types=1);

/** @var \ModulNest\Calendar\DTO\AppointmentDTO $appointment */
/** @var string $base_path */
/** @var string $csrf_token */

$calAppointment = $appointment;
$calBasePath = (string) ($base_path ?? '/calendar');
$calCsrf = (string) ($csrf_token ?? '');
$isAllDay = (bool) $calAppointment->allDay;
$startTime = $isAllDay ? '' : $calAppointment->startAt->format('H:i');
$endTime = $isAllDay ? '' : $calAppointment->endAt->format('H:i');
$color = $calAppointment->color ?? '#3B82F6';
$title = htmlspecialchars($calAppointment->title, ENT_QUOTES, 'UTF-8');
$location = $calAppointment->location ? htmlspecialchars($calAppointment->location, ENT_QUOTES, 'UTF-8') : '';
$editUrl = $calBasePath . '/appointment/' . $calAppointment->id . '/edit';
$deleteUrl = $calBasePath . '/appointment/' . $calAppointment->id . '/delete';

$top = 0;
$height = 60;
if (!$isAllDay) {
    $startMinutes = ((int) $calAppointment->startAt->format('H')) * 60 + (int) $calAppointment->startAt->format('i');
    $endMinutes = ((int) $calAppointment->endAt->format('H')) * 60 + (int) $calAppointment->endAt->format('i');
    $duration = max(30, $endMinutes - $startMinutes);
    $top = ($startMinutes / 60) * 60;
    $height = ($duration / 60) * 60;
}

$borderColor = $color;
?>
<div class="appointment-block <?= $isAllDay ? 'all-day' : '' ?>"
     data-id="<?= $calAppointment->id ?>"
     style="top: <?= $top ?>px; height: <?= $height ?>px; background-color: <?= $borderColor ?>; border-left: 4px solid <?= $borderColor ?>;"
     tabindex="0" role="button"
     aria-label="<?= $title ?><?= $startTime !== '' ? ", {$startTime} - {$endTime}" : '' ?>">
    <div class="appointment-content">
        <div class="appointment-time">
            <?php if (!$isAllDay): ?>
                <span><?= $startTime ?></span>&nbsp;–&nbsp;<span><?= $endTime ?></span>
            <?php else: ?>
                <span>Ganztägig</span>
            <?php endif; ?>
        </div>
        <div class="appointment-title"><?= $title ?></div>
        <?php if ($location !== ''): ?>
            <div class="appointment-location text-body-secondary"><?= $location ?></div>
        <?php endif; ?>
    </div>
</div>