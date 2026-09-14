<?php

declare(strict_types=1);

namespace ModulNest\FantasyCards;

use Modulon\Core\AdminNavigationProviderInterface;

final class FantasyCardsAdminNavigationProvider implements AdminNavigationProviderInterface
{
    public function moduleKey(): string
    {
        return 'fantasy-cards';
    }

    public function items(string $currentPath): array
    {
        $base = '/admin/fantasy-cards';

        return [[
            'key' => 'fantasy-cards',
            'label' => 'Fantasy Cards',
            'url' => $base,
            'description' => 'Karten-Sets, Karten und Booster-Grunddaten verwalten',
            'is_active' => $this->isActive($base, $currentPath),
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
