<?php

declare(strict_types=1);

namespace ModulNest\Wiki;

use Modulon\Core\RotatingFileLogger;
use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;

/** Synchronises one public GitHub documentation tree into a local, offline cache. */
final class WikiService
{
    private const MAX_ARCHIVE_BYTES = 20_000_000;
    private const MAX_FILES = 3_000;
    private const MAX_FILE_BYTES = 1_500_000;
    private const MAX_UNPACKED_BYTES = 50_000_000;
    private const MAX_DEPTH = 12;
    private const IMAGE_MIME = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];

    public function __construct(
        private readonly WikiRepository $repository,
        private readonly PDO $pdo,
        private readonly string $basePath,
        private readonly GitHubWikiClient $client,
        private readonly ?WikiSearchIndexer $searchIndexer = null,
    ) {
    }

    /** @return array{owner:string,repo:string,ref:string,root:string,enabled:int} */
    public function validateConfig(string $owner, string $repo, string $ref, string $root, bool $enabled): array
    {
        $owner = trim($owner);
        $repo = trim($repo);
        // `main` is a human-readable default. A concrete branch/tag is always stored.
        $ref = trim($ref) ?: 'main';
        $root = trim(str_replace('\\', '/', $root), '/');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,38}$/', $owner)
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$/', $repo)) {
            throw new RuntimeException('GitHub-Owner oder Repository ist ungültig.');
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,159}$/', $ref) || str_contains($ref, '..') || str_starts_with($ref, '/')) {
            throw new RuntimeException('Der Git-Ref ist ungültig.');
        }
        if ($root === '' || str_contains($root, '..') || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,254}$/', $root)) {
            throw new RuntimeException('Der Dokumentationspfad ist ungültig.');
        }
        return compact('owner', 'repo', 'ref', 'root') + ['enabled' => $enabled ? 1 : 0];
    }

    public function saveConfig(string $owner, string $repo, string $ref, string $root, bool $enabled): void
    {
        $this->repository->saveSource($this->validateConfig($owner, $repo, $ref, $root, $enabled) + ['source_type'=>'github']);
    }
    public function saveLocalConfig(string $root, bool $enabled): void
    {
        $root = (new LocalWikiPath($this->basePath))->relative($root);
        // Keep a previously configured GitHub source intact, so an administrator can
        // switch source types without having to re-enter its validated coordinates.
        $previous = $this->repository->source() ?? [];
        $this->repository->saveSource([
            'source_type' => 'local',
            'owner' => (string) ($previous['repository_owner'] ?? ''),
            'repo' => (string) ($previous['repository_name'] ?? ''),
            'ref' => (string) ($previous['ref_name'] ?? ''),
            'root' => $root,
            'enabled' => $enabled ? 1 : 0,
        ]);
    }

    /** @return array{added:int,changed:int,deleted:int,sha:string} */
    public function sync(): array
    {
        $source = $this->repository->source();
        if ($source === null || (int) $source['enabled'] !== 1) {
            throw new RuntimeException('Es ist keine aktivierte Wiki-Quelle konfiguriert.');
        }
        $id = (int) $source['id'];
        try {
            $download = (($source['source_type'] ?? 'github') === 'local') ? (new LocalWikiSource(new LocalWikiPath($this->basePath)))->download((string)$source['docs_root']) : $this->client->download((string) $source['repository_owner'], (string) $source['repository_name'], (string) $source['ref_name']);
            return $this->syncArchive($source, $download['archive'], $download['sha']);
        } catch (Throwable $e) {
            $code = $e instanceof WikiSyncException ? $e->safeCode : 'sync_failed';
            $this->repository->failure($id, $code, 'Synchronisierung fehlgeschlagen. Der letzte lokale Stand bleibt verfügbar.');
            $this->repository->run($id, 'failed', null, [], $code);
            (new RotatingFileLogger($this->basePath))->write('wiki', ['event' => 'sync_failed', 'source_id' => $id, 'code' => $code]);
            throw $e;
        }
    }

    /** Testable archive import; GitHub downloads call this after resolving the commit SHA. @return array{added:int,changed:int,deleted:int,sha:string} */
    public function syncArchive(array $source, string $archive, string $sha): array
    {
        if (strlen($archive) > self::MAX_ARCHIVE_BYTES) {
            throw new WikiSyncException('archive_too_large');
        }
        $run = bin2hex(random_bytes(10));
        $staging = $this->basePath . '/storage/modules/modulnest.wiki/staging/' . $run;
        $content = $staging . '/content';
        $archivePath = $staging . '/source.zip';
        $this->mkdir($content);
        try {
            file_put_contents($archivePath, $archive, LOCK_EX);
            $index = $this->extractAndIndex($archivePath, $content, (string) $source['docs_root']);
            $result = $this->activate($source, $content, $index['pages'], $index['assets'], $sha);
            $this->repository->run((int) $source['id'], 'success', $sha, $result);
            (new RotatingFileLogger($this->basePath))->write('wiki', ['event' => 'sync_success', 'source_id' => (int) $source['id'], 'added' => $result['added'], 'changed' => $result['changed'], 'deleted' => $result['deleted']]);
            return $result + ['sha' => $sha];
        } finally {
            $this->removeTree($staging);
        }
    }

    /** @return array{pages:list<array<string,mixed>>,assets:list<array<string,mixed>>} */
    private function extractAndIndex(string $archivePath, string $target, string $docsRoot): array
    {
        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new WikiSyncException('invalid_archive');
        }
        $pages = []; $assets = []; $total = 0; $seen = 0; $root = trim($docsRoot, '/');
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat)) { throw new WikiSyncException('invalid_archive'); }
                $name = (string) ($stat['name'] ?? '');
                if (str_ends_with($name, '/')) { continue; }
                $parts = $this->safeArchivePath($name);
                if (count($parts) < 2) { continue; }
                array_shift($parts); // codeload's immutable top-level directory
                $relative = implode('/', $parts);
                if ($relative !== $root && !str_starts_with($relative, $root . '/')) { continue; }
                $relative = ltrim(substr($relative, strlen($root)), '/');
                if ($relative === '') { continue; }
                if (++$seen > self::MAX_FILES) { throw new WikiSyncException('too_many_files'); }
                $size = (int) ($stat['size'] ?? 0);
                if ($size < 0 || $size > self::MAX_FILE_BYTES || ($total += $size) > self::MAX_UNPACKED_BYTES) { throw new WikiSyncException('content_too_large'); }
                if ($this->isSymlink($zip, $i)) { throw new WikiSyncException('symlink_blocked'); }
                $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
                if (!in_array($extension, ['md', 'markdown'], true) && !isset(self::IMAGE_MIME[$extension])) { continue; }
                $stream = $zip->getStream($name);
                if (!is_resource($stream)) { throw new WikiSyncException('archive_read_failed'); }
                $body = stream_get_contents($stream); fclose($stream);
                if (!is_string($body) || strlen($body) !== $size) { throw new WikiSyncException('archive_read_failed'); }
                $destination = $target . '/' . $relative;
                $this->mkdir(dirname($destination));
                if (in_array($extension, ['md', 'markdown'], true)) {
                    [$meta, $markdown] = $this->frontmatter($body);
                    file_put_contents($destination, $markdown, LOCK_EX);
                    $route = $this->routeFor($relative);
                    $pages[] = ['relative_path' => $relative, 'route_path' => $route, 'title' => $meta['title'] ?? $this->titleFor($markdown, $relative), 'content_hash' => hash('sha256', $markdown), 'sort_order' => (int) ($meta['order'] ?? 1000), 'hidden' => ($meta['hidden'] ?? false) ? 1 : 0, 'source_mtime' => (int) ($stat['mtime'] ?? 0)];
                } else {
                    file_put_contents($destination, $body, LOCK_EX);
                    $assets[] = ['relative_path' => $relative, 'content_hash' => hash('sha256', $body), 'mime_type' => self::IMAGE_MIME[$extension]];
                }
            }
        } finally { $zip->close(); }
        if ($pages === []) { throw new WikiSyncException('no_markdown_found'); }
        // README/index and .md/.markdown variants can legitimately map to the same human route.
        // Keep one deterministic canonical page rather than making a whole sync fail.
        usort($pages, fn (array $a, array $b): int => $this->pageRoutePriority($a) <=> $this->pageRoutePriority($b));
        $unique = [];
        foreach ($pages as $page) {
            if (!isset($unique[$page['route_path']])) $unique[$page['route_path']] = $page;
        }
        $pages = array_values($unique);
        return compact('pages', 'assets');
    }

    private function activate(array $source, string $newContent, array $pages, array $assets, string $sha): array
    {
        $live = $this->basePath . '/storage/modules/modulnest.wiki/content';
        $backup = $this->basePath . '/storage/modules/modulnest.wiki/previous-' . bin2hex(random_bytes(6));
        $this->mkdir(dirname($live));
        $moved = false;
        try {
            if (is_dir($live)) { if (!rename($live, $backup)) { throw new WikiSyncException('content_switch_failed'); } $moved = true; }
            if (!rename($newContent, $live)) { throw new WikiSyncException('content_switch_failed'); }
            $this->pdo->beginTransaction();
            $result = $this->repository->replaceIndex((int) $source['id'], $pages, $assets, $sha, $source);
            ($this->searchIndexer ?? new WikiSearchIndexer($this->pdo))->synchronize((int) $source['id'], $live);
            $this->pdo->commit();
            if ($moved) { $this->removeTree($backup); }
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            if (is_dir($live)) { $this->removeTree($live); }
            if ($moved && is_dir($backup)) { @rename($backup, $live); }
            throw $e;
        }
    }

    /** @return list<string> */
    private function safeArchivePath(string $path): array
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, "\0")) { throw new WikiSyncException('unsafe_archive_path'); }
        $parts = explode('/', $path);
        if (count($parts) > self::MAX_DEPTH + 2 || in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)) { throw new WikiSyncException('unsafe_archive_path'); }
        return $parts;
    }
    private function isSymlink(ZipArchive $zip, int $index): bool { $attrs = $zip->getExternalAttributesIndex($index, $opsys, $attr); return $attrs && $opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000; }
    /** @return array{0:array<string,mixed>,1:string} */
    private function frontmatter(string $body): array { if (!str_starts_with($body, "---\n")) return [[], $body]; $end = strpos($body, "\n---\n", 4); if ($end === false) return [[], $body]; $meta=[]; foreach (explode("\n", substr($body,4,$end-4)) as $line) { if (preg_match('/^(title|order|hidden):\\s*(.+)$/i', trim($line), $m)) { $key=strtolower($m[1]);$value=trim($m[2], " \t\"'"); if($key==='title')$meta['title']=mb_substr($value,0,255); if($key==='order'&&preg_match('/^-?\\d+$/',$value))$meta['order']=(int)$value; if($key==='hidden')$meta['hidden']=in_array(strtolower($value),['1','true','yes'],true); } } return [$meta, substr($body,$end+5)]; }
    private function routeFor(string $path): string { $without=preg_replace('/\\.(md|markdown)$/i','',$path)??$path; $parts=explode('/',$without); $last=strtolower((string)end($parts)); if(in_array($last,['readme','index'],true)){array_pop($parts);} $route=implode('/',$parts); return $route===''?'index':$route; }
    /** @param array{relative_path:string,route_path:string} $page */
    private function pageRoutePriority(array $page): string { $path = (string) $page['relative_path']; $root = ['README.md'=>'1','index.md'=>'2','README.markdown'=>'3','index.markdown'=>'4']; return ((string) $page['route_path'] === 'index' ? ($root[$path] ?? '5') : '5') . ':' . (str_ends_with(strtolower($path), '.md') ? '1' : '2') . ':' . $path; }
    private function titleFor(string $markdown, string $path): string { if(preg_match('/^#\\s+(.+)$/m',$markdown,$m)) return mb_substr(trim($m[1]),0,255); $base=pathinfo($path,PATHINFO_FILENAME); return ucwords(str_replace(['-','_'],' ',$base)); }
    private function mkdir(string $path): void { if(!is_dir($path) && !mkdir($path,0770,true) && !is_dir($path)) throw new WikiSyncException('storage_unavailable'); }
    private function removeTree(string $path): void { if(!is_dir($path)) return; $items=scandir($path); if(!is_array($items)) return; foreach($items as $item){if($item==='.'||$item==='..')continue;$target=$path.'/'.$item;if(is_dir($target)&&!is_link($target))$this->removeTree($target);else @unlink($target);} @rmdir($path); }
}

final class WikiSyncException extends RuntimeException
{
    public function __construct(public readonly string $safeCode) { parent::__construct($safeCode); }
}
