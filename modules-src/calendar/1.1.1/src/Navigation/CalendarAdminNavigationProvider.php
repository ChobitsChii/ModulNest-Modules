<?php

declare(strict_types=1);

namespace ModulNest\Calendar\Navigation;

use Modulon\Core\AdminNavigationProviderInterface;

final class CalendarAdminNavigationProvider implements AdminNavigationProviderInterface
{
    public function moduleKey(): string
    {
        return 'calendar';
    }

    /**
     * @return array<int, array{key:string,label:string,url:string,is_active:bool,description?:string,sort_order?:int}>
     */
    public function items(string $currentPath): array
    {
        return [
            [
                'key' => 'calendar',
                'label' => 'Kalender',
                'url' => '/admin/calendar',
                'is_active' => str_starts_with($currentPath, '/admin/calendar'),
                'description' => 'Google Calendar OAuth & Synchronisation konfigurieren',
                'sort_order' => 60,
            ],
        ];
    }
}
