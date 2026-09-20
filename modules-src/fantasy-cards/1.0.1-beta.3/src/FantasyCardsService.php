<?php

declare(strict_types=1);

namespace ModulNest\FantasyCards;

final class FantasyCardsService
{
    public function __construct(private readonly FantasyCardsRepository $cards)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function activeSets(): array
    {
        return $this->cards->listActiveSets();
    }

    /**
     * @return array{set: array<string, mixed>, cards: array<int, array<string, mixed>>}|null
     */
    public function activeSetDetail(string $slug): ?array
    {
        $set = $this->cards->findSetBySlug($slug, true);
        if ($set === null) {
            return null;
        }

        return [
            'set' => $set,
            'cards' => $this->cards->listCardsForSet((int) $set['id'], true),
        ];
    }
}
