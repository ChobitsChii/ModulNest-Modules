-- Mail-Modul Fundament (multi-account, favorisierte Ordner, Whitelist, Listenpräferenzen)
CREATE TABLE IF NOT EXISTS mail_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    email_address VARCHAR(190) NOT NULL,
    imap_host VARCHAR(190) NOT NULL,
    imap_port SMALLINT UNSIGNED NOT NULL DEFAULT 993,
    imap_encryption ENUM('tls', 'ssl', 'starttls') NOT NULL DEFAULT 'tls',
    imap_username VARCHAR(190) NOT NULL,
    smtp_host VARCHAR(190) NOT NULL,
    smtp_port SMALLINT UNSIGNED NOT NULL DEFAULT 587,
    smtp_encryption ENUM('tls', 'ssl', 'starttls') NOT NULL DEFAULT 'tls',
    smtp_username VARCHAR(190) NOT NULL,
    encrypted_password TEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_mail_accounts_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_mail_accounts_user (user_id),
    INDEX idx_mail_accounts_user_sort (user_id, sort_order),
    UNIQUE KEY uq_mail_accounts_user_email (user_id, email_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_favorite_folders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    mail_account_id BIGINT UNSIGNED NOT NULL,
    folder_name VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mail_favorite_folders_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_mail_favorite_folders_account
        FOREIGN KEY (mail_account_id) REFERENCES mail_accounts(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_mail_favorite_folders_user (user_id),
    INDEX idx_mail_favorite_folders_account (mail_account_id),
    INDEX idx_mail_favorite_folders_sort (mail_account_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_sender_whitelist (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    scope_type ENUM('sender', 'domain') NOT NULL,
    scope_value VARCHAR(255) NOT NULL,
    allow_external_images TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_mail_sender_whitelist_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_mail_sender_whitelist_user (user_id),
    INDEX idx_mail_sender_whitelist_scope (scope_type, scope_value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_sender_exclusions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    mail_account_id BIGINT UNSIGNED NOT NULL,
    folder_name VARCHAR(255) NOT NULL,
    sender_key VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mail_sender_exclusions_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_mail_sender_exclusions_account
        FOREIGN KEY (mail_account_id) REFERENCES mail_accounts(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_mail_sender_exclusions_user (user_id),
    INDEX idx_mail_sender_exclusions_context (user_id, mail_account_id, folder_name),
    UNIQUE KEY uq_mail_sender_exclusions_scope (user_id, mail_account_id, folder_name, sender_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_list_preferences (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    mail_account_id BIGINT UNSIGNED NULL,
    folder_name VARCHAR(255) NULL,
    sort_field ENUM('date', 'sender', 'subject', 'size') NOT NULL DEFAULT 'date',
    sort_direction ENUM('asc', 'desc') NOT NULL DEFAULT 'desc',
    group_field ENUM('none', 'sender', 'date', 'folder') NOT NULL DEFAULT 'none',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_mail_list_preferences_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_mail_list_preferences_account
        FOREIGN KEY (mail_account_id) REFERENCES mail_accounts(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_mail_list_preferences_user (user_id),
    INDEX idx_mail_list_preferences_account (mail_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_message_index (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    mail_account_id BIGINT UNSIGNED NOT NULL,
    folder_name VARCHAR(255) NOT NULL,
    uid BIGINT UNSIGNED NOT NULL,
    message_id VARCHAR(255) NULL,
    sender_key VARCHAR(255) NOT NULL,
    sender_label VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    message_timestamp BIGINT NOT NULL DEFAULT 0,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_mail_message_index_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_mail_message_index_account
        FOREIGN KEY (mail_account_id) REFERENCES mail_accounts(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY uq_mail_message_index_scope_uid (user_id, mail_account_id, folder_name, uid),
    INDEX idx_mail_message_index_scope (user_id, mail_account_id, folder_name),
    INDEX idx_mail_message_index_scope_sender (user_id, mail_account_id, folder_name, sender_key),
    INDEX idx_mail_message_index_scope_ts (user_id, mail_account_id, folder_name, message_timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
