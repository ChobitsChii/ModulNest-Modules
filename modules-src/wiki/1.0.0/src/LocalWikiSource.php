<?php
declare(strict_types=1);
namespace ModulNest\Wiki;
use ZipArchive;

final class LocalWikiSource
{
    public function __construct(private readonly LocalWikiPath $paths) {}
    /** @return array{archive:string,sha:string} */
    public function download(string $relative): array
    {
        $relative = $this->paths->relative($relative);
        $directory = $this->paths->absolute($relative);
        $file = tempnam(sys_get_temp_dir(), 'wiki-local-');
        $zip = new ZipArchive();
        if ($file === false || $zip->open($file, ZipArchive::OVERWRITE) !== true) {
            throw new WikiSyncException('local_archive_failed');
        }

        try {
            $visited = [];
            $this->addDirectory($zip, $directory, 'local-source/' . $relative, $directory, $visited);
            $zip->close();
            $archive = (string) file_get_contents($file);
        } catch (\Throwable $e) {
            $zip->close();
            throw $e;
        } finally {
            @unlink($file);
        }

        return ['archive' => $archive, 'sha' => hash('sha256', $archive)];
    }

    /**
     * Follows only links whose resolved target remains inside the selected source
     * directory. The visited set makes cycles harmless and avoids a ZIP that
     * exposes files outside the administrator-selected documentation tree.
     *
     * @param array<string,bool> $visited
     */
    private function addDirectory(ZipArchive $zip, string $directory, string $archivePath, string $sourceRoot, array &$visited): void
    {
        $resolvedDirectory = realpath($directory);
        if ($resolvedDirectory === false || !$this->inside($resolvedDirectory, $sourceRoot)) {
            throw new WikiSyncException('local_path_unavailable');
        }
        if (isset($visited[$resolvedDirectory])) {
            return;
        }
        $visited[$resolvedDirectory] = true;

        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $candidate = $directory . '/' . $name;
            $resolved = realpath($candidate);
            if ($resolved === false || !$this->inside($resolved, $sourceRoot)) {
                continue;
            }
            $target = $archivePath . '/' . $name;
            if (is_dir($candidate)) {
                $this->addDirectory($zip, $candidate, $target, $sourceRoot, $visited);
            } elseif (is_file($candidate) && !$zip->addFile($resolved, $target)) {
                throw new WikiSyncException('local_archive_failed');
            }
        }
    }

    private function inside(string $candidate, string $root): bool
    {
        $root = rtrim((string) realpath($root), DIRECTORY_SEPARATOR);
        return $candidate === $root || str_starts_with($candidate . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR);
    }
}
