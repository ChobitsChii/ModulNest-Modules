<?php

declare(strict_types=1);

namespace ModulNest\Homepage;

use Modulon\Core\AdminNavigationProviderInterface;

final class HomepageAdminNavigationProvider implements AdminNavigationProviderInterface
{
    public function moduleKey(): string
    {
        return 'homepage';
    }

    public function items(string $currentPath): array
    {
        $url = '/admin/homepage';

        return [[
            'key' => 'homepage',
            'label' => 'Startseite',
            'url' => $url,
            'description' => 'Konfigurierbare Root-Startseite vorbereiten',
            'is_active' => $this->isActive($url, $currentPath),
            'sort_order' => 95,
        ]];
    }

    private function isActive(string $url, string $currentPath): bool
    {
        $target = rtrim('/' . trim($url, '/'), '/');
        $current = rtrim('/' . trim($currentPath, '/'), '/');

        return $current === $target || str_starts_with($current, $target . '/');
    }
}
