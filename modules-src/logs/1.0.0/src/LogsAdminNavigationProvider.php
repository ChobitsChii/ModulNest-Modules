<?php

declare(strict_types=1);

namespace ModulNest\Logs;

use Modulon\Core\AdminNavigationProviderInterface;

final class LogsAdminNavigationProvider implements AdminNavigationProviderInterface
{
    public function moduleKey(): string
    {
        return 'modulnest.logs';
    }

    /**
     * @return array<int, array{key:string,label:string,url:string,is_active:bool,description:string,sort_order:int}>
     */
    public function items(string $currentPath): array
    {
        $url = '/admin/logs';

        return [[
            'key' => 'modulnest.logs',
            'label' => 'Logs',
            'url' => $url,
            'description' => 'Logdateien ansehen',
            'is_active' => $this->isActive($url, $currentPath),
            'sort_order' => 80,
        ]];
    }

    private function isActive(string $url, string $currentPath): bool
    {
        $target = rtrim('/' . trim($url, '/'), '/');
        $current = rtrim('/' . trim($currentPath, '/'), '/');

        return $current === $target || str_starts_with($current, $target . '/');
    }
}
