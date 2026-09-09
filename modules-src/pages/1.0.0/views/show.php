<?php
declare(strict_types=1);

$entry = is_array($entry ?? null) ? $entry : [];
$contentHtml = (string) ($content_html ?? '');
?>
<div class="container py-4">
    <article class="card border-0 shadow-sm app-card">
        <div class="card-body p-4">
            <h1 class="h3 mb-3"><?= htmlspecialchars((string) ($entry['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h1>
            <div class="markdown-body">
                <?= $contentHtml ?>
            </div>
        </div>
    </article>
</div>
