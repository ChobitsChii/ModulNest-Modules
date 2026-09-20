<?php

declare(strict_types=1);

namespace ModulNest\DataPortability;

use Modulon\Core\UserNavigationProviderInterface;

final class DataPortabilityUserNavigationProvider implements UserNavigationProviderInterface
{
    public function moduleKey(): string
    {
        return 'data-portability';
    }

    public function items(string $currentPath): array
    {
        return [
            [
                'key' => 'data-portability',
                'label' => 'Meine Daten',
                'url' => '/profil/data-portability',
                'is_active' => $this->isActive($currentPath),
                'sort_order' => 35,
            ],
        ];
    }

    private function isActive(string $currentPath): bool
    {
        $current = rtrim('/' . trim($currentPath, '/'), '/');

        return $current === '/profil/data-portability';
    }
}
