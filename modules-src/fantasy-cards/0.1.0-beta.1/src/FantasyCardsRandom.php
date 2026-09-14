<?php

declare(strict_types=1);

namespace ModulNest\FantasyCards;

final class FantasyCardsRandom
{
    /**
     * @param array<string, int> $weights
     */
    public function weightedKey(array $weights): string
    {
        $valid = array_filter($weights, static fn (int $weight): bool => $weight > 0);
        if ($valid === []) {
            return 'common';
        }

        $sum = array_sum($valid);
        $roll = random_int(1, $sum);
        $current = 0;
        foreach ($valid as $key => $weight) {
            $current += $weight;
            if ($roll <= $current) {
                return (string) $key;
            }
        }

        return (string) array_key_first($valid);
    }
}
