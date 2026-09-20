-- Mail-Client Datenbankschema (modulnest.mail-client v1.0.0)
-- Alle Tabellen haben den Prefix mail_client_ um Konflikte mit modulnest.mail zu vermeiden.

-- ─────────────────────────────────────────────────────────────────────────────
-- Mail-Konten (IMAP + SMTP Zugangsdaten, Passwort AES-256-GCM-verschlüsselt)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS mail_client_accounts (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         BIGINT UNSIGNED NOT NULL,
    display_name    VARCHAR(120)    NOT NULL,
    email_address   VARCHAR(190)    NOT NULL,
    -- IMAP
    imap_host       VARCHAR(190)    NOT NULL,
    imap_port       SMALLINT UNSIGNED NOT NULL DEFAULT 993,
    imap_encryption ENUM('ssl','tls','starttls') NOT NULL DEFAULT 'ssl',
    imap_username   VARCHAR(190)    NOT NULL,
    -- SMTP
    smtp_host       VARCHAR(190)    NOT NULL,
    smtp_port       SMALLINT UNSIGNED NOT NULL DEFAULT 587,
    smtp_encryption ENUM('ssl','tls','starttls') NOT NULL DEFAULT 'tls',
    smtp_username   VARCHAR(190)    NOT NULL,
    -- Verschlüsseltes Passwort (JSON-Token via SecretBox/AES-256-GCM)
    encrypted_password TEXT         NOT NULL,
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    sort_order      INT             NOT NULL DEFAULT 0,
    -- UIDVALIDITY-Cache als JSON: {"INBOX": 1234567, "Sent": 9876543, ...}
    -- Wird beim Ordner-Sync geprüft; Änderung → kompletter Cache-Drop für den Ordner
    uidvalidity_cache JSON          NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_mc_accounts_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_mc_accounts_user          (user_id),
    INDEX idx_mc_accounts_user_sort     (user_id, sort_order),
    UNIQUE KEY uq_mc_accounts_user_email (user_id, email_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Ordner-Cache: zuletzt bekannte Ordnerliste + Sync-Zeitstempel
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS mail_client_folder_cache (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             BIGINT UNSIGNED NOT NULL,
    mail_client_account_id BIGINT UNSIGNED NOT NULL,
    folder_name         VARCHAR(255)    NOT NULL,
    -- Zähler werden direkt von IMAP geliefert (STATUS command)
    total_messages      INT UNSIGNED    NOT NULL DEFAULT 0,
    unseen_messages     INT UNSIGNED    NOT NULL DEFAULT 0,
    uidvalidity         BIGINT UNSIGNED NOT NULL DEFAULT 0,
    uidnext             BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_synced_at      TIMESTAMP       NULL,
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_mc_folder_cache_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_mc_folder_cache_account
        FOREIGN KEY (mail_client_account_id) REFERENCES mail_client_accounts(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY uq_mc_folder_cache_scope (mail_client_account_id, folder_name),
    INDEX idx_mc_folder_cache_user      (user_id),
    INDEX idx_mc_folder_cache_account   (mail_client_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Nachrichten-Header-Cache (kein Body, nur Metadaten für schnelle Listenansicht)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS mail_client_message_index (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id                 BIGINT UNSIGNED NOT NULL,
    mail_client_account_id  BIGINT UNSIGNED NOT NULL,
    folder_name             VARCHAR(255)    NOT NULL,
    -- IMAP UID (eindeutig pro Ordner + UIDVALIDITY)
    uid                     BIGINT UNSIGNED NOT NULL,
    -- Message-ID Header (für Threading, optional)
    message_id              VARCHAR(512)    NULL,
    -- Normalisierter Absender für Whitelist-Abgleich
    sender_address          VARCHAR(255)    NOT NULL DEFAULT '',
    sender_name             VARCHAR(255)    NOT NULL DEFAULT '',
    -- Empfänger
    recipient_name          VARCHAR(255)    NOT NULL DEFAULT '',
    recipient_address       VARCHAR(255)    NOT NULL DEFAULT '',
    subject                 VARCHAR(998)    NOT NULL DEFAULT '',
    -- Unix-Timestamp des Mail-Datums
    message_date            BIGINT          NOT NULL DEFAULT 0,
    -- IMAP-Flags als Bitfeld: 1=Seen, 2=Answered, 4=Flagged, 8=Deleted, 16=Draft
    flags                   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    -- Größe der Nachricht in Bytes (aus IMAP FETCH RFC822.SIZE)
    size_bytes              INT UNSIGNED    NOT NULL DEFAULT 0,
    -- Hat Anhänge?
    has_attachments         TINYINT(1)      NOT NULL DEFAULT 0,
    created_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_mc_msg_index_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_mc_msg_index_account
        FOREIGN KEY (mail_client_account_id) REFERENCES mail_client_accounts(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY uq_mc_msg_index_scope_uid (mail_client_account_id, folder_name, uid),
    INDEX idx_mc_msg_index_scope        (user_id, mail_client_account_id, folder_name),
    INDEX idx_mc_msg_index_scope_date   (user_id, mail_client_account_id, folder_name, message_date),
    INDEX idx_mc_msg_index_scope_flags  (user_id, mail_client_account_id, folder_name, flags)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Absender-Whitelist: wer darf externe Bilder in Mails laden?
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS mail_client_sender_whitelist (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    -- 'sender' für einzelne Adresse, 'domain' für ganze Domain
    scope_type  ENUM('sender','domain') NOT NULL,
    scope_value VARCHAR(255)    NOT NULL,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mc_whitelist_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_mc_whitelist_user         (user_id),
    UNIQUE KEY uq_mc_whitelist_scope    (user_id, scope_type, scope_value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
