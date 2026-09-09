<?php

declare(strict_types=1);

namespace ModulNest\SneakPreview;

use Modulon\Core\AdminNavigationProviderInterface;

final class SneakPreviewAdminNavigationProvider implements AdminNavigationProviderInterface
{
    public function moduleKey(): string
    {
        return 'sneak-preview';
    }

    public function items(string $currentPath): array
    {
        return [[
            'key' => 'sneak-preview',
            'label' => 'Sneak Preview',
            'url' => '/admin/sneak-preview',
            'description' => 'Sneak-Preview-Filme und Anzeige-Einstellungen verwalten',
            'is_active' => $this->isActive('/admin/sneak-preview', $currentPath),
            'sort_order' => 60,
        ]];
    }

    private function isActive(string $url, string $currentPath): bool
    {
        $target = rtrim('/' . trim($url, '/'), '/');
        $current = rtrim('/' . trim($currentPath, '/'), '/');

        return $current === $target || str_starts_with($current, $target . '/');
    }
}
