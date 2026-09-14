<?php
declare(strict_types=1);

$currentPath = (string) ($current_path ?? '');
$active = static function (string $path) use ($currentPath): string {
    $current = rtrim('/' . trim($currentPath, '/'), '/');
    $target = rtrim('/' . trim($path, '/'), '/');
    if ($target === '/admin/fantasy-cards') {
        return ($current === $target || str_starts_with($current, $target . '/sets')) ? ' active' : '';
    }
    return ($current === $target || str_starts_with($current, $target . '/')) ? ' active' : '';
};
?>

<ul class="nav nav-tabs mb-4">
    <li class="nav-item"><a class="nav-link<?= $active('/admin/fantasy-cards') ?>" href="/admin/fantasy-cards">Sets</a></li>
    <li class="nav-item"><a class="nav-link<?= $active('/admin/fantasy-cards/cards') ?>" href="/admin/fantasy-cards/cards">Karten</a></li>
    <li class="nav-item"><a class="nav-link<?= $active('/admin/fantasy-cards/upload') ?>" href="/admin/fantasy-cards/upload">Upload</a></li>
</ul>
