<?php
declare(strict_types=1);

$set = is_array($set ?? null) ? $set : [];
$cards = is_array($cards ?? null) ? $cards : [];
$rarities = is_array($rarities ?? null) ? $rarities : [];
$e = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
?>

<?php require __DIR__ . '/partials/module-nav.php'; ?>

<section class="app-card p-4 mb-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
        <div>
            <p class="text-uppercase text-body-secondary small fw-semibold mb-1">Karten-Set</p>
            <h1 class="h3 mb-2"><?= $e($set['name'] ?? '') ?></h1>
            <p class="text-body-secondary mb-0"><?= $e($set['description'] ?? '') ?></p>
        </div>
        <a class="btn btn-outline-secondary align-self-lg-start" href="/fantasy-cards">Zurück zu Sets</a>
    </div>
</section>

<?php if ($cards === []): ?>
    <div class="app-card p-4">
        <p class="mb-0 text-body-secondary">In diesem Set sind noch keine aktiven Karten vorhanden.</p>
    </div>
<?php else: ?>
    <div class="row g-4">
        <?php foreach ($cards as $card): ?>
            <?php
            $rarityKey = (string) ($card['rarity'] ?? 'common');
            $rarity = $rarities[$rarityKey] ?? ['label' => $rarityKey, 'class' => 'fantasycards-rarity-common', 'badge' => 'text-bg-secondary'];
            $fullImage = trim((string) ($card['image_path'] ?? ''));
            $thumbImage = trim((string) ($card['thumbnail_path'] ?? ''));
            $previewImage = $thumbImage !== '' ? $thumbImage : $fullImage;
            $cardTitle = (string) ($card['name'] ?? '');
            $caption = trim((string) ($card['card_number'] ?? '') . ' · ' . (string) ($rarity['label'] ?? ''));
            ?>
            <div class="col-12 col-sm-6 col-xl-3">
                <article class="fantasycards-card app-card h-100 <?= $e($rarity['class'] ?? '') ?>">
                    <div class="fantasycards-card-image">
                        <?php if ($previewImage !== ''): ?>
                            <?php if ($fullImage !== ''): ?>
                                <button type="button" class="fantasycards-image-button" data-fantasycards-lightbox data-full-image="<?= $e($fullImage) ?>" data-title="<?= $e($cardTitle) ?>" data-caption="<?= $e($caption) ?>">
                                    <img src="<?= $e($previewImage) ?>" alt="<?= $e($cardTitle) ?>" loading="lazy">
                                </button>
                            <?php else: ?>
                                <img src="<?= $e($previewImage) ?>" alt="<?= $e($cardTitle) ?>" loading="lazy">
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="fantasycards-image-placeholder">Karte</div>
                        <?php endif; ?>
                    </div>
                    <div class="p-3">
                        <div class="d-flex justify-content-between gap-2 align-items-start mb-2">
                            <h2 class="h6 mb-0"><?= $e($card['name'] ?? '') ?></h2>
                            <span class="badge <?= $e($rarity['badge'] ?? 'text-bg-secondary') ?>"><?= $e($rarity['label'] ?? '') ?></span>
                        </div>
                        <div class="small text-body-secondary mb-2"><?= $e($card['card_number'] ?? '') ?></div>
                        <p class="small mb-0"><?= $e($card['description'] ?? '') ?></p>
                    </div>
                </article>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script src="/assets/js/fantasycards-lightbox.js" defer></script>
