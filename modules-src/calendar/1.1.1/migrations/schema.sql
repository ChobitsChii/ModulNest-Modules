-- Calendar Module Schema (modulnest.calendar)
-- Multi-Kalender-Unterstützung mit lokaler und externer (Google/CalDAV) Anbindung

CREATE TABLE IF NOT EXISTS `calendars` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `color` VARCHAR(20) NOT NULL DEFAULT '#3B82F6',
    `is_visible` TINYINT(1) NOT NULL DEFAULT 1,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `source` VARCHAR(50) NOT NULL DEFAULT 'local',
    `external_id` VARCHAR(255) NULL,
    `sync_token` VARCHAR(255) NULL,
    `last_synced_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_calendars_user` (`user_id`),
    INDEX `idx_calendars_source` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `calendar_appointments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `calendar_id` BIGINT UNSIGNED NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `location` VARCHAR(500) NULL,
    `start_at` DATETIME NOT NULL,
    `end_at` DATETIME NOT NULL,
    `all_day` TINYINT(1) NOT NULL DEFAULT 0,
    `color` VARCHAR(7) NULL DEFAULT '#3B82F6',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_calendar_appointments_user_start` (`user_id`, `start_at`),
    INDEX `idx_calendar_appointments_user_end` (`user_id`, `end_at`),
    INDEX `idx_calendar_appointments_all_day` (`user_id`, `all_day`, `start_at`),
    INDEX `idx_calendar_appointments_cal_id` (`calendar_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
