<?php
declare(strict_types=1);

$state = is_array($state ?? null) ? $state : [];
$sets = is_array($sets ?? null) ? $sets : [];
$inventory = is_array($inventory ?? null) ? $inventory : [];
$history = is_array($history ?? null) ? $history : [];
$config = is_array($config ?? null) ? $config : [];
$message = (string) ($message ?? '');
$error = (string) ($error ?? '');
$csrfToken = (string) ($csrf_token ?? '');
$timezoneName = (string) ($timezone_name ?? 'UTC');
$e = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
$claims = (int) ($state['free_claims'] ?? 0);
$maxClaims = (int) ($state['max_free_claims'] ?? 3);
$nextClaim = (string) ($state['next_claim_at'] ?? '');
$formatDateTime = static function (mixed $value) use ($timezoneName): string {
    $text = trim((string) ($value ?? ''));
    if ($text === '') {
        return '';
    }

    try {
        $timezone = new DateTimeZone($timezoneName);
    } catch (Throwable) {
        $timezone = new DateTimeZone('UTC');
    }

    try {
        $date = str_contains($text, 'T')
            ? new DateTimeImmutable($text)
            : new DateTimeImmutable($text, new DateTimeZone('UTC'));

        return $date->setTimezone($timezone)->format('d.m.Y H:i:s');
    } catch (Throwable) {
        return $text;
    }
};
$nextClaimLabel = $formatDateTime($nextClaim);
?>

<?php require __DIR__ . '/partials/module-nav.php'; ?>

<?php if ($message !== ''): ?>
    <div class="alert alert-success"><?= $e($message) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= $e($error) ?></div>
<?php endif; ?>

<section class="app-card fantasycards-booster-hero p-4 mb-4">
    <div class="d-flex flex-column flex-xl-row justify-content-between gap-4">
        <div>
            <p class="text-uppercase text-body-secondary small fw-semibold mb-1">Booster</p>
            <h1 class="h3 mb-2">Meine Booster</h1>
            <p class="text-body-secondary mb-0">Free-Pack-Claims sammeln, Booster auswählen und Karten mit Reveal-Animation öffnen.</p>
        </div>
        <div class="fantasycards-claim-meter">
            <div class="small text-body-secondary">Free-Pack-Claims</div>
            <div class="display-6 fw-semibold"><?= $claims ?>/<?= $maxClaims ?></div>
            <div class="small text-body-secondary">
                <?php if ($nextClaimLabel !== ''): ?>
                    Nächster Claim: <?= $e($nextClaimLabel) ?> · <?= $e($timezoneName) ?>
                <?php else: ?>
                    Maximum erreicht
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<div class="row g-4 mb-4">
    <div class="col-12 col-xl-5">
        <section class="app-card p-4 h-100">
            <h2 class="h5 mb-3">Free Pack claimen</h2>
            <p class="text-body-secondary small">Alle <?= (int) ($config['free_claim_interval_seconds'] ?? 43200) / 3600 ?> Stunden entsteht bis maximal <?= $maxClaims ?> ein neuer Claim. Free Packs werden nicht automatisch geöffnet.</p>
            <?php if ($sets === []): ?>
                <p class="mb-0 text-body-secondary">Aktuell ist kein Set für Free Packs verfügbar.</p>
            <?php else: ?>
                <form method="post" action="/fantasy-cards/boosters/claim" class="d-flex flex-column gap-3">
                    <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                    <label class="form-label mb-0">
                        Set auswählen
                        <select class="form-select mt-1" name="set_id" <?= $claims <= 0 ? 'disabled' : '' ?>>
                            <?php foreach ($sets as $set): ?>
                                <option value="<?= (int) ($set['id'] ?? 0) ?>">
                                    <?= $e($set['name'] ?? '') ?> · <?= (int) ($set['booster_card_count'] ?? 0) ?> Booster-Karten
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="btn btn-primary align-self-start" type="submit" <?= $claims <= 0 ? 'disabled' : '' ?>>Free Booster erhalten</button>
                </form>
            <?php endif; ?>
        </section>
    </div>
    <div class="col-12 col-xl-7">
        <section class="app-card p-4 h-100">
            <h2 class="h5 mb-3">Öffnungsverlauf</h2>
            <?php if ($history === []): ?>
                <p class="mb-0 text-body-secondary">Noch kein Booster geöffnet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Booster</th>
                            <th>Set</th>
                            <th class="text-end">Karten</th>
                            <th>Zeitpunkt</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($history as $entry): ?>
                            <tr>
                                <td><?= $e($entry['booster_name'] ?? '') ?></td>
                                <td><?= $e($entry['set_name'] ?? '') ?></td>
                                <td class="text-end"><?= (int) ($entry['cards_count'] ?? 0) ?></td>
                                <td class="text-body-secondary small"><?= $e($formatDateTime($entry['opened_at'] ?? '')) ?> · <?= $e($timezoneName) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<section class="app-card p-4 mb-4">
    <h2 class="h5 mb-3">Booster-Inventar</h2>
    <?php if ($inventory === []): ?>
        <p class="mb-0 text-body-secondary">Du besitzt aktuell keine ungeöffneten Booster.</p>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($inventory as $booster): ?>
                <div class="col-12 col-md-6 col-xl-4">
                    <article class="fantasycards-booster-card h-100" data-booster-type-id="<?= (int) ($booster['booster_type_id'] ?? 0) ?>">
                        <div class="fantasycards-booster-art">
                            <span><?= $e($booster['set_name'] ?? 'Booster') ?></span>
                        </div>
                        <div class="p-3">
                            <div class="d-flex justify-content-between gap-3 align-items-start mb-2">
                                <h3 class="h5 mb-0"><?= $e($booster['booster_name'] ?? '') ?></h3>
                                <span class="badge text-bg-primary">x<?= (int) ($booster['quantity'] ?? 0) ?></span>
                            </div>
                            <p class="small text-body-secondary mb-3"><?= $e($booster['booster_description'] ?? '') ?></p>
                            <button
                                class="btn btn-primary fantasycards-open-booster"
                                type="button"
                                data-open-url="/fantasy-cards/boosters/open"
                                data-csrf-token="<?= $e($csrfToken) ?>"
                                data-booster-type-id="<?= (int) ($booster['booster_type_id'] ?? 0) ?>"
                            >Booster öffnen</button>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="app-card fantasycards-opening-stage p-4" id="fantasycards-opening-stage" hidden>
    <div class="d-flex justify-content-between gap-3 align-items-start mb-3">
        <div>
            <p class="text-uppercase text-body-secondary small fw-semibold mb-1">Opening</p>
            <h2 class="h4 mb-0" id="fantasycards-opening-title">Booster wird geöffnet</h2>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="/fantasy-cards/collection">Zur Sammlung</a>
    </div>
    <div class="fantasycards-pack-animation" aria-hidden="true">
        <div class="fantasycards-pack">BOOSTER</div>
    </div>
    <div class="fantasycards-reveal-grid" id="fantasycards-reveal-grid"></div>
</section>

<script src="/assets/js/fantasycards-lightbox.js" defer></script>
<script src="/assets/js/fantasycards-booster.js" defer></script>
