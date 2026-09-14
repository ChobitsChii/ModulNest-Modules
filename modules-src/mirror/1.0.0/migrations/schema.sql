-- modulnest.mirror 0.1.0-beta.1 schema
CREATE TABLE IF NOT EXISTS `mirror_configs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(150) NOT NULL,
    `type` VARCHAR(32) NOT NULL,
    `source_type` VARCHAR(32) NOT NULL DEFAULT 'github',
    `source_url` VARCHAR(500) NOT NULL,
    `source_branch` VARCHAR(100) NOT NULL DEFAULT 'main',
    `target_path` VARCHAR(500) NOT NULL,
    `public_url` VARCHAR(500) NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `last_synced_at` DATETIME NULL,
    `last_status` VARCHAR(50) NOT NULL DEFAULT 'idle',
    `last_log` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_mirror_type` (`type`),
    INDEX `idx_mirror_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
