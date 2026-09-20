<?php

declare(strict_types=1);

use Modulon\Core\Database\{Migration, SchemaHelper};

return new class implements Migration {
    public function key(): string { return 'modulnest.wiki_001_baseline'; }
    public function scope(): string { return 'module'; }
    public function moduleKey(): ?string { return 'modulnest.wiki'; }
    public function description(): string { return 'Erstellt das vollständige Wiki-Datenschema für eine neue v2-Installation.'; }

    public function up(PDO $pdo, SchemaHelper $schema): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wiki_sources (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            source_type VARCHAR(16) NOT NULL DEFAULT 'github',
            active_source_type VARCHAR(16) NULL,
            active_repository_owner VARCHAR(100) NULL,
            active_repository_name VARCHAR(100) NULL,
            active_ref_name VARCHAR(160) NULL,
            active_docs_root VARCHAR(255) NULL,
            repository_owner VARCHAR(100) NOT NULL DEFAULT '', repository_name VARCHAR(100) NOT NULL DEFAULT '', ref_name VARCHAR(160) NOT NULL DEFAULT '', docs_root VARCHAR(255) NOT NULL DEFAULT 'docs', enabled TINYINT(1) NOT NULL DEFAULT 0,
            last_commit_sha CHAR(64) NULL, last_sync_status VARCHAR(32) NOT NULL DEFAULT 'never', last_sync_at TIMESTAMP NULL, last_error_code VARCHAR(64) NULL, last_error_message_safe VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wiki_pages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, source_id BIGINT UNSIGNED NOT NULL, relative_path VARCHAR(500) NOT NULL, route_path VARCHAR(500) NOT NULL, title VARCHAR(255) NOT NULL, content_hash CHAR(64) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0, hidden TINYINT(1) NOT NULL DEFAULT 0, source_mtime BIGINT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_wiki_page_path(source_id,relative_path), UNIQUE KEY uq_wiki_route_path(source_id,route_path), KEY idx_wiki_pages_navigation(source_id,hidden,sort_order),
            CONSTRAINT fk_wiki_pages_source FOREIGN KEY(source_id) REFERENCES wiki_sources(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wiki_assets (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,source_id BIGINT UNSIGNED NOT NULL,relative_path VARCHAR(500) NOT NULL,content_hash CHAR(64) NOT NULL,mime_type VARCHAR(100) NOT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_wiki_asset_path(source_id,relative_path),CONSTRAINT fk_wiki_assets_source FOREIGN KEY(source_id) REFERENCES wiki_sources(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wiki_sync_runs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,source_id BIGINT UNSIGNED NOT NULL,started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,finished_at TIMESTAMP NULL,commit_sha CHAR(64) NULL,status VARCHAR(32) NOT NULL,added_count INT NOT NULL DEFAULT 0,changed_count INT NOT NULL DEFAULT 0,deleted_count INT NOT NULL DEFAULT 0,safe_error_code VARCHAR(64) NULL,CONSTRAINT fk_wiki_sync_source FOREIGN KEY(source_id) REFERENCES wiki_sources(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wiki_search_state (source_id BIGINT UNSIGNED PRIMARY KEY,index_version INT UNSIGNED NOT NULL,status VARCHAR(16) NOT NULL DEFAULT 'current',indexed_pages INT UNSIGNED NOT NULL DEFAULT 0,term_count INT UNSIGNED NOT NULL DEFAULT 0,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,CONSTRAINT fk_wiki_search_state_source FOREIGN KEY(source_id) REFERENCES wiki_sources(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wiki_search_documents (page_id BIGINT UNSIGNED PRIMARY KEY,source_id BIGINT UNSIGNED NOT NULL,content_hash CHAR(64) NOT NULL,title_text VARCHAR(255) NOT NULL,headings_text MEDIUMTEXT NOT NULL,body_text MEDIUMTEXT NOT NULL,code_text MEDIUMTEXT NOT NULL,path_text VARCHAR(500) NOT NULL,index_version INT UNSIGNED NOT NULL,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY idx_wiki_search_documents_source(source_id),CONSTRAINT fk_wiki_search_documents_page FOREIGN KEY(page_id) REFERENCES wiki_pages(id) ON DELETE CASCADE,CONSTRAINT fk_wiki_search_documents_source FOREIGN KEY(source_id) REFERENCES wiki_sources(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wiki_search_terms (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,source_id BIGINT UNSIGNED NOT NULL,term VARCHAR(191) NOT NULL,UNIQUE KEY uq_wiki_search_term(source_id,term),KEY idx_wiki_search_term_prefix(source_id,term),CONSTRAINT fk_wiki_search_terms_source FOREIGN KEY(source_id) REFERENCES wiki_sources(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wiki_search_postings (term_id BIGINT UNSIGNED NOT NULL,page_id BIGINT UNSIGNED NOT NULL,title_hits SMALLINT UNSIGNED NOT NULL DEFAULT 0,heading_hits SMALLINT UNSIGNED NOT NULL DEFAULT 0,body_hits SMALLINT UNSIGNED NOT NULL DEFAULT 0,code_hits SMALLINT UNSIGNED NOT NULL DEFAULT 0,path_hits SMALLINT UNSIGNED NOT NULL DEFAULT 0,PRIMARY KEY(term_id,page_id),KEY idx_wiki_search_postings_page(page_id),CONSTRAINT fk_wiki_search_postings_term FOREIGN KEY(term_id) REFERENCES wiki_search_terms(id) ON DELETE CASCADE,CONSTRAINT fk_wiki_search_postings_page FOREIGN KEY(page_id) REFERENCES wiki_pages(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wiki_search_trigrams (source_id BIGINT UNSIGNED NOT NULL,trigram VARCHAR(12) NOT NULL,term_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(source_id,trigram,term_id),KEY idx_wiki_search_trigram_term(term_id),CONSTRAINT fk_wiki_search_trigrams_source FOREIGN KEY(source_id) REFERENCES wiki_sources(id) ON DELETE CASCADE,CONSTRAINT fk_wiki_search_trigrams_term FOREIGN KEY(term_id) REFERENCES wiki_search_terms(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
};
