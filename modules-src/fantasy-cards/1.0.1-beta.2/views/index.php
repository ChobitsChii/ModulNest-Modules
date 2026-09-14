<?php
declare(strict_types=1);

$sets = is_array($sets ?? null) ? $sets : [];
$e = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
?>

<?php require __DIR__ . '/partials/module-nav.php'; ?>

<section class="app-card fantasycards-hero p-4 mb-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
        <div>
            <p class="text-uppercase text-body-secondary small fw-semibold mb-1">Fantasy Cards</p>
            <h1 class="h3 mb-2">Sammelkarten-Sets</h1>
            <p class="text-body-secondary mb-0">Digitale Fantasy-Karten zum Sammeln. Free Packs claimen, Booster öffnen und Sammlung ausbauen.</p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-self-lg-start">
            <a class="btn btn-primary btn-sm" href="/fantasy-cards/boosters">Booster öffnen</a>
            <a class="btn btn-outline-secondary btn-sm" href="/fantasy-cards/collection">Meine Sammlung</a>
        </div>
    </div>
</section>

<?php if ($sets === []): ?>
    <div class="app-card p-4">
        <p class="mb-0 text-body-secondary">Noch keine aktiven Karten-Sets vorhanden.</p>
    </div>
<?php else: ?>
    <div class="row g-4">
        <?php foreach ($sets as $set): ?>
            <?php
            $cover = trim((string) ($set['cover_image'] ?? ''));
            $cardCount = (int) ($set['card_count'] ?? 0);
            ?>
            <div class="col-12 col-md-6 col-xl-4">
                <article class="app-card fantasycards-set-card h-100">
                    <a class="fantasycards-set-link" href="/fantasy-cards/set/<?= $e($set['slug'] ?? '') ?>">
                        <div class="fantasycards-set-cover">
                            <?php if ($cover !== ''): ?>
                                <img src="<?= $e($cover) ?>" alt="">
                            <?php else: ?>
                                <div class="fantasycards-image-placeholder">Set</div>
                            <?php endif; ?>
                        </div>
                        <div class="p-3">
                            <div class="d-flex justify-content-between gap-3 align-items-start mb-2">
                                <h2 class="h5 mb-0"><?= $e($set['name'] ?? '') ?></h2>
                                <span class="badge text-bg-secondary"><?= $cardCount ?> Karten</span>
                            </div>
                            <p class="text-body-secondary small mb-0"><?= $e($set['description'] ?? '') ?></p>
                        </div>
                    </a>
                </article>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<section class="app-card p-4 mt-4" id="collection-preview">
    <h2 class="h5 mb-2">Booster ohne Echtgeld</h2>
    <p class="text-body-secondary mb-0">Free-Pack-Claims entstehen alle 12 Stunden bis zu einem Maximum von 3. Du entscheidest selbst, aus welchem verfügbaren Set ein Booster erzeugt wird.</p>
</section>
