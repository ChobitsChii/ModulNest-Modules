<?php

declare(strict_types=1);

namespace ModulNest\FantasyCards;

use Modulon\Core\ModuleSubnavigationProviderInterface;

final class FantasyCardsSubnavigationProvider implements ModuleSubnavigationProviderInterface
{
    public function moduleKey(): string
    {
        return 'fantasy-cards';
    }

    public function items(string $currentPath): array
    {
        $current = rtrim('/' . trim($currentPath, '/'), '/');

        return [
            [
                'key' => 'sets',
                'label' => 'Sets',
                'url' => '/fantasy-cards',
                'description' => 'Aktive Karten-Sets',
                'is_active' => $current === '/fantasy-cards' || str_starts_with($current, '/fantasy-cards/set/'),
            ],
            [
                'key' => 'collection',
                'label' => 'Sammlung',
                'url' => '/fantasy-cards/collection',
                'description' => 'Eigene Karten und Set-Fortschritt',
                'is_active' => $current === '/fantasy-cards/collection',
            ],
            [
                'key' => 'boosters',
                'label' => 'Booster',
                'url' => '/fantasy-cards/boosters',
                'description' => 'Free Packs claimen und Booster öffnen',
                'is_active' => $current === '/fantasy-cards/boosters',
            ],
        ];
    }
}
