-- Banking-Modul Zielstruktur (native, usergebundene Migration aus Legacy-Banking)
CREATE TABLE IF NOT EXISTS banking_migration_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    target_user_id BIGINT UNSIGNED NOT NULL,
    source_database VARCHAR(120) NOT NULL DEFAULT 'banking',
    source_snapshot_label VARCHAR(190) NULL,
    source_transactions_count INT UNSIGNED NOT NULL DEFAULT 0,
    source_rules_count INT UNSIGNED NOT NULL DEFAULT 0,
    source_conditions_count INT UNSIGNED NOT NULL DEFAULT 0,
    imported_transactions_count INT UNSIGNED NOT NULL DEFAULT 0,
    imported_rules_count INT UNSIGNED NOT NULL DEFAULT 0,
    imported_conditions_count INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('planned', 'dry_run', 'running', 'completed', 'failed', 'rolled_back') NOT NULL DEFAULT 'planned',
    notes TEXT NULL,
    started_at TIMESTAMP NULL DEFAULT NULL,
    finished_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_banking_migration_runs_user
        FOREIGN KEY (target_user_id) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_banking_migration_runs_user (target_user_id),
    INDEX idx_banking_migration_runs_status (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS banking_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    migration_run_id BIGINT UNSIGNED NULL,
    legacy_account_key VARCHAR(190) NULL,
    account_identifier VARCHAR(190) NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    iban VARCHAR(34) NULL,
    bic VARCHAR(11) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'EUR',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_banking_accounts_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_banking_accounts_migration_run
        FOREIGN KEY (migration_run_id) REFERENCES banking_migration_runs(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE KEY uq_banking_accounts_user_identifier (user_id, account_identifier),
    INDEX idx_banking_accounts_user_active (user_id, is_active),
    INDEX idx_banking_accounts_user_iban (user_id, iban),
    INDEX idx_banking_accounts_migration_run (migration_run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS banking_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    migration_run_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    normalized_name VARCHAR(120) NOT NULL,
    color VARCHAR(20) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_banking_categories_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_banking_categories_migration_run
        FOREIGN KEY (migration_run_id) REFERENCES banking_migration_runs(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE KEY uq_banking_categories_user_normalized (user_id, normalized_name),
    INDEX idx_banking_categories_user_sort (user_id, sort_order),
    INDEX idx_banking_categories_migration_run (migration_run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS banking_import_batches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NULL,
    migration_run_id BIGINT UNSIGNED NULL,
    source_type ENUM('legacy_migration', 'csv', 'manual', 'other') NOT NULL DEFAULT 'legacy_migration',
    original_filename VARCHAR(255) NULL,
    file_sha256 CHAR(64) NULL,
    status ENUM('pending', 'running', 'completed', 'failed', 'rolled_back') NOT NULL DEFAULT 'pending',
    imported_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_count INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_summary TEXT NULL,
    started_at TIMESTAMP NULL DEFAULT NULL,
    finished_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_banking_import_batches_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_banking_import_batches_account
        FOREIGN KEY (account_id) REFERENCES banking_accounts(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_banking_import_batches_migration_run
        FOREIGN KEY (migration_run_id) REFERENCES banking_migration_runs(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_banking_import_batches_user_status (user_id, status),
    INDEX idx_banking_import_batches_account (account_id),
    INDEX idx_banking_import_batches_file_hash (user_id, file_sha256),
    INDEX idx_banking_import_batches_migration_run (migration_run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS banking_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NULL,
    category_id BIGINT UNSIGNED NULL,
    import_batch_id BIGINT UNSIGNED NULL,
    migration_run_id BIGINT UNSIGNED NULL,
    legacy_id INT UNSIGNED NULL,
    booking_date DATE NOT NULL,
    value_date DATE NULL,
    booking_text VARCHAR(255) NULL,
    purpose TEXT NULL,
    counterparty_name VARCHAR(255) NULL,
    counterparty_iban VARCHAR(64) NULL,
    counterparty_bic VARCHAR(20) NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'EUR',
    raw_info TEXT NULL,
    legacy_category_name VARCHAR(120) NULL,
    transaction_hash CHAR(64) NULL,
    booking_status ENUM('gebucht', 'vorgemerkt') NOT NULL DEFAULT 'gebucht',
    legacy_created_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_banking_transactions_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_banking_transactions_account
        FOREIGN KEY (account_id) REFERENCES banking_accounts(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_banking_transactions_category
        FOREIGN KEY (category_id) REFERENCES banking_categories(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_banking_transactions_import_batch
        FOREIGN KEY (import_batch_id) REFERENCES banking_import_batches(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_banking_transactions_migration_run
        FOREIGN KEY (migration_run_id) REFERENCES banking_migration_runs(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE KEY uq_banking_transactions_user_legacy (user_id, legacy_id),
    UNIQUE KEY uq_banking_transactions_user_hash (user_id, transaction_hash),
    INDEX idx_banking_transactions_user_account_date (user_id, account_id, booking_date),
    INDEX idx_banking_transactions_user_value_date (user_id, value_date),
    INDEX idx_banking_transactions_user_status (user_id, booking_status),
    INDEX idx_banking_transactions_user_category (user_id, category_id),
    INDEX idx_banking_transactions_user_amount (user_id, amount),
    INDEX idx_banking_transactions_migration_run (migration_run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS banking_recurring_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NULL,
    category_id BIGINT UNSIGNED NULL,
    migration_run_id BIGINT UNSIGNED NULL,
    legacy_id INT UNSIGNED NULL,
    name VARCHAR(190) NOT NULL,
    interval_type VARCHAR(40) NOT NULL,
    notes TEXT NULL,
    rule_type VARCHAR(40) NULL,
    group_label VARCHAR(120) NULL,
    active_from DATE NULL,
    active_to DATE NULL,
    period_mode VARCHAR(40) NULL,
    due_day TINYINT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    legacy_created_at TIMESTAMP NULL DEFAULT NULL,
    legacy_updated_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_banking_recurring_rules_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_banking_recurring_rules_account
        FOREIGN KEY (account_id) REFERENCES banking_accounts(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_banking_recurring_rules_category
        FOREIGN KEY (category_id) REFERENCES banking_categories(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_banking_recurring_rules_migration_run
        FOREIGN KEY (migration_run_id) REFERENCES banking_migration_runs(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE KEY uq_banking_recurring_rules_user_legacy (user_id, legacy_id),
    INDEX idx_banking_recurring_rules_user_active (user_id, is_active),
    INDEX idx_banking_recurring_rules_user_type (user_id, rule_type),
    INDEX idx_banking_recurring_rules_user_interval (user_id, interval_type),
    INDEX idx_banking_recurring_rules_user_group (user_id, group_label),
    INDEX idx_banking_recurring_rules_migration_run (migration_run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS banking_recurring_rule_conditions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    recurring_rule_id BIGINT UNSIGNED NOT NULL,
    migration_run_id BIGINT UNSIGNED NULL,
    legacy_id INT UNSIGNED NULL,
    field VARCHAR(80) NOT NULL,
    operator VARCHAR(40) NOT NULL,
    value VARCHAR(255) NOT NULL,
    legacy_created_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_banking_recurring_rule_conditions_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_banking_recurring_rule_conditions_rule
        FOREIGN KEY (recurring_rule_id) REFERENCES banking_recurring_rules(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_banking_recurring_rule_conditions_migration_run
        FOREIGN KEY (migration_run_id) REFERENCES banking_migration_runs(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE KEY uq_banking_recurring_rule_conditions_user_legacy (user_id, legacy_id),
    UNIQUE KEY uq_banking_recurring_rule_conditions_rule_legacy (recurring_rule_id, legacy_id),
    INDEX idx_banking_recurring_rule_conditions_user (user_id),
    INDEX idx_banking_recurring_rule_conditions_rule (recurring_rule_id),
    INDEX idx_banking_recurring_rule_conditions_field_operator (field, operator),
    INDEX idx_banking_recurring_rule_conditions_migration_run (migration_run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS banking_dashboard_cache (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    cache_scope VARCHAR(60) NOT NULL,
    period_key VARCHAR(20) NOT NULL,
    data_hash CHAR(64) NOT NULL,
    payload_json LONGTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_banking_dashboard_cache_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY uq_banking_dashboard_cache_scope (user_id, cache_scope, period_key),
    INDEX idx_banking_dashboard_cache_user_updated (user_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
