<?php

declare(strict_types=1);

namespace ModulNest\FantasyCards;

use RuntimeException;
use Throwable;
use ZipArchive;

final class FantasyCardsUploadService
{
    private const MAX_IMAGE_BYTES = 15_728_640; // 15 MB pro Bild
    private const MAX_ZIP_BYTES = 104_857_600; // 100 MB
    private const MAX_WIDTH = 6000;
    private const MAX_HEIGHT = 6000;
    private const THUMB_WIDTH = 420;

    public function __construct(
        private readonly FantasyCardsRepository $repository,
        private readonly string $basePath,
    ) {
    }

    /**
     * @param array<string, mixed> $file
     * @return array{ok:bool,message:string,card_ids:array<int,int>,cards:array<int,array<string,mixed>>,errors:array<int,string>}
     */
    public function handleUpload(int $setId, array $file): array
    {
        $set = $this->repository->findSetById($setId);
        if ($set === null) {
            return ['ok' => false, 'message' => 'Set nicht gefunden.', 'card_ids' => [], 'cards' => [], 'errors' => []];
        }

        $errors = [];
        $prepared = [];
        $sort = $this->repository->nextCardSortOrder($setId);

        try {
            foreach ($this->expandUpload($file) as $upload) {
                try {
                    $prepared[] = $this->prepareImageCard($set, $upload['path'], $upload['name'], $sort);
                    $sort += 10;
                } catch (Throwable $exception) {
                    $errors[] = $upload['name'] . ': ' . $exception->getMessage();
                } finally {
                    if (!empty($upload['temporary']) && is_file($upload['path'])) {
                        @unlink($upload['path']);
                    }
                }
            }
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => $exception->getMessage(), 'card_ids' => [], 'cards' => [], 'errors' => $errors];
        }

        $cardIds = $this->repository->createCards($prepared);
        $cards = $this->repository->listCardsByIds($cardIds);

        return [
            'ok' => $cardIds !== [],
            'message' => $cardIds === [] ? 'Keine Karten importiert.' : count($cardIds) . ' Karte(n) importiert.',
            'card_ids' => $cardIds,
            'cards' => $cards,
            'errors' => $errors,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $cards
     */
    public function deleteCardAssets(array $cards): void
    {
        foreach ($cards as $card) {
            foreach (['image_path', 'thumbnail_path'] as $field) {
                $path = trim((string) ($card[$field] ?? ''));
                if ($path === '') {
                    continue;
                }

                $target = $this->safePublicAssetPath($path);
                if ($target !== null && is_file($target)) {
                    @unlink($target);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $file
     * @return array<int, array{name:string,path:string,temporary:bool}>
     */
    private function expandUpload(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->uploadErrorMessage($error));
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        $name = basename((string) ($file['name'] ?? 'upload'));
        $size = (int) ($file['size'] ?? 0);
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException('Temporäre Upload-Datei ist ungültig.');
        }

        if ($this->isZipName($name)) {
            if ($size <= 0 || $size > self::MAX_ZIP_BYTES) {
                throw new RuntimeException('ZIP-Datei ist zu groß.');
            }
            return $this->extractZip($tmpName);
        }

        if ($size <= 0 || $size > self::MAX_IMAGE_BYTES) {
            throw new RuntimeException('Bilddatei ist zu groß.');
        }

        return [['name' => $name, 'path' => $tmpName, 'temporary' => false]];
    }

    /**
     * @return array<int, array{name:string,path:string,temporary:bool}>
     */
    private function extractZip(string $zipPath): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZIP-Unterstützung ist auf diesem System nicht verfügbar.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('ZIP-Datei konnte nicht gelesen werden.');
        }

        $tempDir = $this->runtimeDir('storage/fantasy-cards/tmp/zip_' . bin2hex(random_bytes(6)));
        $items = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = is_array($stat) ? basename((string) ($stat['name'] ?? '')) : '';
            if ($name === '' || !$this->isImageName($name)) {
                continue;
            }
            $content = $zip->getFromIndex($i);
            if (!is_string($content) || $content === '' || strlen($content) > self::MAX_IMAGE_BYTES) {
                continue;
            }
            $path = $tempDir . '/' . bin2hex(random_bytes(8)) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
            if (@file_put_contents($path, $content) === false) {
                throw new RuntimeException('ZIP-Inhalt konnte nicht zwischengespeichert werden.');
            }
            $items[] = ['name' => $name, 'path' => $path, 'temporary' => true];
        }
        $zip->close();

        if ($items === []) {
            throw new RuntimeException('ZIP enthält keine unterstützten Bilddateien.');
        }

        return $items;
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Datei überschreitet das aktuelle PHP-Uploadlimit.',
            UPLOAD_ERR_PARTIAL => 'Datei wurde nur teilweise hochgeladen.',
            UPLOAD_ERR_NO_FILE => 'Keine Datei empfangen.',
            UPLOAD_ERR_NO_TMP_DIR => 'Temporäres Upload-Verzeichnis fehlt.',
            UPLOAD_ERR_CANT_WRITE => 'Upload konnte nicht auf die Festplatte geschrieben werden.',
            UPLOAD_ERR_EXTENSION => 'Upload wurde durch eine PHP-Erweiterung gestoppt.',
            default => 'Upload fehlgeschlagen.',
        };
    }

    /**
     * @param array<string, mixed> $set
     * @return array<string, mixed>
     */
    private function prepareImageCard(array $set, string $path, string $originalName, int $sortOrder): array
    {
        if (!$this->isImageName($originalName)) {
            throw new RuntimeException('Dateityp wird nicht unterstützt.');
        }

        $info = @getimagesize($path);
        if (!is_array($info)) {
            throw new RuntimeException('Bild konnte nicht gelesen werden.');
        }
        [$width, $height, $type] = $info;
        if ($width <= 0 || $height <= 0 || $width > self::MAX_WIDTH || $height > self::MAX_HEIGHT) {
            throw new RuntimeException('Bilddimensionen sind ungültig oder zu groß.');
        }
        if (!in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new RuntimeException('Nur JPG, PNG und WebP werden unterstützt.');
        }

        $image = $this->createImage($path, $type);
        $uuid = $this->uuid();
        $setSlug = (string) ($set['slug'] ?? 'set');
        $publicDir = $this->runtimeDir('public/assets/fantasy-cards/cards/' . $setSlug);
        $imageFile = $uuid . '.webp';
        $thumbFile = $uuid . '_thumb.webp';
        $imageTarget = $publicDir . '/' . $imageFile;
        $thumbTarget = $publicDir . '/' . $thumbFile;

        @imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);
        if (!@imagewebp($image, $imageTarget, 88)) {
            imagedestroy($image);
            throw new RuntimeException('WebP-Datei konnte nicht geschrieben werden.');
        }

        $thumb = $this->resizeImage($image, self::THUMB_WIDTH);
        if (!@imagewebp($thumb, $thumbTarget, 82)) {
            imagedestroy($thumb);
            imagedestroy($image);
            throw new RuntimeException('Thumbnail konnte nicht geschrieben werden.');
        }
        imagedestroy($thumb);
        imagedestroy($image);

        $name = $this->nameFromFilename($originalName);
        $slug = $this->uniqueCardSlug((int) $set['id'], $this->slugify($name));

        return [
            'uuid' => $uuid,
            'set_id' => (int) $set['id'],
            'card_number' => $this->cardNumberFromFilename($originalName),
            'slug' => $slug,
            'name' => $name,
            'description' => '',
            'rarity' => 'common',
            'faction' => null,
            'element_name' => null,
            'image_path' => '/assets/fantasy-cards/cards/' . $setSlug . '/' . $imageFile,
            'thumbnail_path' => '/assets/fantasy-cards/cards/' . $setSlug . '/' . $thumbFile,
            'status' => 'draft',
            'is_active' => 0,
            'available_in_boosters' => 1,
            'sort_order' => $sortOrder,
        ];
    }

    /**
     * @return resource|\GdImage
     */
    private function createImage(string $path, int $type): mixed
    {
        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => false,
        };
        if (!$image) {
            throw new RuntimeException('Bild konnte nicht normalisiert werden.');
        }

        return $image;
    }

    private function resizeImage(mixed $image, int $targetWidth): mixed
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $ratio = $width > 0 ? $targetWidth / $width : 1;
        $targetHeight = max(1, (int) round($height * $ratio));
        $thumb = imagecreatetruecolor($targetWidth, $targetHeight);
        if (!$thumb) {
            throw new RuntimeException('Thumbnail konnte nicht erzeugt werden.');
        }
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
        imagecopyresampled($thumb, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $thumb;
    }

    private function runtimeDir(string $relative): string
    {
        $dir = rtrim($this->basePath, '/') . '/' . trim($relative, '/');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Storage-Verzeichnis konnte nicht erstellt werden.');
        }

        return $dir;
    }

    private function safePublicAssetPath(string $publicPath): ?string
    {
        $publicPath = '/' . ltrim($publicPath, '/');
        if (!str_starts_with($publicPath, '/assets/fantasy-cards/cards/')) {
            return null;
        }

        $root = rtrim($this->basePath, '/') . '/public';
        $target = $root . $publicPath;
        $dir = realpath(dirname($target));
        $assetsRoot = realpath($root . '/assets/fantasy-cards/cards');
        if (!is_string($dir) || !is_string($assetsRoot) || !str_starts_with($dir, $assetsRoot)) {
            return null;
        }

        return $target;
    }

    private function uniqueCardSlug(int $setId, string $baseSlug): string
    {
        $baseSlug = $baseSlug !== '' ? $baseSlug : 'karte';
        $slug = $baseSlug;
        $suffix = 2;
        while ($this->repository->cardSlugExists($setId, $slug)) {
            $slug = $baseSlug . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    private function nameFromFilename(string $filename): string
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $base = preg_replace('/^[0-9]+[_\-\s]+/', '', $base) ?? $base;
        $base = str_replace(['_', '-'], ' ', $base);
        $base = preg_replace('/\s+/', ' ', $base) ?? $base;

        return mb_convert_case(trim($base), MB_CASE_TITLE, 'UTF-8') ?: 'Neue Karte';
    }

    private function cardNumberFromFilename(string $filename): string
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        if (preg_match('/^([0-9]+)/', $base, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }

    private function slugify(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';
        return trim($value, '-');
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function isZipName(string $name): bool
    {
        return strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'zip';
    }

    private function isImageName(string $name): bool
    {
        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true);
    }
}
