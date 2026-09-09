<?php
declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$blocks = is_array($homepage_blocks ?? null) ? $homepage_blocks : [];
$productMeta = is_array($product_meta ?? null) ? $product_meta : [];
$productName = (string) ($productMeta['product_name'] ?? $productMeta['public_product_name'] ?? 'ModulNest');
$columnClass = static function (string $span): string {
    return match ($span) {
        'half' => 'col-12 col-lg-6',
        'two_thirds' => 'col-12 col-lg-8',
        'one_third', 'third' => 'col-12 col-lg-4',
        default => 'col-12',
    };
};
$buttonClass = static fn (string $variant): string => $variant === 'secondary' ? 'btn btn-outline-secondary btn-sm' : 'btn btn-primary btn-sm';
$renderButtons = static function (array $buttons, bool $withMargin = true) use ($e, $buttonClass): string {
    if ($buttons === []) {
        return '';
    }

    $html = '<div class="homepage-button-row' . ($withMargin ? ' mt-3' : '') . '">';
    foreach ($buttons as $button) {
        if (!is_array($button)) {
            continue;
        }
        $label = trim((string) ($button['label'] ?? ''));
        $url = trim((string) ($button['url'] ?? ''));
        if ($label === '' || $url === '') {
            continue;
        }
        $html .= '<a href="' . $e($url) . '" class="' . $e($buttonClass((string) ($button['variant'] ?? 'primary'))) . '">' . $e($label) . '</a>';
    }

    return $html . '</div>';
};
?>
<div class="row g-3 homepage-grid">
    <?php foreach ($blocks as $block): ?>
        <?php
        if (!is_array($block)) {
            continue;
        }
        $type = (string) ($block['type'] ?? '');
        $span = (string) ($block['column_span'] ?? 'full');
        $buttonLayout = (string) ($block['button_layout'] ?? 'below_text');
        $showTitle = (bool) ($block['show_title'] ?? true);
        ?>
        <div class="<?= $e($columnClass($span)) ?>">
            <?php if ($type === 'module_list'): ?>
                <section class="homepage-module-list-section h-100 homepage-block homepage-block-<?= $e($type) ?>">
                    <?php if (($showTitle && (string) ($block['title'] ?? '') !== '') || (string) ($block['content_html'] ?? '') !== ''): ?>
                        <div class="homepage-module-list-header">
                        <?php if ($showTitle && (string) ($block['title'] ?? '') !== ''): ?>
                            <h2 class="h6 text-uppercase text-body-secondary mb-0"><?= $e((string) ($block['title'] ?? $productName)) ?></h2>
                        <?php endif; ?>
                        <?php if ((string) ($block['content_html'] ?? '') !== ''): ?>
                            <div class="mn-markdown homepage-markdown small">
                                <?= (string) ($block['content_html'] ?? '') ?>
                            </div>
                        <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php $modules = is_array($block['modules'] ?? null) ? $block['modules'] : []; ?>
                    <?php if ($modules === []): ?>
                        <p class="text-body-secondary mb-0">Aktuell sind keine passenden Module aktiv.</p>
                    <?php else: ?>
                        <div class="row g-3">
                            <?php foreach ($modules as $module): ?>
                                <?php
                                if (!is_array($module)) {
                                    continue;
                                }
                                $name = (string) ($module['name'] ?? 'Modul');
                                $description = trim((string) ($module['description'] ?? ''));
                                $url = (string) ($module['url'] ?? '/');
                                ?>
                                <div class="col-12 col-md-6 col-xl-4">
                                    <div class="card shadow-sm border-0 app-card h-100 homepage-module-card">
                                        <div class="card-body p-3 d-flex flex-column">
                                            <h2 class="h6 mb-2"><?= $e($name) ?></h2>
                                            <p class="text-body-secondary small mb-3">
                                                <?= $e($description !== '' ? $description : 'Keine Beschreibung hinterlegt.') ?>
                                            </p>
                                            <div class="mt-auto">
                                                <a href="<?= $e($url) ?>" class="btn btn-primary btn-sm">Modul öffnen</a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
                <?php continue; ?>
            <?php endif; ?>
            <section class="card shadow-sm border-0 app-card h-100 homepage-block homepage-block-<?= $e($type) ?><?= $type === 'custom_content' && $buttonLayout === 'inline_right' ? ' homepage-block-inline-right' : '' ?>">
                <div class="card-body p-4">
                    <?php if ($showTitle): ?>
                        <h1 class="<?= $type === 'custom_content' ? 'h4' : 'h6 text-uppercase text-body-secondary' ?> mb-3"><?= $e((string) ($block['title'] ?? $productName)) ?></h1>
                    <?php endif; ?>

                    <?php if ($type === 'custom_content'): ?>
                        <?php $buttons = is_array($block['buttons'] ?? null) ? $block['buttons'] : []; ?>
                        <?php if ($buttonLayout === 'inline_right' && $buttons !== []): ?>
                            <div class="row g-3 align-items-center homepage-content-inline">
                                <div class="col-12 col-lg">
                                    <div class="mn-markdown homepage-markdown">
                                        <?= (string) ($block['content_html'] ?? '') ?>
                                    </div>
                                </div>
                                <div class="col-12 col-lg-auto">
                                    <?= $renderButtons($buttons, false) ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="mn-markdown homepage-markdown">
                                <?= (string) ($block['content_html'] ?? '') ?>
                            </div>
                            <?= $renderButtons($buttons) ?>
                        <?php endif; ?>
                    <?php elseif ($type === 'feature_list'): ?>
                        <?php if ((string) ($block['content_html'] ?? '') !== ''): ?>
                            <div class="mn-markdown homepage-markdown mb-3">
                                <?= (string) ($block['content_html'] ?? '') ?>
                            </div>
                        <?php endif; ?>
                        <?php $items = is_array($block['items'] ?? null) ? $block['items'] : []; ?>
                        <?php if ($items === []): ?>
                            <p class="text-body-secondary mb-0">Noch keine Feature-Items angelegt.</p>
                        <?php else: ?>
                            <div class="homepage-feature-list">
                                <?php foreach ($items as $item): ?>
                                    <?php if (!is_array($item)) { continue; } ?>
                                    <article class="homepage-feature-item">
                                        <strong class="d-block small mb-1"><?= $e((string) ($item['title'] ?? '')) ?></strong>
                                        <div class="mn-markdown homepage-markdown small">
                                            <?= (string) ($item['content_html'] ?? '') ?>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    <?php endforeach; ?>
</div>
