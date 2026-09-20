<?php

declare(strict_types=1);

namespace ModulNest\Mirror\Service;

use RuntimeException;

final class MirrorPathResolver
{
    private const BLOCKED = ['.ssh', '.gnupg', '.cache', '.local', '.bashrc', '.profile', '.git'];

    private string $homeRoot;

    public function __construct(?string $homeRoot = null)
    {
        if ($homeRoot !== null && is_dir($homeRoot)) {
            $this->homeRoot = (string) realpath($homeRoot);
            return;
        }

        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
        if (!$home && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $info = @posix_getpwuid(posix_geteuid());
            $home = $info['dir'] ?? '';
        }

        if (!$home || !is_dir($home)) {
            // Fallback to parent of project root
            $home = dirname(dirname(dirname(dirname(__DIR__))));
        }

        $real = realpath($home);
        $this->homeRoot = $real !== false ? $real : '/home';
    }

    public function home(): string
    {
        return $this->homeRoot;
    }

    /**
     * @return array{
     *     home: string,
     *     current: string,
     *     parent: ?string,
     *     directories: list<string>
     * }
     */
    public function listDirectories(string $subpath = ''): array
    {
        $target = $this->resolvePath($subpath);
        if (!is_dir($target) || !is_readable($target)) {
            throw new RuntimeException('Das Verzeichnis ist nicht lesbar oder existiert nicht.');
        }

        $items = scandir($target);
        if ($items === false) {
            $items = [];
        }

        $dirs = [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || str_starts_with($item, '.') || in_array($item, self::BLOCKED, true)) {
                continue;
            }

            $full = $target . '/' . $item;
            if (is_dir($full) && is_readable($full)) {
                $dirs[] = $item;
            }
        }

        natcasesort($dirs);

        $parent = null;
        if ($target !== $this->homeRoot) {
            $p = dirname($target);
            if (str_starts_with($p, $this->homeRoot)) {
                $parent = $p;
            }
        }

        return [
            'home' => $this->homeRoot,
            'current' => $target,
            'parent' => $parent,
            'directories' => array_values($dirs),
        ];
    }

    public function resolvePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path === '/') {
            return $this->homeRoot;
        }

        // If path is absolute
        if (str_starts_with($path, '/')) {
            $real = realpath($path);
            if ($real === false || !str_starts_with($real, $this->homeRoot)) {
                // If it doesn't exist yet but parent does
                $parent = dirname($path);
                $realParent = realpath($parent);
                if ($realParent !== false && str_starts_with($realParent, $this->homeRoot)) {
                    return $realParent . '/' . basename($path);
                }
                throw new RuntimeException('Pfad liegt außerhalb des zulässigen Home-Verzeichnisses.');
            }
            return $real;
        }

        // Relative to home
        $combined = $this->homeRoot . '/' . ltrim($path, '/');
        $real = realpath($combined);
        if ($real === false || !str_starts_with($real, $this->homeRoot)) {
            $parent = dirname($combined);
            $realParent = realpath($parent);
            if ($realParent !== false && str_starts_with($realParent, $this->homeRoot)) {
                return $realParent . '/' . basename($combined);
            }
            throw new RuntimeException('Pfad liegt außerhalb des zulässigen Home-Verzeichnisses.');
        }

        return $real;
    }
}
