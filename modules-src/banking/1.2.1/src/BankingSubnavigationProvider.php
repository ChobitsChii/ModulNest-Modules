<?php

declare(strict_types=1);

namespace ModulNest\Banking;

use Modulon\Core\ModuleSubnavigationProviderInterface;

final class BankingSubnavigationProvider implements ModuleSubnavigationProviderInterface
{
    /**
     * @var array<int, array{key:string,label:string,url:string,description:string}>
     */
    private const ITEMS = [
        [
            'key' => 'overview',
            'label' => 'Übersicht',
            'url' => '/banking',
            'description' => 'Kennzahlen und letzte Buchungen',
        ],
        [
            'key' => 'transactions',
            'label' => 'Umsätze',
            'url' => '/banking/transactions',
            'description' => 'Buchungsliste und Filter',
        ],
        [
            'key' => 'monthly-overview',
            'label' => 'Monatsübersicht',
            'url' => '/banking/overview',
            'description' => 'Monatliche Einnahmen und Ausgaben',
        ],
        [
            'key' => 'recurring',
            'label' => 'Wiederkehrend',
            'url' => '/banking/recurring',
            'description' => 'Regeln und Bedingungen',
        ],
        [
            'key' => 'recurring-overview',
            'label' => 'Fälligkeitsstatus',
            'url' => '/banking/recurring/overview',
            'description' => 'Status wiederkehrender Zahlungen',
        ],
        [
            'key' => 'import',
            'label' => 'Import',
            'url' => '/banking/import',
            'description' => 'Sparkassen-CSV importieren',
        ],
    ];

    public function moduleKey(): string
    {
        return 'banking';
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
        $current = rtrim('/' . trim($currentPath, '/'), '/');
        if ($current === '') {
            return 'overview';
        }

        $active = 'overview';
        $activeLength = 0;
        foreach (self::ITEMS as $item) {
            $target = rtrim('/' . trim($item['url'], '/'), '/');
            if ($target === '/banking') {
                if ($current === '/banking' && strlen($target) > $activeLength) {
                    $active = $item['key'];
                    $activeLength = strlen($target);
                }
                continue;
            }

            if (($current === $target || str_starts_with($current, $target . '/')) && strlen($target) > $activeLength) {
                $active = $item['key'];
                $activeLength = strlen($target);
            }
        }

        return $active;
    }
}
