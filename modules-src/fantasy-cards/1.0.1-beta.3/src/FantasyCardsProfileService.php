<?php

declare(strict_types=1);

namespace ModulNest\FantasyCards;

use PDO;

final class FantasyCardsProfileService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly FantasyCardsRepository $repository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function profileData(int $userId): array
    {
        $ownedCards = array_values(array_filter(
            $this->repository->collectionForUser($userId, null),
            static fn (array $card): bool => (int) ($card['owned_quantity'] ?? 0) > 0,
        ));
        $settings = $this->settings($userId);
        $showcase = $this->manualShowcase($userId);

        if ($showcase === []) {
            $showcase = $this->rarestCards($ownedCards, 5);
        }

        return [
            'settings' => $settings,
            'owned_cards' => $ownedCards,
            'favorite_card' => $this->favoriteCard($ownedCards, (int) ($settings['favorite_card_id'] ?? 0)),
            'manual_showcase' => $this->manualShowcase($userId),
            'showcase_cards' => $showcase,
            'rarest_cards' => $this->rarestCards($ownedCards, 5),
            'latest_pulls' => $this->latestCards($ownedCards, 5),
            'completed_sets' => $this->completedSets($this->repository->setProgressForUser($userId)),
            'progress' => $this->progress($this->repository->setProgressForUser($userId)),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function saveProfile(int $userId, array $payload): void
    {
        $favoriteCardId = (int) ($payload['favorite_card_id'] ?? 0);
        if ($favoriteCardId > 0 && !$this->userOwnsCard($userId, $favoriteCardId)) {
            $favoriteCardId = 0;
        }

        $showcaseMode = (string) ($payload['showcase_mode'] ?? 'manual');
        if (!in_array($showcaseMode, ['manual', 'rarest', 'latest', 'completed'], true)) {
            $showcaseMode = 'manual';
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO fantasy_card_profile_settings
                (user_id, favorite_card_id, showcase_mode, is_collection_public, is_progress_public, is_favorites_public)
             VALUES
                (:user_id, :favorite_card_id, :showcase_mode, :is_collection_public, :is_progress_public, :is_favorites_public)
             ON DUPLICATE KEY UPDATE
                favorite_card_id = VALUES(favorite_card_id),
                showcase_mode = VALUES(showcase_mode),
                is_collection_public = VALUES(is_collection_public),
                is_progress_public = VALUES(is_progress_public),
                is_favorites_public = VALUES(is_favorites_public),
                updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'user_id' => $userId,
            'favorite_card_id' => $favoriteCardId > 0 ? $favoriteCardId : null,
            'showcase_mode' => $showcaseMode,
            'is_collection_public' => !empty($payload['is_collection_public']) ? 1 : 0,
            'is_progress_public' => !empty($payload['is_progress_public']) ? 1 : 0,
            'is_favorites_public' => !empty($payload['is_favorites_public']) ? 1 : 0,
        ]);

        $ids = array_values(array_unique(array_filter(array_map('intval', $payload['showcase_card_ids'] ?? []), static fn (int $id): bool => $id > 0)));
        $this->saveShowcase($userId, array_slice($ids, 0, 5));
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(int $userId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM fantasy_card_profile_settings WHERE user_id = :user_id LIMIT 1');
        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : [
            'user_id' => $userId,
            'favorite_card_id' => null,
            'showcase_mode' => 'manual',
            'is_collection_public' => 0,
            'is_progress_public' => 0,
            'is_favorites_public' => 0,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function manualShowcase(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.*, s.name AS set_name, s.slug AS set_slug, uc.quantity AS owned_quantity, sc.slot
             FROM fantasy_card_profile_showcase_cards sc
             INNER JOIN cards c ON c.id = sc.card_id
             INNER JOIN card_sets s ON s.id = c.set_id
             INNER JOIN user_cards uc ON uc.card_id = c.id AND uc.user_id = sc.user_id
             WHERE sc.user_id = :user_id
               AND c.status = \'active\'
               AND c.is_active = 1
             ORDER BY sc.slot ASC'
        );
        $statement->execute(['user_id' => $userId]);
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<int, int> $cardIds
     */
    private function saveShowcase(int $userId, array $cardIds): void
    {
        $this->pdo->prepare('DELETE FROM fantasy_card_profile_showcase_cards WHERE user_id = :user_id')->execute(['user_id' => $userId]);
        if ($cardIds === []) {
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO fantasy_card_profile_showcase_cards (user_id, card_id, slot)
             VALUES (:user_id, :card_id, :slot)'
        );
        $slot = 1;
        foreach ($cardIds as $cardId) {
            if (!$this->userOwnsCard($userId, $cardId)) {
                continue;
            }
            $insert->execute(['user_id' => $userId, 'card_id' => $cardId, 'slot' => $slot]);
            $slot++;
        }
    }

    private function userOwnsCard(int $userId, int $cardId): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM user_cards WHERE user_id = :user_id AND card_id = :card_id AND quantity > 0 LIMIT 1');
        $statement->execute(['user_id' => $userId, 'card_id' => $cardId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param array<int, array<string, mixed>> $cards
     * @return array<string, mixed>|null
     */
    private function favoriteCard(array $cards, int $cardId): ?array
    {
        foreach ($cards as $card) {
            if ((int) ($card['id'] ?? 0) === $cardId) {
                return $card;
            }
        }

        return $cards[0] ?? null;
    }

    /**
     * @param array<int, array<string, mixed>> $cards
     * @return array<int, array<string, mixed>>
     */
    private function rarestCards(array $cards, int $limit): array
    {
        $rank = ['common' => 1, 'uncommon' => 2, 'rare' => 3, 'epic' => 4, 'legendary' => 5, 'mythic' => 6];
        usort($cards, static function (array $a, array $b) use ($rank): int {
            $rarityDiff = ($rank[FantasyCardsRarity::normalize((string) ($b['rarity'] ?? 'common'))] ?? 0)
                <=> ($rank[FantasyCardsRarity::normalize((string) ($a['rarity'] ?? 'common'))] ?? 0);
            if ($rarityDiff !== 0) {
                return $rarityDiff;
            }
            return strcmp((string) ($b['last_obtained_at'] ?? ''), (string) ($a['last_obtained_at'] ?? ''));
        });

        return array_slice($cards, 0, $limit);
    }

    /**
     * @param array<int, array<string, mixed>> $cards
     * @return array<int, array<string, mixed>>
     */
    private function latestCards(array $cards, int $limit): array
    {
        usort($cards, static fn (array $a, array $b): int => strcmp((string) ($b['last_obtained_at'] ?? ''), (string) ($a['last_obtained_at'] ?? '')));
        return array_slice($cards, 0, $limit);
    }

    /**
     * @param array<int, array<string, mixed>> $progress
     * @return array<int, array<string, mixed>>
     */
    private function completedSets(array $progress): array
    {
        return array_values(array_filter($this->progress($progress), static fn (array $set): bool => (int) ($set['total_cards'] ?? 0) > 0 && (int) ($set['owned_cards'] ?? 0) >= (int) ($set['total_cards'] ?? 0)));
    }

    /**
     * @param array<int, array<string, mixed>> $progress
     * @return array<int, array<string, mixed>>
     */
    private function progress(array $progress): array
    {
        foreach ($progress as &$set) {
            $total = (int) ($set['total_cards'] ?? 0);
            $owned = (int) ($set['owned_cards'] ?? 0);
            $set['percent'] = $total > 0 ? (int) round(($owned / $total) * 100) : 0;
        }
        unset($set);
        return $progress;
    }
}
