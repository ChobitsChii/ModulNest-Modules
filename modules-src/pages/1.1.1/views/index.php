<?php
declare(strict_types=1);

$entries = is_array($entries ?? null) ? $entries : [];
?>
<div class="container py-4">
    <div class="card border-0 shadow-sm app-card">
        <div class="card-body p-4">
            <h1 class="h4 mb-3">Seiten</h1>
            <?php if ($entries === []): ?>
                <p class="text-body-secondary mb-0">Keine öffentlichen Seiten gefunden.</p>
            <?php else: ?>
                <ul class="mb-0">
                    <?php foreach ($entries as $entry): ?>
                        <li>
                            <a href="/pages/<?= htmlspecialchars((string) ($entry['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars((string) ($entry['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
