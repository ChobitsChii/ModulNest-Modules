<?php

declare(strict_types=1);

namespace ModulNest\Tools;

use Modulon\Core\AdminNavigationProviderInterface;

final class ToolsAdminNavigationProvider implements AdminNavigationProviderInterface
{
    public function moduleKey(): string
    {
        return 'tools';
    }

    public function items(string $currentPath): array
    {
        $url = '/admin/tools';

        return [[
            'key' => 'tools',
            'label' => 'Tools',
            'url' => $url,
            'description' => 'Admin-Werkzeuge und Diagnosen',
            'is_active' => $this->isActive($url, $currentPath),
            'sort_order' => 90,
        ]];
    }

    private function isActive(string $url, string $currentPath): bool
    {
        $target = rtrim('/' . trim($url, '/'), '/');
        $current = rtrim('/' . trim($currentPath, '/'), '/');

        return $current === $target || str_starts_with($current, $target . '/');
    }
}
