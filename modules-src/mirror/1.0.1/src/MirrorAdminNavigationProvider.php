<?php

declare(strict_types=1);

namespace ModulNest\Mirror;

use Modulon\Core\AdminNavigationProviderInterface;

final class MirrorAdminNavigationProvider implements AdminNavigationProviderInterface
{
    public function moduleKey(): string
    {
        return 'modulnest.mirror';
    }

    /**
     * @return array<int, array{key:string,label:string,url:string,is_active:bool,description:string,sort_order:int}>
     */
    public function items(string $currentPath): array
    {
        $url = '/admin/mirror';

        return [[
            'key' => 'modulnest.mirror',
            'label' => 'Mirror Manager',
            'url' => $url,
            'description' => 'Multi-Mirror für Repositories & Updates verwalten',
            'is_active' => $this->isActive($url, $currentPath),
            'sort_order' => 85,
        ]];
    }

    private function isActive(string $url, string $currentPath): bool
    {
        $target = rtrim('/' . trim($url, '/'), '/');
        $current = rtrim('/' . trim($currentPath, '/'), '/');

        return $current === $target || str_starts_with($current, $target . '/');
    }
}
