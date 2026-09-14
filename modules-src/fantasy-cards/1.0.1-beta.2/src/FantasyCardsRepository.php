<?php

declare(strict_types=1);

namespace ModulNest\FantasyCards;

use PDO;

final class FantasyCardsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listActiveSets(): array
    {
        $statement = $this->pdo->query(
            "SELECT s.*, COUNT(c.id) AS card_count
             FROM card_sets s
             LEFT JOIN cards c ON c.set_id = s.id AND c.is_active = 1 AND c.status = 'active'
             WHERE s.is_active = 1
             GROUP BY s.id
             ORDER BY s.sort_order ASC, s.name ASC"
        );

        $rows = $statement->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSetsForAdmin(): array
    {
        $statement = $this->pdo->query(
            'SELECT s.*, COUNT(c.id) AS card_count
             FROM card_sets s
             LEFT JOIN cards c ON c.set_id = s.id
             GROUP BY s.id
             ORDER BY s.sort_order ASC, s.id ASC'
        );

        $rows = $statement->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    public function findSetBySlug(string $slug, bool $activeOnly = true): ?array
    {
        $sql = 'SELECT * FROM card_sets WHERE slug = :slug';
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' LIMIT 1';
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['slug' => $slug]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function findSetById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM card_sets WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function setSlugExists(string $slug, int $excludeId = 0): bool
    {
        $statement = $this->pdo->prepare('SELECT id FROM card_sets WHERE slug = :slug AND id <> :id LIMIT 1');
        $statement->execute(['slug' => $slug, 'id' => $excludeId]);
        return $statement->fetch() !== false;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveSet(array $data, ?int $id = null): int
    {
        if ($id === null) {
            $statement = $this->pdo->prepare(
                'INSERT INTO card_sets
                    (uuid, slug, name, description, cover_image, is_active, available_in_free_packs, sort_order)
                 VALUES
                    (:uuid, :slug, :name, :description, :cover_image, :is_active, :available_in_free_packs, :sort_order)'
            );
            $statement->execute([
                'uuid' => $data['uuid'],
                'slug' => $data['slug'],
                'name' => $data['name'],
                'description' => $data['description'],
                'cover_image' => $data['cover_image'],
                'is_active' => $data['is_active'],
                'available_in_free_packs' => $data['available_in_free_packs'],
                'sort_order' => $data['sort_order'],
            ]);

            return (int) $this->pdo->lastInsertId();
        }

        $statement = $this->pdo->prepare(
            'UPDATE card_sets
             SET slug = :slug,
                 name = :name,
                 description = :description,
                 cover_image = :cover_image,
                 is_active = :is_active,
                 available_in_free_packs = :available_in_free_packs,
                 sort_order = :sort_order,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $id,
            'slug' => $data['slug'],
            'name' => $data['name'],
            'description' => $data['description'],
            'cover_image' => $data['cover_image'],
            'is_active' => $data['is_active'],
            'available_in_free_packs' => $data['available_in_free_packs'],
            'sort_order' => $data['sort_order'],
        ]);

        return $id;
    }

    public function toggleSet(int $id, string $field, bool $enabled): bool
    {
        if (!in_array($field, ['is_active', 'available_in_free_packs'], true)) {
            return false;
        }

        $statement = $this->pdo->prepare(
            "UPDATE card_sets SET {$field} = :enabled, updated_at = CURRENT_TIMESTAMP WHERE id = :id"
        );
        $statement->execute(['id' => $id, 'enabled' => $enabled ? 1 : 0]);

        return $statement->rowCount() > 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listCardsForSet(int $setId, bool $activeOnly = true): array
    {
        $sql = 'SELECT c.*, s.name AS set_name, s.slug AS set_slug
                FROM cards c
                INNER JOIN card_sets s ON s.id = c.set_id
                WHERE c.set_id = :set_id';
        if ($activeOnly) {
            $sql .= " AND c.is_active = 1 AND c.status = 'active'";
        }
        $sql .= ' ORDER BY c.sort_order ASC, c.card_number ASC, c.name ASC';
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['set_id' => $setId]);
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function countCardsForAdmin(?int $setId, string $search, string $status = ''): int
    {
        $params = [];
        $sql = 'SELECT COUNT(*)
                FROM cards c
                WHERE 1 = 1';
        $this->appendCardFilters($sql, $params, $setId, $search, $status);
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listCardsForAdmin(?int $setId, string $search, string $status = '', int $limit = 50, int $offset = 0): array
    {
        $params = [];
        $sql = 'SELECT c.*, s.name AS set_name, s.slug AS set_slug
                FROM cards c
                INNER JOIN card_sets s ON s.id = c.set_id
                WHERE 1 = 1';
        $this->appendCardFilters($sql, $params, $setId, $search, $status);

        $sql .= ' ORDER BY s.sort_order ASC, c.sort_order ASC, c.id ASC LIMIT :limit OFFSET :offset';
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->bindValue('limit', max(1, min(200, $limit)), PDO::PARAM_INT);
        $statement->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function appendCardFilters(string &$sql, array &$params, ?int $setId, string $search, string $status): void
    {
        if ($setId !== null && $setId > 0) {
            $sql .= ' AND c.set_id = :set_id';
            $params['set_id'] = $setId;
        }

        if ($search !== '') {
            $sql .= ' AND (c.name LIKE :search OR c.card_number LIKE :search OR c.description LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        if (in_array($status, ['draft', 'active', 'retired'], true)) {
            $sql .= ' AND c.status = :status';
            $params['status'] = $status;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listCardsByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(
            "SELECT c.*, s.name AS set_name, s.slug AS set_slug
             FROM cards c
             INNER JOIN card_sets s ON s.id = c.set_id
             WHERE c.id IN ({$placeholders})
             ORDER BY FIELD(c.id, {$placeholders})"
        );
        $statement->execute(array_merge($ids, $ids));
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function nextCardSortOrder(int $setId): int
    {
        $statement = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM cards WHERE set_id = :set_id');
        $statement->execute(['set_id' => $setId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array<int, array<string, mixed>> $cards
     * @return array<int, int>
     */
    public function createCards(array $cards): array
    {
        if ($cards === []) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO cards
                (uuid, set_id, card_number, slug, name, description, rarity, faction, element_name, image_path, thumbnail_path, status, is_active, available_in_boosters, sort_order)
             VALUES
                (:uuid, :set_id, :card_number, :slug, :name, :description, :rarity, :faction, :element_name, :image_path, :thumbnail_path, :status, :is_active, :available_in_boosters, :sort_order)'
        );

        $ids = [];
        foreach ($cards as $card) {
            $statement->execute($card);
            $ids[] = (int) $this->pdo->lastInsertId();
        }

        return $ids;
    }

    public function updateCardField(int $cardId, string $field, mixed $value): bool
    {
        $allowed = [
            'name',
            'description',
            'rarity',
            'faction',
            'element_name',
            'card_number',
            'status',
            'is_active',
            'available_in_boosters',
            'sort_order',
        ];
        if (!in_array($field, $allowed, true)) {
            return false;
        }

        $statement = $this->pdo->prepare(
            "UPDATE cards SET {$field} = :value, updated_at = CURRENT_TIMESTAMP WHERE id = :id"
        );
        $statement->execute(['value' => $value, 'id' => $cardId]);

        return $statement->rowCount() > 0;
    }

    /**
     * @param array<int, int> $cardIds
     * @return array{matched:int,changed:int}
     */
    public function bulkUpdateCards(array $cardIds, string $field, mixed $value): array
    {
        $allowed = ['status', 'is_active', 'available_in_boosters'];
        $cardIds = $this->normalizeIds($cardIds);
        if ($cardIds === [] || !in_array($field, $allowed, true)) {
            return ['matched' => 0, 'changed' => 0];
        }

        $placeholders = implode(',', array_fill(0, count($cardIds), '?'));
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM cards WHERE id IN ({$placeholders})");
        $count->execute($cardIds);
        $matched = (int) $count->fetchColumn();

        $statement = $this->pdo->prepare(
            "UPDATE cards SET {$field} = ?, updated_at = CURRENT_TIMESTAMP WHERE id IN ({$placeholders})"
        );
        $statement->execute(array_merge([$value], $cardIds));

        return ['matched' => $matched, 'changed' => $statement->rowCount()];
    }

    /**
     * @param array<int, int> $cardIds
     * @return array{deleted:int,skipped:int,deleted_cards:array<int,array<string,mixed>>}
     */
    public function bulkDeleteCards(array $cardIds): array
    {
        $cardIds = $this->normalizeIds($cardIds);
        if ($cardIds === []) {
            return ['deleted' => 0, 'skipped' => 0, 'deleted_cards' => []];
        }

        $placeholders = implode(',', array_fill(0, count($cardIds), '?'));
        $deletable = $this->pdo->prepare(
            "SELECT c.*
             FROM cards c
             WHERE c.id IN ({$placeholders})
               AND NOT EXISTS (
                   SELECT 1
                   FROM fantasy_card_booster_opening_cards oc
                   WHERE oc.card_id = c.id
               )"
        );
        $deletable->execute($cardIds);
        $cards = $deletable->fetchAll();
        $cards = is_array($cards) ? $cards : [];
        $deleteIds = array_values(array_map(static fn (array $card): int => (int) ($card['id'] ?? 0), $cards));

        if ($deleteIds !== []) {
            $deletePlaceholders = implode(',', array_fill(0, count($deleteIds), '?'));
            $delete = $this->pdo->prepare("DELETE FROM cards WHERE id IN ({$deletePlaceholders})");
            $delete->execute($deleteIds);
        }

        return [
            'deleted' => count($deleteIds),
            'skipped' => max(0, count($cardIds) - count($deleteIds)),
            'deleted_cards' => $cards,
        ];
    }

    /**
     * @param array<int, int> $cardIds
     */
    public function reorderCards(int $setId, array $cardIds): void
    {
        $cardIds = $this->normalizeIds($cardIds);
        if ($cardIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($cardIds), '?'));
        $statement = $this->pdo->prepare("SELECT id FROM cards WHERE set_id = ? AND id IN ({$placeholders})");
        $statement->execute(array_merge([$setId], $cardIds));
        $owned = array_fill_keys(array_map('intval', array_column($statement->fetchAll() ?: [], 'id')), true);

        $update = $this->pdo->prepare('UPDATE cards SET sort_order = :sort_order, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND set_id = :set_id');
        $sort = 10;
        foreach ($cardIds as $cardId) {
            if (!isset($owned[$cardId])) {
                continue;
            }
            $update->execute(['sort_order' => $sort, 'id' => $cardId, 'set_id' => $setId]);
            $sort += 10;
        }
    }

    public function findCardById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.*, s.name AS set_name
             FROM cards c
             INNER JOIN card_sets s ON s.id = c.set_id
             WHERE c.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function cardSlugExists(int $setId, string $slug, int $excludeId = 0): bool
    {
        $statement = $this->pdo->prepare('SELECT id FROM cards WHERE set_id = :set_id AND slug = :slug AND id <> :id LIMIT 1');
        $statement->execute(['set_id' => $setId, 'slug' => $slug, 'id' => $excludeId]);
        return $statement->fetch() !== false;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveCard(array $data, ?int $id = null): int
    {
        if ($id === null) {
            $statement = $this->pdo->prepare(
                'INSERT INTO cards
                (uuid, set_id, card_number, slug, name, description, rarity, faction, element_name, image_path, thumbnail_path, status, is_active, available_in_boosters, sort_order)
             VALUES
                (:uuid, :set_id, :card_number, :slug, :name, :description, :rarity, :faction, :element_name, :image_path, :thumbnail_path, :status, :is_active, :available_in_boosters, :sort_order)'
            );
            $statement->execute($data);

            return (int) $this->pdo->lastInsertId();
        }

        unset($data['uuid']);
        $data['id'] = $id;
        $statement = $this->pdo->prepare(
            'UPDATE cards
             SET set_id = :set_id,
                 card_number = :card_number,
                 slug = :slug,
                 name = :name,
                 description = :description,
                 rarity = :rarity,
                 faction = :faction,
                 element_name = :element_name,
                 image_path = :image_path,
                 thumbnail_path = :thumbnail_path,
                 status = :status,
                 is_active = :is_active,
                 available_in_boosters = :available_in_boosters,
                 sort_order = :sort_order,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute($data);

        return $id;
    }

    public function toggleCard(int $id, string $field, bool $enabled): bool
    {
        if (!in_array($field, ['is_active', 'available_in_boosters'], true)) {
            return false;
        }

        $statement = $this->pdo->prepare(
            "UPDATE cards SET {$field} = :enabled, updated_at = CURRENT_TIMESTAMP WHERE id = :id"
        );
        $statement->execute(['id' => $id, 'enabled' => $enabled ? 1 : 0]);

        return $statement->rowCount() > 0;
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function userState(int $userId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM fantasy_card_user_state WHERE user_id = :user_id LIMIT 1');
        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch();
        if (is_array($row)) {
            return $row;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO fantasy_card_user_state (user_id, free_claims, last_free_claim_at)
             VALUES (:user_id, 1, UTC_TIMESTAMP())'
        );
        $insert->execute(['user_id' => $userId]);

        return [
            'user_id' => $userId,
            'free_claims' => 1,
            'last_free_claim_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    public function updateUserState(int $userId, int $freeClaims, string $lastClaimAt): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO fantasy_card_user_state (user_id, free_claims, last_free_claim_at)
             VALUES (:user_id, :free_claims, :last_free_claim_at)
             ON DUPLICATE KEY UPDATE
                free_claims = VALUES(free_claims),
                last_free_claim_at = VALUES(last_free_claim_at),
                updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'user_id' => $userId,
            'free_claims' => max(0, min(FantasyCardsBoosterConfig::MAX_FREE_CLAIMS, $freeClaims)),
            'last_free_claim_at' => $lastClaimAt,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function freePackSets(): array
    {
        $statement = $this->pdo->query(
            "SELECT s.*, COUNT(c.id) AS booster_card_count
             FROM card_sets s
             LEFT JOIN cards c
                ON c.set_id = s.id
               AND c.is_active = 1
               AND c.status = 'active'
               AND c.available_in_boosters = 1
             WHERE s.is_active = 1
               AND s.available_in_free_packs = 1
             GROUP BY s.id
             HAVING booster_card_count > 0
             ORDER BY s.sort_order ASC, s.name ASC"
        );

        $rows = $statement->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    public function ensureFreeBoosterTypeForSet(array $set): int
    {
        $setId = (int) ($set['id'] ?? 0);
        $statement = $this->pdo->prepare(
            'SELECT id FROM booster_types
             WHERE set_id = :set_id AND is_free_pack = 1
             LIMIT 1'
        );
        $statement->execute(['set_id' => $setId]);
        $existing = $statement->fetchColumn();
        if ($existing !== false) {
            return (int) $existing;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO booster_types
                (uuid, set_id, name, description, image_path, cards_per_pack, is_free_pack, is_active)
             VALUES
                (:uuid, :set_id, :name, :description, :image_path, :cards_per_pack, 1, 1)'
        );
        $insert->execute([
            'uuid' => $this->uuid(),
            'set_id' => $setId,
            'name' => (string) ($set['name'] ?? 'Free Booster'),
            'description' => 'Kostenloser Booster aus dem Set ' . (string) ($set['name'] ?? ''),
            'image_path' => (string) ($set['cover_image'] ?? ''),
            'cards_per_pack' => FantasyCardsBoosterConfig::DEFAULT_CARDS_PER_PACK,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function incrementBoosterInventory(int $userId, int $boosterTypeId, int $quantity): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO user_booster_inventory (user_id, booster_type_id, quantity)
             VALUES (:user_id, :booster_type_id, :quantity)
             ON DUPLICATE KEY UPDATE
                quantity = quantity + VALUES(quantity),
                updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'user_id' => $userId,
            'booster_type_id' => $boosterTypeId,
            'quantity' => max(0, $quantity),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function boosterInventory(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT i.*, b.name AS booster_name, b.description AS booster_description, b.image_path,
                    b.cards_per_pack, b.is_free_pack, b.set_id,
                    s.name AS set_name, s.slug AS set_slug
             FROM user_booster_inventory i
             INNER JOIN booster_types b ON b.id = i.booster_type_id
             LEFT JOIN card_sets s ON s.id = b.set_id
             WHERE i.user_id = :user_id
               AND i.quantity > 0
               AND b.is_active = 1
             ORDER BY s.sort_order ASC, b.name ASC'
        );
        $statement->execute(['user_id' => $userId]);
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function boosterInventoryItem(int $userId, int $boosterTypeId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT i.*, b.name AS booster_name, b.description AS booster_description, b.image_path,
                    b.cards_per_pack, b.set_id, s.name AS set_name, s.slug AS set_slug
             FROM user_booster_inventory i
             INNER JOIN booster_types b ON b.id = i.booster_type_id
             LEFT JOIN card_sets s ON s.id = b.set_id
             WHERE i.user_id = :user_id
               AND i.booster_type_id = :booster_type_id
               AND i.quantity > 0
               AND b.is_active = 1
             LIMIT 1'
        );
        $statement->execute(['user_id' => $userId, 'booster_type_id' => $boosterTypeId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function decrementBoosterInventory(int $userId, int $boosterTypeId): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE user_booster_inventory
             SET quantity = quantity - 1,
                 updated_at = CURRENT_TIMESTAMP
             WHERE user_id = :user_id
               AND booster_type_id = :booster_type_id
               AND quantity > 0'
        );
        $statement->execute(['user_id' => $userId, 'booster_type_id' => $boosterTypeId]);

        return $statement->rowCount() > 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function eligibleBoosterCards(int $setId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT c.*, s.name AS set_name, s.slug AS set_slug
             FROM cards c
             INNER JOIN card_sets s ON s.id = c.set_id
             WHERE c.set_id = :set_id
               AND c.is_active = 1
               AND c.status = 'active'
               AND c.available_in_boosters = 1
             ORDER BY c.sort_order ASC, c.id ASC"
        );
        $statement->execute(['set_id' => $setId]);
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<int, array<string, mixed>> $cards
     */
    public function recordOpening(int $userId, array $booster, array $cards): int
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO fantasy_card_booster_openings
                (uuid, user_id, booster_type_id, set_id, cards_count)
             VALUES
                (:uuid, :user_id, :booster_type_id, :set_id, :cards_count)'
        );
        $insert->execute([
            'uuid' => $this->uuid(),
            'user_id' => $userId,
            'booster_type_id' => (int) ($booster['booster_type_id'] ?? 0),
            'set_id' => (int) ($booster['set_id'] ?? 0) ?: null,
            'cards_count' => count($cards),
        ]);
        $openingId = (int) $this->pdo->lastInsertId();

        $openingCard = $this->pdo->prepare(
            'INSERT INTO fantasy_card_booster_opening_cards (opening_id, card_id, reveal_order)
             VALUES (:opening_id, :card_id, :reveal_order)'
        );
        $userCard = $this->pdo->prepare(
            'INSERT INTO user_cards (user_id, card_id, quantity, first_obtained_at, last_obtained_at)
             VALUES (:user_id, :card_id, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                quantity = quantity + 1,
                last_obtained_at = UTC_TIMESTAMP(),
                updated_at = CURRENT_TIMESTAMP'
        );

        $order = 1;
        foreach ($cards as $card) {
            $cardId = (int) ($card['id'] ?? 0);
            $openingCard->execute(['opening_id' => $openingId, 'card_id' => $cardId, 'reveal_order' => $order]);
            $userCard->execute(['user_id' => $userId, 'card_id' => $cardId]);
            $order++;
        }

        return $openingId;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function openingHistory(int $userId, int $limit = 20): array
    {
        $statement = $this->pdo->prepare(
            'SELECT o.*, b.name AS booster_name, s.name AS set_name
             FROM fantasy_card_booster_openings o
             INNER JOIN booster_types b ON b.id = o.booster_type_id
             LEFT JOIN card_sets s ON s.id = o.set_id
             WHERE o.user_id = :user_id
             ORDER BY o.opened_at DESC, o.id DESC
             LIMIT :limit'
        );
        $statement->bindValue('user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue('limit', max(1, min(100, $limit)), PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function collectionForUser(int $userId, ?int $setId = null): array
    {
        $params = ['user_id' => $userId];
        $sql = 'SELECT c.*, s.name AS set_name, s.slug AS set_slug,
                       COALESCE(uc.quantity, 0) AS owned_quantity,
                       uc.first_obtained_at,
                       uc.last_obtained_at
                FROM cards c
                INNER JOIN card_sets s ON s.id = c.set_id
                LEFT JOIN user_cards uc ON uc.card_id = c.id AND uc.user_id = :user_id
                WHERE c.status = \'active\'
                  AND c.is_active = 1';
        if ($setId !== null && $setId > 0) {
            $sql .= ' AND c.set_id = :set_id';
            $params['set_id'] = $setId;
        }
        $sql .= ' ORDER BY s.sort_order ASC, c.sort_order ASC, c.card_number ASC, c.id ASC';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function setProgressForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT s.id, s.slug, s.name,
                    COUNT(c.id) AS total_cards,
                    SUM(CASE WHEN COALESCE(uc.quantity, 0) > 0 THEN 1 ELSE 0 END) AS owned_cards,
                    MAX(uc.last_obtained_at) AS last_obtained_at
             FROM card_sets s
             LEFT JOIN cards c ON c.set_id = s.id AND c.status = \'active\' AND c.is_active = 1
             LEFT JOIN user_cards uc ON uc.card_id = c.id AND uc.user_id = :user_id
             WHERE s.is_active = 1
             GROUP BY s.id
             ORDER BY s.sort_order ASC, s.name ASC'
        );
        $statement->execute(['user_id' => $userId]);
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * @param array<int, int|string> $ids
     * @return array<int, int>
     */
    private function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    }
}
