<?php
declare(strict_types=1);

$moduleNavItems = is_array($module_nav_items ?? null) ? $module_nav_items : [];
$moduleNavLabel = (string) ($module_nav_label ?? 'Modulnavigation');
?>

<?php if ($moduleNavItems !== []): ?>
    <nav class="modulon-module-nav mb-4" aria-label="<?= htmlspecialchars($moduleNavLabel, ENT_QUOTES, 'UTF-8') ?>">
        <ul class="nav nav-tabs">
            <?php foreach ($moduleNavItems as $item): ?>
                <?php
                $label = (string) ($item['label'] ?? '');
                $url = (string) ($item['url'] ?? '#');
                $isActive = (bool) ($item['is_active'] ?? false);
                ?>
                <li class="nav-item">
                    <a class="nav-link<?= $isActive ? ' active' : '' ?>" href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>
<?php endif; ?>
