CREATE TABLE IF NOT EXISTS homepage_blocks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type ENUM('custom_content', 'module_list', 'feature_list') NOT NULL DEFAULT 'custom_content',
    title VARCHAR(190) NOT NULL,
    show_title TINYINT(1) NOT NULL DEFAULT 1,
    content_markdown MEDIUMTEXT NULL,
    button_label VARCHAR(120) NULL,
    button_url VARCHAR(255) NULL,
    button_layout ENUM('below_text', 'inline_right') NOT NULL DEFAULT 'below_text',
    visibility_guest TINYINT(1) NOT NULL DEFAULT 1,
    visibility_user TINYINT(1) NOT NULL DEFAULT 1,
    visibility_admin TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    column_span ENUM('full', 'half', 'two_thirds', 'one_third') NOT NULL DEFAULT 'full',
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_homepage_blocks_enabled_sort (is_enabled, sort_order, id),
    INDEX idx_homepage_blocks_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE homepage_blocks
    ADD COLUMN IF NOT EXISTS type ENUM('custom_content', 'module_list', 'feature_list') NOT NULL DEFAULT 'custom_content',
    ADD COLUMN IF NOT EXISTS title VARCHAR(190) NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS show_title TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS content_markdown MEDIUMTEXT NULL,
    ADD COLUMN IF NOT EXISTS button_label VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS button_url VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS button_layout ENUM('below_text', 'inline_right') NOT NULL DEFAULT 'below_text',
    ADD COLUMN IF NOT EXISTS visibility_guest TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS visibility_user TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS visibility_admin TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS column_span ENUM('full', 'half', 'two_thirds', 'one_third') NOT NULL DEFAULT 'full',
    ADD COLUMN IF NOT EXISTS is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE homepage_blocks
    MODIFY COLUMN type ENUM('custom_content', 'module_list', 'feature_list') NOT NULL DEFAULT 'custom_content',
    MODIFY COLUMN button_layout ENUM('below_text', 'inline_right') NOT NULL DEFAULT 'below_text',
    MODIFY COLUMN column_span ENUM('full', 'half', 'two_thirds', 'one_third') NOT NULL DEFAULT 'full';

CREATE TABLE IF NOT EXISTS homepage_block_buttons (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    block_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(120) NOT NULL,
    url VARCHAR(255) NOT NULL,
    variant ENUM('primary', 'secondary') NOT NULL DEFAULT 'primary',
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_homepage_block_buttons_block
        FOREIGN KEY (block_id) REFERENCES homepage_blocks(id)
        ON DELETE CASCADE,
    INDEX idx_homepage_block_buttons_block_sort (block_id, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS homepage_block_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    block_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(190) NOT NULL,
    content_markdown MEDIUMTEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_homepage_block_items_block
        FOREIGN KEY (block_id) REFERENCES homepage_blocks(id)
        ON DELETE CASCADE,
    INDEX idx_homepage_block_items_block_sort (block_id, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO app_settings (`key`, `value`) VALUES ('homepage.is_published', '0');
