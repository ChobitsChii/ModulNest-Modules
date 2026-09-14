<?php

declare(strict_types=1);

namespace ModulNest\RepositoryManager;

use Modulon\Core\AdminNavigationProviderInterface;

final class RepositoryManagerAdminNavigationProvider implements AdminNavigationProviderInterface
{
    public function moduleKey(): string
    {
        return 'repository-manager';
    }

    public function items(string $currentPath): array
    {
        $url = '/admin/repository-manager';

        return [[
            'key' => 'repository-manager',
            'label' => 'Repository Manager',
            'url' => $url,
            'description' => 'Modul-Katalogquellen verwalten',
            'is_active' => $this->isActive($url, $currentPath),
            'sort_order' => 910,
        ]];
    }

    private function isActive(string $url, string $currentPath): bool
    {
        $target = rtrim('/' . trim($url, '/'), '/');
        $current = rtrim('/' . trim($currentPath, '/'), '/');

        return $current === $target || str_starts_with($current, $target . '/');
    }
}
