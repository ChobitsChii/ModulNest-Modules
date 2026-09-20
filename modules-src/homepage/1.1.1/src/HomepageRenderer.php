<?php

declare(strict_types=1);

namespace ModulNest\Homepage;

use Modulon\Core\MarkdownRenderer;
use Modulon\Core\Modules\RootPageProviderInterface;
use Throwable;

final class HomepageRenderer implements RootPageProviderInterface
{
    public function __construct(
        private readonly HomepageRepository $repository,
        private readonly MarkdownRenderer $markdown,
    ) {
    }

    public function view(): string
    {
        return '@modulnest.homepage/render';
    }

    /**
     * @param array<string, mixed>|null $user
     * @param array<int, array<string, mixed>> $availableModules
     * @return array{audience:string,blocks:array<int,array<string,mixed>>}|null
     */
    public function build(?array $user, bool $isAdmin, array $availableModules): ?array
    {
        try {
            if (!$this->repository->isPublished()) {
                return null;
            }

            $audience = $isAdmin ? 'admin' : ($user !== null ? 'user' : 'guest');
            $blocks = $this->prepareBlocks($this->repository->enabledBlocksForAudience($audience), $availableModules);

            if ($blocks === []) {
                return null;
            }

            return [
                'audience' => $audience,
                'blocks' => $blocks,
            ];
        } catch (Throwable $throwable) {
            error_log('Homepage rendering fallback: ' . $throwable->getMessage());

            return null;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $availableModules
     * @return array<int, array<string, mixed>>
     */
    public function prepareBlocks(array $blocks, array $availableModules, bool $includeDisabled = false): array
    {
        $prepared = [];
        foreach ($blocks as $block) {
            if (!$includeDisabled && (int) ($block['is_enabled'] ?? 1) !== 1) {
                continue;
            }

            $item = $this->prepareBlock($block, $availableModules);
            if ($item !== null) {
                $prepared[] = $item;
            }
        }

        return $prepared;
    }

    /**
     * @param array<string, mixed> $block
     * @param array<int, array<string, mixed>> $availableModules
     * @return array<string, mixed>|null
     */
    private function prepareBlock(array $block, array $availableModules): ?array
    {
        $type = (string) ($block['type'] ?? '');
        $title = trim((string) ($block['title'] ?? ''));
        if ($title === '') {
            return null;
        }

        $span = $this->normalizeColumnSpan((string) ($block['column_span'] ?? 'full'));
        $prepared = [
            'id' => (int) ($block['id'] ?? 0),
            'type' => $type,
            'title' => $title,
            'show_title' => (int) ($block['show_title'] ?? 1) === 1,
            'column_span' => $span,
            'button_layout' => $this->normalizeButtonLayout((string) ($block['button_layout'] ?? 'below_text')),
            'is_enabled' => (int) ($block['is_enabled'] ?? 1) === 1,
            'visibility_guest' => (int) ($block['visibility_guest'] ?? 0) === 1,
            'visibility_user' => (int) ($block['visibility_user'] ?? 0) === 1,
            'visibility_admin' => (int) ($block['visibility_admin'] ?? 0) === 1,
        ];

        if ($type === 'custom_content') {
            $html = $this->markdown->render((string) ($block['content_markdown'] ?? ''));
            $prepared['content_html'] = $html;
            $prepared['buttons'] = $this->prepareButtons($block);
            if ($html === '' && $prepared['buttons'] === []) {
                return null;
            }

            return $prepared;
        }

        if ($type === 'module_list') {
            $introHtml = $this->markdown->render((string) ($block['content_markdown'] ?? ''));
            $prepared['content_html'] = $introHtml;
            $prepared['modules'] = $availableModules;

            return $prepared;
        }

        if ($type === 'feature_list') {
            $introHtml = $this->markdown->render((string) ($block['content_markdown'] ?? ''));
            $items = [];
            foreach (is_array($block['items'] ?? null) ? $block['items'] : [] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $itemTitle = trim((string) ($item['title'] ?? ''));
                if ($itemTitle === '') {
                    continue;
                }
                $items[] = [
                    'title' => $itemTitle,
                    'content_html' => $this->markdown->render((string) ($item['content_markdown'] ?? '')),
                ];
            }

            if ($introHtml === '' && $items === []) {
                return null;
            }

            $prepared['content_html'] = $introHtml;
            $prepared['items'] = $items;

            return $prepared;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $block
     * @return array<int,array{label:string,url:string,variant:string}>
     */
    private function prepareButtons(array $block): array
    {
        $buttons = [];
        foreach (is_array($block['buttons'] ?? null) ? $block['buttons'] : [] as $button) {
            if (!is_array($button)) {
                continue;
            }
            $label = trim((string) ($button['label'] ?? ''));
            $url = $this->safeButtonUrl((string) ($button['url'] ?? ''));
            if ($label === '' || $url === '') {
                continue;
            }
            $variant = (string) ($button['variant'] ?? 'primary');
            $buttons[] = [
                'label' => $label,
                'url' => $url,
                'variant' => $variant === 'secondary' ? 'secondary' : 'primary',
            ];
        }

        if ($buttons !== []) {
            return $buttons;
        }

        $legacyLabel = trim((string) ($block['button_label'] ?? ''));
        $legacyUrl = $this->safeButtonUrl((string) ($block['button_url'] ?? ''));
        if ($legacyLabel !== '' && $legacyUrl !== '') {
            return [[
                'label' => $legacyLabel,
                'url' => $legacyUrl,
                'variant' => 'primary',
            ]];
        }

        return [];
    }

    private function normalizeColumnSpan(string $span): string
    {
        $span = $span === 'third' ? 'one_third' : $span;

        return in_array($span, ['full', 'half', 'two_thirds', 'one_third'], true) ? $span : 'full';
    }

    private function normalizeButtonLayout(string $layout): string
    {
        return in_array($layout, HomepageRepository::BUTTON_LAYOUTS, true) ? $layout : 'below_text';
    }

    private function safeButtonUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (str_starts_with($url, '/')) {
            return preg_match('~^/[^\s<>"]*$~', $url) === 1 ? $url : '';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (in_array($scheme, ['http', 'https'], true) && filter_var($url, FILTER_VALIDATE_URL) !== false) {
            return $url;
        }

        return '';
    }
}
