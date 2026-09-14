-- Fantasy Cards Modul: Sammelkarten-Sets, Karten und Booster-Grunddaten
CREATE TABLE IF NOT EXISTS card_sets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE,
    slug VARCHAR(160) NOT NULL UNIQUE,
    name VARCHAR(160) NOT NULL,
    description TEXT NOT NULL,
    cover_image VARCHAR(255) NOT NULL DEFAULT '',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    available_in_free_packs TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_card_sets_active_sort (is_active, sort_order),
    INDEX idx_card_sets_free_packs (available_in_free_packs, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cards (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE,
    set_id BIGINT UNSIGNED NOT NULL,
    card_number VARCHAR(40) NOT NULL DEFAULT '',
    slug VARCHAR(160) NOT NULL,
    name VARCHAR(160) NOT NULL,
    description TEXT NOT NULL,
    rarity ENUM('common', 'uncommon', 'rare', 'epic', 'legendary', 'mythic') NOT NULL DEFAULT 'common',
    faction VARCHAR(120) NULL,
    element_name VARCHAR(120) NULL,
    image_path VARCHAR(255) NOT NULL DEFAULT '',
    thumbnail_path VARCHAR(255) NOT NULL DEFAULT '',
    status ENUM('draft', 'active', 'retired') NOT NULL DEFAULT 'draft',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    available_in_boosters TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_cards_set
        FOREIGN KEY (set_id) REFERENCES card_sets(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY uq_cards_set_slug (set_id, slug),
    INDEX idx_cards_set_active_sort (set_id, is_active, sort_order),
    INDEX idx_cards_set_status_sort (set_id, status, sort_order),
    INDEX idx_cards_rarity (rarity),
    INDEX idx_cards_boosters (available_in_boosters, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS booster_types (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE,
    set_id BIGINT UNSIGNED NULL,
    name VARCHAR(160) NOT NULL,
    description TEXT NOT NULL,
    image_path VARCHAR(255) NULL,
    cards_per_pack SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    is_free_pack TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_booster_types_set
        FOREIGN KEY (set_id) REFERENCES card_sets(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_booster_types_set (set_id),
    INDEX idx_booster_types_active_free (is_active, is_free_pack)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_booster_inventory (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    booster_type_id BIGINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_booster_inventory_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_user_booster_inventory_booster
        FOREIGN KEY (booster_type_id) REFERENCES booster_types(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY uq_user_booster_inventory (user_id, booster_type_id),
    INDEX idx_user_booster_inventory_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_cards (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    card_id BIGINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 0,
    first_obtained_at TIMESTAMP NULL DEFAULT NULL,
    last_obtained_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_cards_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_user_cards_card
        FOREIGN KEY (card_id) REFERENCES cards(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY uq_user_cards (user_id, card_id),
    INDEX idx_user_cards_user (user_id),
    INDEX idx_user_cards_card (card_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fantasy_card_user_state (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    free_claims SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    last_free_claim_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_fantasy_card_user_state_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_fantasy_card_user_state_claims (free_claims, last_free_claim_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fantasy_card_booster_openings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED NOT NULL,
    booster_type_id BIGINT UNSIGNED NOT NULL,
    set_id BIGINT UNSIGNED NULL,
    cards_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    opened_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_fantasy_card_booster_openings_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_fantasy_card_booster_openings_booster
        FOREIGN KEY (booster_type_id) REFERENCES booster_types(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_fantasy_card_booster_openings_set
        FOREIGN KEY (set_id) REFERENCES card_sets(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_fantasy_card_booster_openings_user_opened (user_id, opened_at),
    INDEX idx_fantasy_card_booster_openings_booster (booster_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fantasy_card_booster_opening_cards (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    opening_id BIGINT UNSIGNED NOT NULL,
    card_id BIGINT UNSIGNED NOT NULL,
    reveal_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_fantasy_card_booster_opening_cards_opening
        FOREIGN KEY (opening_id) REFERENCES fantasy_card_booster_openings(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_fantasy_card_booster_opening_cards_card
        FOREIGN KEY (card_id) REFERENCES cards(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_fantasy_card_booster_opening_cards_opening (opening_id, reveal_order),
    INDEX idx_fantasy_card_booster_opening_cards_card (card_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fantasy_card_profile_settings (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    favorite_card_id BIGINT UNSIGNED NULL,
    showcase_mode ENUM('manual', 'rarest', 'latest', 'completed') NOT NULL DEFAULT 'manual',
    is_collection_public TINYINT(1) NOT NULL DEFAULT 0,
    is_progress_public TINYINT(1) NOT NULL DEFAULT 0,
    is_favorites_public TINYINT(1) NOT NULL DEFAULT 0,
    profile_background_key VARCHAR(120) NULL,
    card_frame_key VARCHAR(120) NULL,
    achievement_badge_key VARCHAR(120) NULL,
    seasonal_showcase_key VARCHAR(120) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_fantasy_card_profile_settings_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_fantasy_card_profile_settings_favorite_card
        FOREIGN KEY (favorite_card_id) REFERENCES cards(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fantasy_card_profile_showcase_cards (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    card_id BIGINT UNSIGNED NOT NULL,
    slot SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_fantasy_card_profile_showcase_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_fantasy_card_profile_showcase_card
        FOREIGN KEY (card_id) REFERENCES cards(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY uq_fantasy_card_profile_showcase_slot (user_id, slot),
    UNIQUE KEY uq_fantasy_card_profile_showcase_card (user_id, card_id),
    INDEX idx_fantasy_card_profile_showcase_user_slot (user_id, slot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
