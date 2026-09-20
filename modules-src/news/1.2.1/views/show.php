<?php
declare(strict_types=1);

$entry = is_array($entry ?? null) ? $entry : [];
$title = (string) ($entry['title'] ?? '');
$type = (string) ($entry['type'] ?? 'news');
$version = trim((string) ($entry['version'] ?? ''));
$publishedAt = (string) (($entry['published_at'] ?? '') ?: ($entry['created_at'] ?? ''));
$contentHtml = (string) ($entry['content_html'] ?? '');
?>
<div class="mb-3">
    <a href="/news" class="btn btn-outline-secondary btn-sm">&larr; Zur Übersicht</a>
</div>

<article class="card shadow-sm border-0 app-card">
    <div class="card-body p-4 p-md-5">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <span class="badge text-bg-secondary"><?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?></span>
            <?php if ($version !== ''): ?>
                <span class="badge text-bg-primary">v<?= htmlspecialchars($version, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
            <span class="text-body-secondary small"><?= htmlspecialchars($publishedAt, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <h1 class="h3 mb-4"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
        <div class="modulon-news-content mn-markdown"><?= $contentHtml ?></div>
    </div>
</article>
