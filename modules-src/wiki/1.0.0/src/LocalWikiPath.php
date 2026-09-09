<?php
declare(strict_types=1);
namespace ModulNest\Wiki;

use RuntimeException;

/** Resolves local Wiki paths relative to the current installation only. */
final class LocalWikiPath
{
    private const BLOCKED = ['.git', '.local', '.idea', 'storage', 'vendor', 'node_modules', '.env'];
    public function __construct(private readonly string $root) {}
    public function relative(string $path): string
    {
        $path = trim(str_replace('\\', '/', rawurldecode($path)), '/');
        if (str_contains($path, "\0") || $path === '' || str_starts_with($path, '/') || preg_match('#(^|/)\.\.?(/|$)#', $path)) throw new RuntimeException('Der lokale Dokumentationspfad ist ungültig.');
        $parts = explode('/', $path);
        foreach ($parts as $part) if ($part === '' || in_array($part, self::BLOCKED, true)) throw new RuntimeException('Dieses lokale Verzeichnis ist nicht verfügbar.');
        $base = realpath($this->root); $candidate = realpath($this->root . '/' . $path);
        if ($base === false || $candidate === false || !is_dir($candidate) || !is_readable($candidate) || !str_starts_with($candidate . '/', rtrim($base, '/') . '/')) throw new RuntimeException('Das lokale Verzeichnis ist nicht verfügbar.');
        return implode('/', $parts);
    }
    public function absolute(string $path): string { $relative=$this->relative($path); return (string) realpath($this->root . '/' . $relative); }
    public function root(): string { return (string) realpath($this->root); }
    /** @return array{path:string,parent:string,directories:list<string>} */
    public function directories(string $path): array
    {
        $relative = $path === '' ? '' : $this->relative($path);
        $absolute = $relative === '' ? realpath($this->root) : realpath($this->root . '/' . $relative);
        if ($absolute === false) throw new RuntimeException('Das lokale Verzeichnis ist nicht verfügbar.');
        $names=[]; foreach (scandir($absolute) ?: [] as $name) { if ($name==='.'||$name==='..'||str_starts_with($name,'.')||in_array($name,self::BLOCKED,true)) continue; if (is_dir($absolute.'/'.$name)) { try { $this->relative(trim($relative.'/'.$name,'/')); $names[]=$name; } catch (RuntimeException) {} } }
        natcasesort($names); return ['path'=>$relative,'parent'=>$relative === '' ? '' : (dirname($relative) === '.' ? '' : dirname($relative)),'directories'=>array_values($names)];
    }
}
