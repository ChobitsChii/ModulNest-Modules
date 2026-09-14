<?php

declare(strict_types=1);

namespace ModulNest\FantasyCards;

use RuntimeException;

final class FantasyCardsBoosterService
{
    public function __construct(
        private readonly FantasyCardsRepository $repository,
        private readonly FantasyCardsRandom $random = new FantasyCardsRandom(),
    ) {
    }

    /**
     * @return array{state:array<string,mixed>,sets:array<int,array<string,mixed>>,inventory:array<int,array<string,mixed>>,history:array<int,array<string,mixed>>,config:array<string,mixed>}
     */
    public function dashboard(int $userId): array
    {
        return [
            'state' => $this->syncFreeClaims($userId),
            'sets' => $this->repository->freePackSets(),
            'inventory' => $this->repository->boosterInventory($userId),
            'history' => $this->repository->openingHistory($userId, 10),
            'config' => $this->config(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function syncFreeClaims(int $userId): array
    {
        $state = $this->repository->userState($userId);
        $claims = (int) ($state['free_claims'] ?? 0);
        $last = strtotime((string) ($state['last_free_claim_at'] ?? '')) ?: time();
        $now = time();

        if ($claims < FantasyCardsBoosterConfig::MAX_FREE_CLAIMS) {
            $elapsed = max(0, $now - $last);
            $earned = intdiv($elapsed, FantasyCardsBoosterConfig::FREE_CLAIM_INTERVAL_SECONDS);
            if ($earned > 0) {
                $claims = min(FantasyCardsBoosterConfig::MAX_FREE_CLAIMS, $claims + $earned);
                $last = $claims >= FantasyCardsBoosterConfig::MAX_FREE_CLAIMS
                    ? $now
                    : $last + ($earned * FantasyCardsBoosterConfig::FREE_CLAIM_INTERVAL_SECONDS);
                $this->repository->updateUserState($userId, $claims, gmdate('Y-m-d H:i:s', $last));
            }
        }

        return [
            'free_claims' => $claims,
            'last_free_claim_at' => gmdate('Y-m-d H:i:s', $last),
            'next_claim_at' => $claims >= FantasyCardsBoosterConfig::MAX_FREE_CLAIMS
                ? null
                : gmdate('Y-m-d H:i:s', $last + FantasyCardsBoosterConfig::FREE_CLAIM_INTERVAL_SECONDS),
            'max_free_claims' => FantasyCardsBoosterConfig::MAX_FREE_CLAIMS,
            'interval_seconds' => FantasyCardsBoosterConfig::FREE_CLAIM_INTERVAL_SECONDS,
        ];
    }

    public function claimFreeBooster(int $userId, int $setId): string
    {
        return $this->repository->transaction(function () use ($userId, $setId): string {
            $state = $this->syncFreeClaims($userId);
            $claims = (int) ($state['free_claims'] ?? 0);
            if ($claims <= 0) {
                throw new RuntimeException('Aktuell ist kein Free-Pack-Claim verfügbar.');
            }

            $set = null;
            foreach ($this->repository->freePackSets() as $candidate) {
                if ((int) ($candidate['id'] ?? 0) === $setId) {
                    $set = $candidate;
                    break;
                }
            }
            if ($set === null) {
                throw new RuntimeException('Dieses Set ist für Free Packs nicht verfügbar.');
            }

            $boosterTypeId = $this->repository->ensureFreeBoosterTypeForSet($set);
            $this->repository->incrementBoosterInventory($userId, $boosterTypeId, 1);
            $this->repository->updateUserState(
                $userId,
                $claims - 1,
                (string) ($state['last_free_claim_at'] ?? gmdate('Y-m-d H:i:s')),
            );

            return 'Free Booster für ' . (string) ($set['name'] ?? 'Set') . ' erhalten.';
        });
    }

    /**
     * @return array{opening_id:int,booster:array<string,mixed>,cards:array<int,array<string,mixed>>}
     */
    public function openBooster(int $userId, int $boosterTypeId): array
    {
        return $this->repository->transaction(function () use ($userId, $boosterTypeId): array {
            $booster = $this->repository->boosterInventoryItem($userId, $boosterTypeId);
            if ($booster === null) {
                throw new RuntimeException('Booster nicht gefunden oder bereits geöffnet.');
            }

            $setId = (int) ($booster['set_id'] ?? 0);
            $eligible = $this->repository->eligibleBoosterCards($setId);
            if ($eligible === []) {
                throw new RuntimeException('Für dieses Set sind keine aktiven Booster-Karten verfügbar.');
            }

            if (!$this->repository->decrementBoosterInventory($userId, $boosterTypeId)) {
                throw new RuntimeException('Booster konnte nicht aus dem Inventar entnommen werden.');
            }

            $cards = $this->drawCards($eligible, (int) ($booster['cards_per_pack'] ?? FantasyCardsBoosterConfig::DEFAULT_CARDS_PER_PACK));
            $openingId = $this->repository->recordOpening($userId, $booster, $cards);

            return [
                'opening_id' => $openingId,
                'booster' => $booster,
                'cards' => $cards,
            ];
        });
    }

    /**
     * @return array{sets:array<int,array<string,mixed>>,cards:array<int,array<string,mixed>>,progress:array<int,array<string,mixed>>,selected_set_id:int,rarities:array<string,array<string,string>>}
     */
    public function collection(int $userId, ?int $setId): array
    {
        $allCards = $this->repository->collectionForUser($userId, null);

        return [
            'sets' => $this->repository->listActiveSets(),
            'cards' => $setId === null ? $allCards : array_values(array_filter(
                $allCards,
                static fn (array $card): bool => (int) ($card['set_id'] ?? 0) === $setId,
            )),
            'progress' => $this->decorateProgress($this->repository->setProgressForUser($userId), $allCards),
            'selected_set_id' => $setId ?? 0,
            'rarities' => FantasyCardsRarity::all(),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $eligible
     * @return array<int, array<string, mixed>>
     */
    private function drawCards(array $eligible, int $count): array
    {
        $count = max(1, min(20, $count));
        $byRarity = [];
        foreach ($eligible as $card) {
            $byRarity[FantasyCardsRarity::normalize((string) ($card['rarity'] ?? 'common'))][] = $card;
        }

        $cards = [];
        for ($i = 0; $i < $count; $i++) {
            $rarity = $this->random->weightedKey(FantasyCardsBoosterConfig::rarityWeights());
            $pool = $byRarity[$rarity] ?? $eligible;
            if ($pool === []) {
                $pool = $eligible;
            }
            $cards[] = $pool[random_int(0, count($pool) - 1)];
        }

        return $cards;
    }

    /**
     * @param array<int, array<string, mixed>> $progress
     * @param array<int, array<string, mixed>> $cards
     * @return array<int, array<string, mixed>>
     */
    private function decorateProgress(array $progress, array $cards): array
    {
        $rarityRank = [
            'common' => 1,
            'uncommon' => 2,
            'rare' => 3,
            'epic' => 4,
            'legendary' => 5,
            'mythic' => 6,
        ];
        $bySet = [];
        foreach ($cards as $card) {
            if ((int) ($card['owned_quantity'] ?? 0) <= 0) {
                continue;
            }
            $setId = (int) ($card['set_id'] ?? 0);
            $rank = $rarityRank[FantasyCardsRarity::normalize((string) ($card['rarity'] ?? 'common'))] ?? 0;
            $last = strtotime((string) ($card['last_obtained_at'] ?? '')) ?: 0;
            if (!isset($bySet[$setId]['rarest_rank']) || $rank > (int) $bySet[$setId]['rarest_rank']) {
                $bySet[$setId]['rarest_rank'] = $rank;
                $bySet[$setId]['rarest_card'] = $card;
            }
            if (!isset($bySet[$setId]['last_ts']) || $last > (int) $bySet[$setId]['last_ts']) {
                $bySet[$setId]['last_ts'] = $last;
                $bySet[$setId]['last_card'] = $card;
            }
        }

        foreach ($progress as &$set) {
            $total = (int) ($set['total_cards'] ?? 0);
            $owned = (int) ($set['owned_cards'] ?? 0);
            $setId = (int) ($set['id'] ?? 0);
            $set['percent'] = $total > 0 ? (int) round(($owned / $total) * 100) : 0;
            $set['rarest_card_name'] = (string) ($bySet[$setId]['rarest_card']['name'] ?? '');
            $set['rarest_card_rarity'] = (string) ($bySet[$setId]['rarest_card']['rarity'] ?? '');
            $set['last_card_name'] = (string) ($bySet[$setId]['last_card']['name'] ?? '');
        }
        unset($set);

        return $progress;
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return [
            'free_claim_interval_seconds' => FantasyCardsBoosterConfig::FREE_CLAIM_INTERVAL_SECONDS,
            'max_free_claims' => FantasyCardsBoosterConfig::MAX_FREE_CLAIMS,
            'cards_per_pack' => FantasyCardsBoosterConfig::DEFAULT_CARDS_PER_PACK,
            'rarity_weights' => FantasyCardsBoosterConfig::rarityWeights(),
        ];
    }
}
