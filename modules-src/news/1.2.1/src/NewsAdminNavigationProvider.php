<?php

declare(strict_types=1);

namespace ModulNest\News;

use Modulon\Core\AdminNavigationProviderInterface;

final class NewsAdminNavigationProvider implements AdminNavigationProviderInterface
{
    public function moduleKey(): string
    {
        return 'modulnest.news';
    }

    /**
     * @return array<int, array{key:string,label:string,url:string,is_active:bool,description:string,sort_order:int}>
     */
    public function items(string $currentPath): array
    {
        $url = '/admin/news';

        return [[
            'key' => 'modulnest.news',
            'label' => 'News',
            'url' => $url,
            'description' => 'News und Updates verwalten',
            'is_active' => $this->isActive($url, $currentPath),
            'sort_order' => 30,
        ]];
    }

    private function isActive(string $url, string $currentPath): bool
    {
        $target = rtrim('/' . trim($url, '/'), '/');
        $current = rtrim('/' . trim($currentPath, '/'), '/');

        return $current === $target || str_starts_with($current, $target . '/');
    }
}
