<?php
declare(strict_types=1);

$sets = is_array($sets ?? null) ? $sets : [];
$cards = is_array($cards ?? null) ? $cards : [];
$progress = is_array($progress ?? null) ? $progress : [];
$rarities = is_array($rarities ?? null) ? $rarities : [];
$selectedSetId = (int) ($selected_set_id ?? 0);
$e = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
?>

<?php require __DIR__ . '/partials/module-nav.php'; ?>

<section class="app-card p-4 mb-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
        <div>
            <p class="text-uppercase text-body-secondary small fw-semibold mb-1">Sammlung</p>
            <h1 class="h3 mb-2">Meine Sammlung</h1>
            <p class="text-body-secondary mb-0">Set-Fortschritt, Duplikate und fehlende Karten auf einen Blick.</p>
        </div>
        <form method="get" class="d-flex align-items-end gap-2">
            <label class="form-label mb-0">
                Set
                <select class="form-select mt-1" name="set" onchange="this.form.submit()">
                    <option value="0">Alle Sets</option>
                    <?php foreach ($sets as $set): ?>
                        <option value="<?= (int) ($set['id'] ?? 0) ?>" <?= (int) ($set['id'] ?? 0) === $selectedSetId ? 'selected' : '' ?>>
                            <?= $e($set['name'] ?? '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <noscript><button class="btn btn-outline-secondary" type="submit">Filtern</button></noscript>
        </form>
    </div>
</section>

<div class="row g-4 mb-4">
    <?php foreach ($progress as $set): ?>
        <?php
        $total = (int) ($set['total_cards'] ?? 0);
        $owned = (int) ($set['owned_cards'] ?? 0);
        $percent = (int) ($set['percent'] ?? 0);
        ?>
        <div class="col-12 col-md-6 col-xl-4">
            <article class="app-card p-3 h-100">
                <div class="d-flex justify-content-between gap-3 mb-2">
                    <h2 class="h6 mb-0"><?= $e($set['name'] ?? '') ?></h2>
                    <span class="small text-body-secondary"><?= $owned ?>/<?= $total ?></span>
                </div>
                <div class="progress fantasycards-progress mb-2" role="progressbar" aria-valuenow="<?= $percent ?>" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar" style="width: <?= $percent ?>%"></div>
                </div>
                <div class="small text-body-secondary mb-1"><?= $percent ?>% gesammelt</div>
                <div class="small text-body-secondary">
                    Seltenste Karte:
                    <?= ($set['rarest_card_name'] ?? '') !== '' ? $e($set['rarest_card_name']) : 'Noch keine' ?>
                </div>
                <div class="small text-body-secondary">
                    Letzte neue Karte:
                    <?= ($set['last_card_name'] ?? '') !== '' ? $e($set['last_card_name']) : 'Noch keine' ?>
                </div>
            </article>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($cards === []): ?>
    <div class="app-card p-4">
        <p class="mb-0 text-body-secondary">Noch keine aktiven Karten vorhanden.</p>
    </div>
<?php else: ?>
    <div class="row g-4">
        <?php foreach ($cards as $card): ?>
            <?php
            $owned = (int) ($card['owned_quantity'] ?? 0);
            $rarityKey = (string) ($card['rarity'] ?? 'common');
            $rarity = $rarities[$rarityKey] ?? ['label' => $rarityKey, 'class' => 'fantasycards-rarity-common', 'badge' => 'text-bg-secondary'];
            $fullImage = trim((string) ($card['image_path'] ?? ''));
            $thumbImage = trim((string) ($card['thumbnail_path'] ?? ''));
            $previewImage = $thumbImage !== '' ? $thumbImage : $fullImage;
            $cardTitle = (string) ($card['name'] ?? '');
            $caption = trim((string) ($card['set_name'] ?? '') . ' · ' . (string) ($card['card_number'] ?? '') . ' · ' . (string) ($rarity['label'] ?? ''));
            ?>
            <div class="col-12 col-sm-6 col-xl-3">
                <article class="fantasycards-card app-card h-100 <?= $e($rarity['class'] ?? '') ?> <?= $owned <= 0 ? 'fantasycards-card-missing' : '' ?>">
                    <div class="fantasycards-card-image">
                        <?php if ($owned > 0 && $previewImage !== ''): ?>
                            <?php if ($fullImage !== ''): ?>
                                <button type="button" class="fantasycards-image-button" data-fantasycards-lightbox data-full-image="<?= $e($fullImage) ?>" data-title="<?= $e($cardTitle) ?>" data-caption="<?= $e($caption) ?>">
                                    <img src="<?= $e($previewImage) ?>" alt="<?= $e($cardTitle) ?>" loading="lazy">
                                </button>
                            <?php else: ?>
                                <img src="<?= $e($previewImage) ?>" alt="<?= $e($cardTitle) ?>" loading="lazy">
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="fantasycards-image-placeholder"><?= $owned > 0 ? 'Karte' : '?' ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="p-3">
                        <div class="d-flex justify-content-between gap-2 align-items-start mb-2">
                            <h2 class="h6 mb-0"><?= $owned > 0 ? $e($card['name'] ?? '') : 'Unentdeckt' ?></h2>
                            <span class="badge <?= $e($rarity['badge'] ?? 'text-bg-secondary') ?>"><?= $e($rarity['label'] ?? '') ?></span>
                        </div>
                        <div class="small text-body-secondary mb-2">
                            <?= $e($card['set_name'] ?? '') ?> · <?= $e($card['card_number'] ?? '') ?>
                        </div>
                        <?php if ($owned > 0): ?>
                            <span class="badge text-bg-success">x<?= $owned ?></span>
                        <?php else: ?>
                            <span class="badge text-bg-secondary">Fehlt</span>
                        <?php endif; ?>
                    </div>
                </article>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script src="/assets/js/fantasycards-lightbox.js" defer></script>
