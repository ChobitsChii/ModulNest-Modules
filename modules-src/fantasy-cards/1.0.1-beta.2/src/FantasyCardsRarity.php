<?php

declare(strict_types=1);

namespace ModulNest\FantasyCards;

final class FantasyCardsRarity
{
    /**
     * @return array<string, array{label:string,class:string,badge:string}>
     */
    public static function all(): array
    {
        return [
            'common' => ['label' => 'Gewöhnlich', 'class' => 'fantasycards-rarity-common', 'badge' => 'text-bg-secondary'],
            'uncommon' => ['label' => 'Ungewöhnlich', 'class' => 'fantasycards-rarity-uncommon', 'badge' => 'text-bg-success'],
            'rare' => ['label' => 'Selten', 'class' => 'fantasycards-rarity-rare', 'badge' => 'text-bg-primary'],
            'epic' => ['label' => 'Episch', 'class' => 'fantasycards-rarity-epic', 'badge' => 'text-bg-info'],
            'legendary' => ['label' => 'Legendär', 'class' => 'fantasycards-rarity-legendary', 'badge' => 'text-bg-warning'],
            'mythic' => ['label' => 'Mythisch', 'class' => 'fantasycards-rarity-mythic', 'badge' => 'text-bg-danger'],
        ];
    }

    public static function normalize(string $rarity): string
    {
        $rarity = strtolower(trim($rarity));
        return array_key_exists($rarity, self::all()) ? $rarity : 'common';
    }

    /**
     * @return array{label:string,class:string,badge:string}
     */
    public static function get(string $rarity): array
    {
        $normalized = self::normalize($rarity);
        return self::all()[$normalized];
    }
}
