<?php
declare(strict_types=1);

$entries = is_array($entries ?? null) ? $entries : [];
$viewMode = (string) ($view_mode ?? 'compact');
$isExpanded = $viewMode === 'expanded';
?>
<div class="d-flex align-items-center justify-content-between mb-4">
    <h1 class="h4 mb-0">News & Updates</h1>
    <div class="d-flex align-items-center gap-2">
        <span class="text-body-secondary small d-none d-sm-inline">Modulon Changelog</span>
        <div class="btn-group btn-group-sm" role="group" aria-label="Ansicht">
            <a href="/news?view=compact" class="btn <?= !$isExpanded ? 'btn-primary' : 'btn-outline-secondary' ?>">Kompakt</a>
            <a href="/news?view=expanded" class="btn <?= $isExpanded ? 'btn-primary' : 'btn-outline-secondary' ?>">Erweitert</a>
        </div>
    </div>
</div>

<?php if ($entries === []): ?>
    <div class="card shadow-sm border-0 app-card">
        <div class="card-body p-4 text-body-secondary">Noch keine veröffentlichten Einträge.</div>
    </div>
<?php else: ?>
    <div class="row g-3 <?= $isExpanded ? 'news-grid-expanded' : 'news-grid-compact' ?>">
        <?php foreach ($entries as $entry): ?>
            <?php
            $title = (string) ($entry['title'] ?? '');
            $slug = (string) ($entry['slug'] ?? '');
            $type = (string) ($entry['type'] ?? 'news');
            $version = trim((string) ($entry['version'] ?? ''));
            $excerpt = (string) ($entry['excerpt'] ?? '');
            $publishedAt = (string) (($entry['published_at'] ?? '') ?: ($entry['created_at'] ?? ''));
            $contentHtml = (string) ($entry['content_html'] ?? '');
            ?>
            <div class="<?= $isExpanded ? 'col-12' : 'col-12 col-lg-6' ?>">
                <article class="card shadow-sm border-0 app-card h-100">
                    <div class="card-body p-4">
                        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                            <span class="badge text-bg-secondary"><?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php if ($version !== ''): ?>
                                <span class="badge text-bg-primary">v<?= htmlspecialchars($version, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                            <span class="text-body-secondary small"><?= htmlspecialchars($publishedAt, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <h2 class="h5 mb-2"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h2>
                        <p class="text-body-secondary mb-3"><?= htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8') ?></p>
                        <?php if ($isExpanded): ?>
                            <div class="modulon-news-content mn-markdown mb-3"><?= $contentHtml ?></div>
                        <?php endif; ?>
                        <a href="/news/<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary btn-sm">Zum Eintrag</a>
                    </div>
                </article>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
