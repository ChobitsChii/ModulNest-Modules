<?php
declare(strict_types=1);

$toolGroups = is_array($tool_groups ?? null) ? $tool_groups : [];
$selectedTool = is_array($selected_tool ?? null) ? $selected_tool : null;
$isSingleTool = $selectedTool !== null;
$e = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
$slug = static fn (string $value): string => strtolower(strtr($value, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', ' ' => '-']));
$categoryLabels = ['overview' => 'Übersicht'];
foreach (array_keys($toolGroups) as $category) {
    $categoryLabels[$slug((string) $category)] = (string) $category;
}
?>

<section class="app-card p-4 mb-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
        <div>
            <p class="text-uppercase text-muted small fw-semibold mb-1">Modulon Tools</p>
            <?php if ($isSingleTool): ?>
                <h1 class="h3 mb-2"><?= $e($selectedTool['label'] ?? 'Tool') ?></h1>
                <p class="text-muted mb-0"><?= $e($selectedTool['description'] ?? 'Dieses Tool läuft lokal im Browser.') ?></p>
            <?php else: ?>
                <h1 class="h3 mb-2">Hilfs- und Entwickler-Tools</h1>
                <p class="text-muted mb-0">Die User-Tools laufen lokal im Browser und erzeugen keine Serverlast.</p>
            <?php endif; ?>
        </div>
        <?php if ($isSingleTool): ?>
            <div class="align-self-lg-end">
                <a class="btn btn-outline-secondary btn-sm" href="/tools">Zur Tools-Übersicht</a>
            </div>
        <?php else: ?>
            <div class="align-self-lg-end tools-search-wrap">
                <label class="form-label small text-muted" for="tools-search">Tools suchen</label>
                <input class="form-control" id="tools-search" type="search" placeholder="z. B. JSON, Hash, QR">
            </div>
        <?php endif; ?>
    </div>
</section>

<?php if ($isSingleTool): ?>
    <div class="tools-single">
        <?php $tool = $selectedTool; $is_single_tool = true; require __DIR__ . '/partials/public-tool-card.php'; ?>
    </div>
<?php else: ?>
    <nav class="modulon-module-nav tools-category-tabs mb-4" aria-label="Tools-Kategorien">
        <ul class="nav nav-tabs" data-tools-category-tabs>
            <?php foreach ($categoryLabels as $categorySlug => $categoryLabel): ?>
                <?php $categoryHash = $categorySlug === 'overview' ? 'tools-uebersicht' : 'tools-' . $categorySlug; ?>
                <li class="nav-item">
                    <a
                        class="nav-link<?= $categorySlug === 'overview' ? ' active' : '' ?>"
                        href="#<?= $e($categoryHash) ?>"
                        data-tools-category-tab="<?= $e($categorySlug) ?>"
                    >
                        <?= $e($categoryLabel) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <div class="tools-grid" data-tools-grid>
        <?php foreach ($toolGroups as $category => $tools): ?>
            <?php
            $categorySlug = $slug((string) $category);
            $categoryId = 'tools-' . $categorySlug;
            ?>
            <section class="tools-category" id="<?= $e($categoryId) ?>" data-tools-category="<?= $e($categorySlug) ?>">
                <h2 class="h5 mb-3"><?= $e($category) ?></h2>
                <div class="row g-3">
                    <?php foreach ($tools as $tool): ?>
                        <?php
                        $key = (string) ($tool['key'] ?? '');
                        $label = (string) ($tool['label'] ?? '');
                        $description = (string) ($tool['description'] ?? '');
                        ?>
                        <div
                            class="col-12 col-xl-6 tools-card-wrap"
                            data-tool-card
                            data-tool-category-slug="<?= $e($categorySlug) ?>"
                            data-tool-search="<?= $e(strtolower($label . ' ' . $description . ' ' . $category)) ?>"
                        >
                            <?php $is_single_tool = false; require __DIR__ . '/partials/public-tool-card.php'; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>

    <div class="alert alert-secondary mt-4 d-none" data-tools-empty>
        Keine Tools für diese Auswahl gefunden.
    </div>
<?php endif; ?>

<?php $assetVersion = rawurlencode((string) ($app_version ?? '')); ?>
<script src="/assets/vendor/qrcode-generator/qrcode.min.js<?= $assetVersion !== '' ? '?v=' . $assetVersion : '' ?>"></script>
<script src="/assets/modules/modulnest.tools/<?= rawurlencode(basename(dirname(__DIR__))) ?>/tools.js<?= $assetVersion !== '' ? '?v=' . $assetVersion : '' ?>"></script>
