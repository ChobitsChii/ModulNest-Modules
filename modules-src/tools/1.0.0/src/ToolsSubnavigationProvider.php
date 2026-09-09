<?php

declare(strict_types=1);

namespace ModulNest\Tools;

use Modulon\Core\ModuleSubnavigationProviderInterface;

final class ToolsSubnavigationProvider implements ModuleSubnavigationProviderInterface
{
    public function moduleKey(): string
    {
        return 'tools';
    }

    public function items(string $currentPath): array
    {
        $baseActive = rtrim('/' . trim($currentPath, '/'), '/') === '/tools';

        return [
            [
                'key' => 'overview',
                'label' => 'Übersicht',
                'url' => '/tools',
                'description' => 'Alle Hilfswerkzeuge',
                'is_active' => $baseActive,
            ],
            [
                'key' => 'text',
                'label' => 'Text',
                'url' => '/tools#tools-text',
                'description' => 'Zähler, Bereinigung und Markdown',
                'is_active' => false,
            ],
            [
                'key' => 'developer',
                'label' => 'Entwickler',
                'url' => '/tools#tools-entwickler',
                'description' => 'JSON, UUID, Regex, QR',
                'is_active' => false,
            ],
            [
                'key' => 'security',
                'label' => 'Sicherheit',
                'url' => '/tools#tools-sicherheit',
                'description' => 'Passwort und Hashes',
                'is_active' => false,
            ],
        ];
    }
}
