<?php

declare(strict_types=1);

namespace ModulNest\Pages;

use Modulon\Core\AdminNavigationProviderInterface;

final class PagesAdminNavigationProvider implements AdminNavigationProviderInterface
{
    public function moduleKey(): string
    {
        return 'pages';
    }

    /**
     * @return array<int, array{key:string,label:string,url:string,is_active:bool,description:string,sort_order:int}>
     */
    public function items(string $currentPath): array
    {
        $url = '/admin/pages';

        return [[
            'key' => 'pages',
            'label' => 'Pages',
            'url' => $url,
            'description' => 'Microsites und rechtliche Seiten verwalten',
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
