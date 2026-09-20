<?php

declare(strict_types=1);

namespace ModulNest\DataPortability;

use Modulon\Core\AdminNavigationProviderInterface;

final class DataPortabilityAdminNavigationProvider implements AdminNavigationProviderInterface
{
    public function moduleKey(): string
    {
        return 'data-portability';
    }

    /**
     * @return array<int, array{key:string,label:string,url:string,is_active:bool,description:string,sort_order:int}>
     */
    public function items(string $currentPath): array
    {
        $url = '/admin/data-portability';

        return [[
            'key' => 'data-portability',
            'label' => 'Export / Import',
            'url' => $url,
            'is_active' => $this->isActive($url, $currentPath),
            'description' => 'Moduldaten zwischen ModulNest-Instanzen übertragen.',
            'sort_order' => 35,
        ]];
    }

    private function isActive(string $url, string $currentPath): bool
    {
        $target = rtrim('/' . trim($url, '/'), '/');
        $current = rtrim('/' . trim($currentPath, '/'), '/');

        return $current === $target || str_starts_with($current, $target . '/');
    }
}
