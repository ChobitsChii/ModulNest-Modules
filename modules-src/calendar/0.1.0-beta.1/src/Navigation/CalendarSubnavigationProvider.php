<?php

declare(strict_types=1);

namespace ModulNest\Calendar\Navigation;

use Modulon\Core\ModuleSubnavigationProviderInterface;

final class CalendarSubnavigationProvider implements ModuleSubnavigationProviderInterface
{
    private const ITEMS = [
        'day' => [
            'key' => 'day',
            'label' => 'Tag',
            'url' => '/calendar/day',
            'description' => 'Tagesansicht',
        ],
        'week' => [
            'key' => 'week',
            'label' => 'Woche',
            'url' => '/calendar/week',
            'description' => 'Wochenansicht',
        ],
        'month' => [
            'key' => 'month',
            'label' => 'Monat',
            'url' => '/calendar/month',
            'description' => 'Monatsansicht',
        ],
    ];

    public function moduleKey(): string
    {
        return 'calendar';
    }

    public function items(string $currentPath): array
    {
        $activeKey = $this->activeKey($currentPath);

        return array_map(
            static fn (array $item): array => [
                'key' => $item['key'],
                'label' => $item['label'],
                'url' => $item['url'],
                'description' => $item['description'],
                'is_active' => $item['key'] === $activeKey,
            ],
            self::ITEMS
        );
    }

    private function activeKey(string $currentPath): string
    {
        $path = rtrim('/' . trim((string) parse_url($currentPath, PHP_URL_PATH), '/'), '/');

        if ($path === '/calendar/week') {
            return 'week';
        }
        if ($path === '/calendar/month') {
            return 'month';
        }

        return 'day';
    }
}
