<?php

declare(strict_types=1);

namespace ModulNest\Wiki;

/** Builds a source-structure-preserving navigation tree for the Wiki view. */
final class WikiNavigationBuilder
{
    /**
     * @param list<array<string, mixed>> $pages
     * @return array{root_route:string,groups:list<array<string, mixed>>,root_pages:list<array<string, mixed>>}
     */
    public function build(array $pages, string $activeRoute, string $rootRoute): array
    {
        $tree = ['pages' => [], 'children' => []];

        foreach ($pages as $page) {
            if ((string) ($page['route_path'] ?? '') === $rootRoute) {
                continue;
            }

            $relativePath = trim((string) ($page['relative_path'] ?? ''), '/');
            $directory = dirname($relativePath);
            $segments = $directory === '.' ? [] : array_values(array_filter(explode('/', $directory), static fn (string $part): bool => $part !== ''));
            $node =& $tree;
            foreach ($segments as $segment) {
                if (!isset($node['children'][$segment])) {
                    $node['children'][$segment] = ['pages' => [], 'children' => []];
                }
                $node =& $node['children'][$segment];
            }
            $node['pages'][] = $this->page($page, $activeRoute);
            unset($node);
        }

        return [
            'root_route' => $rootRoute,
            'groups' => $this->groups($tree['children'], $activeRoute),
            'root_pages' => $this->sortPages($tree['pages']),
        ];
    }

    /** @param array<string, array<string, mixed>> $children @return list<array<string, mixed>> */
    private function groups(array $children, string $activeRoute, string $parent = ''): array
    {
        ksort($children, SORT_NATURAL | SORT_FLAG_CASE);
        $groups = [];
        foreach ($children as $segment => $node) {
            $path = ltrim($parent . '/' . $segment, '/');
            $nested = $this->groups($node['children'], $activeRoute, $path);
            $pages = $this->sortPages($node['pages']);
            $landing = null;
            foreach ($pages as $index => $page) {
                if ((string) ($page['route_path'] ?? '') === $path) { $landing = $page; unset($pages[$index]); $pages = array_values($pages); break; }
            }
            $isActive = ($landing['is_active'] ?? false) || $this->containsActive($pages) || $this->containsActive($nested);
            $groups[] = [
                'label' => $this->label((string) $segment),
                'key' => (string) $segment,
                'path' => $path,
                'landing' => $landing,
                'pages' => $pages,
                'groups' => $nested,
                'is_active' => $isActive,
            ];
        }
        return $groups;
    }

    /** @param list<array<string, mixed>> $pages @return list<array<string, mixed>> */
    private function sortPages(array $pages): array
    {
        usort($pages, static function (array $left, array $right): int {
            $byOrder = ((int) $left['sort_order']) <=> ((int) $right['sort_order']);
            if ($byOrder !== 0) {
                return $byOrder;
            }
            return strnatcasecmp((string) $left['relative_path'], (string) $right['relative_path']);
        });
        return $pages;
    }

    /** @param list<array<string, mixed>> $items */
    private function containsActive(array $items): bool
    {
        foreach ($items as $item) {
            if (($item['is_active'] ?? false) === true || (isset($item['pages']) && $this->containsActive($item['pages'])) || (isset($item['groups']) && $this->containsActive($item['groups']))) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $page @return array<string, mixed> */
    private function page(array $page, string $activeRoute): array
    {
        $page['is_active'] = (string) ($page['route_path'] ?? '') === $activeRoute;
        return $page;
    }

    private function label(string $segment): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $segment));
    }
}
