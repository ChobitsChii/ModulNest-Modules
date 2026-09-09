CREATE TABLE IF NOT EXISTS sneak_preview_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    legacy_id INT NULL,
    sneak_date DATE NOT NULL,
    title VARCHAR(255) NOT NULL,
    location VARCHAR(255) NOT NULL,
    release_date_de DATE NULL,
    poster_path VARCHAR(255) NULL,
    poster_tmdb_path VARCHAR(255) NULL,
    tmdb_id INT NULL,
    overview TEXT NULL,
    genres VARCHAR(255) NULL,
    runtime INT NULL,
    certification VARCHAR(20) NULL,
    original_language VARCHAR(20) NULL,
    production_countries VARCHAR(255) NULL,
    vote_average DECIMAL(3,1) NULL,
    trailer_key VARCHAR(32) NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_sneak_preview_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_sneak_preview_updated_by
        FOREIGN KEY (updated_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE KEY uniq_sneak_preview_legacy_id (legacy_id),
    INDEX idx_sneak_preview_sneak_date (sneak_date),
    INDEX idx_sneak_preview_title (title),
    INDEX idx_sneak_preview_location (location),
    INDEX idx_sneak_preview_release_date (release_date_de),
    INDEX idx_sneak_preview_tmdb_id (tmdb_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sneak_preview_settings (
    `key` VARCHAR(120) PRIMARY KEY,
    `value` TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
