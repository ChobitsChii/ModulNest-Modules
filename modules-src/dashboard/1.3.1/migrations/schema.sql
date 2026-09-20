-- Dashboard-Grundlage (usergebundene, mehrfach einbindbare Widgets)
CREATE TABLE IF NOT EXISTS dashboard_widgets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    widget_type ENUM('links', 'tasks', 'notes') NOT NULL,
    title VARCHAR(120) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    layout_width TINYINT UNSIGNED NOT NULL DEFAULT 6,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_dashboard_widgets_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_dashboard_widgets_user (user_id),
    INDEX idx_dashboard_widgets_user_sort (user_id, sort_order),
    INDEX idx_dashboard_widgets_user_type (user_id, widget_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dashboard_link_folders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    widget_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_dashboard_link_folders_widget
        FOREIGN KEY (widget_id) REFERENCES dashboard_widgets(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_dashboard_link_folders_widget (widget_id),
    INDEX idx_dashboard_link_folders_widget_sort (widget_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dashboard_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    widget_id BIGINT UNSIGNED NOT NULL,
    folder_id BIGINT UNSIGNED NULL,
    title VARCHAR(180) NOT NULL,
    url VARCHAR(2048) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    favicon_url VARCHAR(2048) NULL,
    favicon_host VARCHAR(190) NULL,
    favicon_last_checked_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_dashboard_links_widget
        FOREIGN KEY (widget_id) REFERENCES dashboard_widgets(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_dashboard_links_folder
        FOREIGN KEY (folder_id) REFERENCES dashboard_link_folders(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_dashboard_links_widget (widget_id),
    INDEX idx_dashboard_links_widget_sort (widget_id, sort_order),
    INDEX idx_dashboard_links_folder (folder_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dashboard_tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    widget_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    details TEXT NULL,
    link_url VARCHAR(2048) NULL,
    priority TINYINT UNSIGNED NOT NULL DEFAULT 0,
    due_at TIMESTAMP NULL DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_done TINYINT(1) NOT NULL DEFAULT 0,
    done_at TIMESTAMP NULL DEFAULT NULL,
    archived_at DATETIME NULL DEFAULT NULL,
    repeat_type ENUM('none', 'daily', 'weekly', 'monthly') NOT NULL DEFAULT 'none',
    repeat_time TIME NULL DEFAULT NULL,
    repeat_weekday TINYINT UNSIGNED NULL,
    repeat_month_mode ENUM('first_day', 'middle_day', 'last_day', 'fixed_day', 'ordinal_weekday') NULL,
    repeat_month_day TINYINT UNSIGNED NULL,
    repeat_month_ordinal TINYINT UNSIGNED NULL,
    repeat_month_weekday TINYINT UNSIGNED NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_dashboard_tasks_widget
        FOREIGN KEY (widget_id) REFERENCES dashboard_widgets(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_dashboard_tasks_widget (widget_id),
    INDEX idx_dashboard_tasks_widget_done_sort (widget_id, is_done, sort_order),
    INDEX idx_dashboard_tasks_widget_archive_sort (widget_id, archived_at, is_done, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE dashboard_tasks
    ADD COLUMN IF NOT EXISTS link_url VARCHAR(2048) NULL AFTER details,
    ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER due_at,
    ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL DEFAULT NULL AFTER done_at,
    ADD COLUMN IF NOT EXISTS repeat_type ENUM('none', 'daily', 'weekly', 'monthly') NOT NULL DEFAULT 'none' AFTER archived_at,
    ADD COLUMN IF NOT EXISTS repeat_time TIME NULL DEFAULT NULL AFTER repeat_type,
    ADD COLUMN IF NOT EXISTS repeat_weekday TINYINT UNSIGNED NULL AFTER repeat_time,
    ADD COLUMN IF NOT EXISTS repeat_month_mode ENUM('first_day', 'middle_day', 'last_day', 'fixed_day', 'ordinal_weekday') NULL AFTER repeat_weekday,
    ADD COLUMN IF NOT EXISTS repeat_month_day TINYINT UNSIGNED NULL AFTER repeat_month_mode,
    ADD COLUMN IF NOT EXISTS repeat_month_ordinal TINYINT UNSIGNED NULL AFTER repeat_month_day,
    ADD COLUMN IF NOT EXISTS repeat_month_weekday TINYINT UNSIGNED NULL AFTER repeat_month_ordinal;

ALTER TABLE dashboard_tasks
    MODIFY COLUMN repeat_month_mode ENUM('first_day', 'middle_day', 'last_day', 'fixed_day', 'ordinal_weekday') NULL;

CREATE TABLE IF NOT EXISTS dashboard_notes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    widget_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(180) NULL,
    content MEDIUMTEXT NOT NULL,
    textarea_height INT UNSIGNED NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    archived_at DATETIME NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_dashboard_notes_widget
        FOREIGN KEY (widget_id) REFERENCES dashboard_widgets(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_dashboard_notes_widget (widget_id),
    INDEX idx_dashboard_notes_widget_sort (widget_id, is_pinned, sort_order),
    INDEX idx_dashboard_notes_widget_archive_sort (widget_id, archived_at, is_pinned, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE dashboard_notes
    ADD COLUMN IF NOT EXISTS textarea_height INT UNSIGNED NULL AFTER content,
    ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL DEFAULT NULL AFTER is_archived;

UPDATE dashboard_notes
SET archived_at = COALESCE(updated_at, CURRENT_TIMESTAMP)
WHERE is_archived = 1
  AND archived_at IS NULL;
