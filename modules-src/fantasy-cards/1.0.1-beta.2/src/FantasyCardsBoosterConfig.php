<?php

declare(strict_types=1);

namespace ModulNest\FantasyCards;

final class FantasyCardsBoosterConfig
{
    public const FREE_CLAIM_INTERVAL_SECONDS = 43_200;
    public const MAX_FREE_CLAIMS = 3;
    public const DEFAULT_CARDS_PER_PACK = 5;

    /**
     * @return array<string, int>
     */
    public static function rarityWeights(): array
    {
        return [
            'common' => 600,
            'uncommon' => 250,
            'rare' => 100,
            'epic' => 35,
            'legendary' => 12,
            'mythic' => 3,
        ];
    }
}
