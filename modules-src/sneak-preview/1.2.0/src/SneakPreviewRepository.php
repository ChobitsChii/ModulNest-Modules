<?php

declare(strict_types=1);

namespace ModulNest\SneakPreview;

use PDO;

final class SneakPreviewRepository
{
    private const DEFAULT_DISPLAY_FIELDS = [
        'poster' => ['table' => true, 'lightbox' => true, 'admin' => true],
        'sneak_date' => ['table' => true, 'lightbox' => false, 'admin' => true],
        'title' => ['table' => true, 'lightbox' => true, 'admin' => true],
        'location' => ['table' => true, 'lightbox' => false, 'admin' => true],
        'release_date_de' => ['table' => true, 'lightbox' => false, 'admin' => true],
        'days_offset' => ['table' => true, 'lightbox' => false, 'admin' => true],
        'certification' => ['table' => false, 'lightbox' => true, 'admin' => true],
        'genres' => ['table' => false, 'lightbox' => true, 'admin' => true],
        'runtime' => ['table' => false, 'lightbox' => true, 'admin' => true],
        'vote_average' => ['table' => false, 'lightbox' => true, 'admin' => true],
        'overview' => ['table' => false, 'lightbox' => true, 'admin' => false],
    ];

    public function __construct(private readonly PDO $pdo)
    {
        $this->ensureTables();
    }

    public function ensureTables(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS sneak_preview_entries (
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
                UNIQUE KEY uniq_sneak_preview_legacy_id (legacy_id),
                INDEX idx_sneak_preview_sneak_date (sneak_date),
                INDEX idx_sneak_preview_title (title),
                INDEX idx_sneak_preview_location (location),
                INDEX idx_sneak_preview_release_date (release_date_de),
                INDEX idx_sneak_preview_tmdb_id (tmdb_id),
                CONSTRAINT fk_sneak_preview_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_sneak_preview_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS sneak_preview_settings (
                `key` VARCHAR(120) PRIMARY KEY,
                `value` TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        if ($this->setting('display_fields') === null) {
            $this->setSetting('display_fields', json_encode(self::DEFAULT_DISPLAY_FIELDS, JSON_THROW_ON_ERROR));
        }
        if ($this->setting('save_posters_locally') === null) {
            $this->setSetting('save_posters_locally', '1');
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allMovies(): array
    {
        $statement = $this->pdo->query(
            'SELECT *
             FROM sneak_preview_entries
             ORDER BY sneak_date DESC, id DESC'
        );

        $rows = $statement->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findMovie(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM sneak_preview_entries WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<int, string>
     */
    public function locations(): array
    {
        $statement = $this->pdo->query(
            "SELECT DISTINCT location
             FROM sneak_preview_entries
             WHERE location <> ''
             ORDER BY location"
        );
        $rows = $statement->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_filter(array_map('strval', is_array($rows) ? $rows : [])));
    }

    /**
     * @param array<string, mixed> $movie
     */
    public function saveMovie(array $movie, int $adminUserId): int
    {
        $id = (int) ($movie['id'] ?? 0);
        $data = [
            'sneak_date' => (string) ($movie['sneak_date'] ?? ''),
            'title' => (string) ($movie['title'] ?? ''),
            'location' => (string) ($movie['location'] ?? ''),
            'release_date_de' => $this->nullableString($movie['release_date_de'] ?? null),
            'poster_path' => $this->nullableString($movie['poster_path'] ?? null),
            'poster_tmdb_path' => $this->nullableString($movie['poster_tmdb_path'] ?? null),
            'tmdb_id' => $this->nullableInt($movie['tmdb_id'] ?? null),
            'overview' => $this->nullableString($movie['overview'] ?? null),
            'genres' => $this->nullableString($movie['genres'] ?? null),
            'runtime' => $this->nullableInt($movie['runtime'] ?? null),
            'certification' => $this->nullableString($movie['certification'] ?? null),
            'original_language' => $this->nullableString($movie['original_language'] ?? null),
            'production_countries' => $this->nullableString($movie['production_countries'] ?? null),
            'vote_average' => $this->nullableFloat($movie['vote_average'] ?? null),
            'trailer_key' => $this->nullableString($movie['trailer_key'] ?? null),
            'updated_by' => $adminUserId > 0 ? $adminUserId : null,
        ];

        if ($id > 0) {
            $statement = $this->pdo->prepare(
                'UPDATE sneak_preview_entries
                 SET sneak_date = :sneak_date,
                     title = :title,
                     location = :location,
                     release_date_de = :release_date_de,
                     poster_path = COALESCE(:poster_path, poster_path),
                     poster_tmdb_path = :poster_tmdb_path,
                     tmdb_id = :tmdb_id,
                     overview = :overview,
                     genres = :genres,
                     runtime = :runtime,
                     certification = :certification,
                     original_language = :original_language,
                     production_countries = :production_countries,
                     vote_average = :vote_average,
                     trailer_key = :trailer_key,
                     updated_by = :updated_by
                 WHERE id = :id'
            );
            $statement->execute($data + ['id' => $id]);
            return $id;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO sneak_preview_entries
                (sneak_date, title, location, release_date_de, poster_path, poster_tmdb_path, tmdb_id, overview, genres, runtime, certification, original_language, production_countries, vote_average, trailer_key, created_by, updated_by)
             VALUES
                (:sneak_date, :title, :location, :release_date_de, :poster_path, :poster_tmdb_path, :tmdb_id, :overview, :genres, :runtime, :certification, :original_language, :production_countries, :vote_average, :trailer_key, :created_by, :updated_by)'
        );
        $statement->execute($data + [
            'created_by' => $adminUserId > 0 ? $adminUserId : null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function deleteMovie(int $id): int
    {
        $statement = $this->pdo->prepare('DELETE FROM sneak_preview_entries WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->rowCount();
    }

    public function setting(string $key): ?string
    {
        $statement = $this->pdo->prepare('SELECT `value` FROM sneak_preview_settings WHERE `key` = :key LIMIT 1');
        $statement->execute(['key' => $key]);
        $value = $statement->fetchColumn();

        return is_string($value) ? $value : null;
    }

    public function setSetting(string $key, ?string $value): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO sneak_preview_settings (`key`, `value`)
             VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute(['key' => $key, 'value' => $value]);
    }

    /**
     * @return array<string, array{table:bool, lightbox:bool, admin:bool}>
     */
    public function displayFields(): array
    {
        $json = $this->setting('display_fields');
        $decoded = is_string($json) ? json_decode($json, true) : null;
        $fields = is_array($decoded) ? $decoded : [];

        return $this->normalizeDisplayFields($fields);
    }

    /**
     * @param array<string, array<string, bool>> $fields
     */
    public function saveDisplayFields(array $fields): void
    {
        $this->setSetting('display_fields', json_encode($this->normalizeDisplayFields($fields), JSON_THROW_ON_ERROR));
    }

    public function savePostersLocally(): bool
    {
        $value = $this->setting('save_posters_locally');
        return $value === null || in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    public function hasTmdbApiKey(): bool
    {
        return trim((string) $this->setting('tmdb_api_key')) !== '';
    }

    public function maskedTmdbApiKey(): ?string
    {
        $key = trim((string) $this->setting('tmdb_api_key'));
        $length = strlen($key);
        if ($length === 0) {
            return null;
        }
        if ($length <= 10) {
            return str_repeat('•', $length);
        }

        return substr($key, 0, 4) . str_repeat('•', max(6, $length - 8)) . substr($key, -4);
    }

    /**
     * @return array<string, string>
     */
    public function displayCatalog(): array
    {
        return [
            'poster' => 'Poster',
            'sneak_date' => 'Datum (Sneak)',
            'title' => 'Titel',
            'location' => 'Ort',
            'release_date_de' => 'Kinostart (DE)',
            'days_offset' => 'Tage davor/danach',
            'certification' => 'FSK',
            'genres' => 'Genres',
            'runtime' => 'Laufzeit',
            'vote_average' => 'Bewertung',
            'overview' => 'Beschreibung',
        ];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, array{table:bool, lightbox:bool, admin:bool}>
     */
    private function normalizeDisplayFields(array $fields): array
    {
        $normalized = [];
        foreach (self::DEFAULT_DISPLAY_FIELDS as $key => $defaults) {
            $input = is_array($fields[$key] ?? null) ? $fields[$key] : [];
            $normalized[$key] = [
                'table' => array_key_exists('table', $input) ? (bool) $input['table'] : (bool) $defaults['table'],
                'lightbox' => array_key_exists('lightbox', $input) ? (bool) $input['lightbox'] : (bool) $defaults['lightbox'],
                'admin' => array_key_exists('admin', $input) ? (bool) $input['admin'] : (bool) $defaults['admin'],
            ];
        }

        return $normalized;
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string) ($value ?? ''));
        return $string === '' ? null : $string;
    }

    private function nullableInt(mixed $value): ?int
    {
        $string = trim((string) ($value ?? ''));
        return $string === '' || !is_numeric($string) ? null : (int) $string;
    }

    private function nullableFloat(mixed $value): ?float
    {
        $string = trim(str_replace(',', '.', (string) ($value ?? '')));
        return $string === '' || !is_numeric($string) ? null : (float) $string;
    }
}
