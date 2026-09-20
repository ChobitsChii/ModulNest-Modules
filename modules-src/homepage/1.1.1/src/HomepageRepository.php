<?php

declare(strict_types=1);

namespace ModulNest\Homepage;

use Modulon\Modules\Admin\AppSettingRepository;
use PDO;

final class HomepageRepository
{
    public const TYPES = ['custom_content', 'module_list', 'feature_list'];
    public const COLUMN_SPANS = ['full', 'half', 'two_thirds', 'one_third', 'third'];
    public const BUTTON_VARIANTS = ['primary', 'secondary'];
    public const BUTTON_LAYOUTS = ['below_text', 'inline_right'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly AppSettingRepository $settings,
    ) {
    }

    public function isPublished(): bool
    {
        return $this->settings->getBool('homepage.is_published', false);
    }

    public function setPublished(bool $published): void
    {
        $this->settings->setBool('homepage.is_published', $published);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBlock(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, type, title, show_title, content_markdown, button_label, button_url, button_layout,
                    visibility_guest, visibility_user, visibility_admin,
                    sort_order, column_span, is_enabled, created_at, updated_at
             FROM homepage_blocks
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return $this->hydrateBlocks([$row])[0] ?? $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listBlocks(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, type, title, show_title, content_markdown, button_label, button_url, button_layout,
                    visibility_guest, visibility_user, visibility_admin,
                    sort_order, column_span, is_enabled, created_at, updated_at
             FROM homepage_blocks
             ORDER BY sort_order ASC, id ASC'
        );

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $this->hydrateBlocks($rows) : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function enabledBlocksForAudience(string $audience): array
    {
        $column = match ($audience) {
            'admin' => 'visibility_admin',
            'user' => 'visibility_user',
            default => 'visibility_guest',
        };

        $statement = $this->pdo->query(
            'SELECT id, type, title, show_title, content_markdown, button_label, button_url, button_layout,
                    visibility_guest, visibility_user, visibility_admin,
                    sort_order, column_span, is_enabled, created_at, updated_at
             FROM homepage_blocks
             WHERE is_enabled = 1 AND ' . $column . ' = 1
             ORDER BY sort_order ASC, id ASC'
        );

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $this->hydrateBlocks($rows) : [];
    }

    /**
     * @param array{
     *   type:string,title:string,show_title:bool,content_markdown:?string,button_label:?string,button_url:?string,
     *   visibility_guest:bool,visibility_user:bool,visibility_admin:bool,column_span:string,button_layout:string,is_enabled:bool,
     *   buttons:array<int,array{label:string,url:string,variant:string}>,
     *   items:array<int,array{title:string,content_markdown:?string}>
     * } $data
     */
    public function createBlock(array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO homepage_blocks
                (type, title, show_title, content_markdown, button_label, button_url, button_layout,
                 visibility_guest, visibility_user, visibility_admin, sort_order, column_span, is_enabled)
             VALUES
                (:type, :title, :show_title, :content_markdown, :button_label, :button_url, :button_layout,
                 :visibility_guest, :visibility_user, :visibility_admin, :sort_order, :column_span, :is_enabled)'
        );
        $this->pdo->beginTransaction();
        try {
            $statement->execute([
                'type' => $data['type'],
                'title' => $data['title'],
                'show_title' => ($data['show_title'] ?? true) ? 1 : 0,
                'content_markdown' => $data['content_markdown'],
                'button_label' => $data['button_label'],
                'button_url' => $data['button_url'],
                'button_layout' => $this->normalizeButtonLayout((string) ($data['button_layout'] ?? 'below_text')),
                'visibility_guest' => $data['visibility_guest'] ? 1 : 0,
                'visibility_user' => $data['visibility_user'] ? 1 : 0,
                'visibility_admin' => $data['visibility_admin'] ? 1 : 0,
                'sort_order' => $this->nextSortOrder(),
                'column_span' => $this->normalizeColumnSpan((string) $data['column_span']),
                'is_enabled' => $data['is_enabled'] ? 1 : 0,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->replaceButtons($id, $data['buttons'] ?? []);
            $this->replaceItems($id, $data['items'] ?? []);
            $this->pdo->commit();

            return $id;
        } catch (\Throwable $throwable) {
            $this->pdo->rollBack();
            throw $throwable;
        }
    }

    /**
     * @param array{
     *   type:string,title:string,show_title:bool,content_markdown:?string,button_label:?string,button_url:?string,
     *   visibility_guest:bool,visibility_user:bool,visibility_admin:bool,column_span:string,button_layout:string,is_enabled:bool,
     *   buttons:array<int,array{label:string,url:string,variant:string}>,
     *   items:array<int,array{title:string,content_markdown:?string}>
     * } $data
     */
    public function updateBlock(int $id, array $data): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE homepage_blocks
             SET type = :type,
                 title = :title,
                 show_title = :show_title,
                 content_markdown = :content_markdown,
                 button_label = :button_label,
                 button_url = :button_url,
                 button_layout = :button_layout,
                 visibility_guest = :visibility_guest,
                 visibility_user = :visibility_user,
                 visibility_admin = :visibility_admin,
                 column_span = :column_span,
                 is_enabled = :is_enabled,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $this->pdo->beginTransaction();
        try {
            $statement->execute([
                'id' => $id,
                'type' => $data['type'],
                'title' => $data['title'],
                'show_title' => ($data['show_title'] ?? true) ? 1 : 0,
                'content_markdown' => $data['content_markdown'],
                'button_label' => $data['button_label'],
                'button_url' => $data['button_url'],
                'button_layout' => $this->normalizeButtonLayout((string) ($data['button_layout'] ?? 'below_text')),
                'visibility_guest' => $data['visibility_guest'] ? 1 : 0,
                'visibility_user' => $data['visibility_user'] ? 1 : 0,
                'visibility_admin' => $data['visibility_admin'] ? 1 : 0,
                'column_span' => $this->normalizeColumnSpan((string) $data['column_span']),
                'is_enabled' => $data['is_enabled'] ? 1 : 0,
            ]);
            $this->replaceButtons($id, $data['buttons'] ?? []);
            $this->replaceItems($id, $data['items'] ?? []);
            $this->pdo->commit();
        } catch (\Throwable $throwable) {
            $this->pdo->rollBack();
            throw $throwable;
        }
    }

    public function setBlockEnabled(int $id, bool $enabled): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE homepage_blocks
             SET is_enabled = :is_enabled,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $id,
            'is_enabled' => $enabled ? 1 : 0,
        ]);
    }

    public function setBlockVisibility(int $id, string $field, bool $visible): void
    {
        if (!in_array($field, ['visibility_guest', 'visibility_user', 'visibility_admin'], true)) {
            throw new \RuntimeException('Ungültige Sichtbarkeit.');
        }

        $statement = $this->pdo->prepare(
            'UPDATE homepage_blocks
             SET ' . $field . ' = :visible,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $id,
            'visible' => $visible ? 1 : 0,
        ]);
    }

    public function deleteBlock(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM homepage_blocks WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function moveBlock(int $id, string $direction): void
    {
        $current = $this->findBlock($id);
        if ($current === null) {
            return;
        }

        $operator = $direction === 'up' ? '<' : '>';
        $order = $direction === 'up' ? 'DESC' : 'ASC';
        $statement = $this->pdo->prepare(
            'SELECT id, sort_order
             FROM homepage_blocks
             WHERE sort_order ' . $operator . ' :sort_order
             ORDER BY sort_order ' . $order . ', id ' . $order . '
             LIMIT 1'
        );
        $statement->execute(['sort_order' => (int) ($current['sort_order'] ?? 0)]);
        $other = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($other)) {
            return;
        }

        $update = $this->pdo->prepare('UPDATE homepage_blocks SET sort_order = :sort_order WHERE id = :id');
        $this->pdo->beginTransaction();
        try {
            $update->execute([
                'id' => (int) ($current['id'] ?? 0),
                'sort_order' => (int) ($other['sort_order'] ?? 0),
            ]);
            $update->execute([
                'id' => (int) ($other['id'] ?? 0),
                'sort_order' => (int) ($current['sort_order'] ?? 0),
            ]);
            $this->pdo->commit();
        } catch (\Throwable $throwable) {
            $this->pdo->rollBack();
            throw $throwable;
        }
    }

    private function nextSortOrder(): int
    {
        $max = $this->pdo->query('SELECT MAX(sort_order) FROM homepage_blocks')->fetchColumn();
        $maxValue = is_numeric($max) ? (int) $max : 0;

        return $maxValue + 10;
    }

    /**
     * @param array<int,array<string,mixed>> $blocks
     * @return array<int,array<string,mixed>>
     */
    private function hydrateBlocks(array $blocks): array
    {
        $ids = array_values(array_filter(array_map(static fn (array $block): int => (int) ($block['id'] ?? 0), $blocks)));
        if ($ids === []) {
            return $blocks;
        }

        $buttonsByBlock = $this->buttonsByBlock($ids);
        $itemsByBlock = $this->itemsByBlock($ids);
        foreach ($blocks as &$block) {
            $id = (int) ($block['id'] ?? 0);
            $block['column_span'] = $this->normalizeColumnSpan((string) ($block['column_span'] ?? 'full'));
            $block['button_layout'] = $this->normalizeButtonLayout((string) ($block['button_layout'] ?? 'below_text'));
            $block['show_title'] = (int) ($block['show_title'] ?? 1) === 1 ? 1 : 0;
            $block['buttons'] = $buttonsByBlock[$id] ?? [];
            $block['items'] = $itemsByBlock[$id] ?? [];
        }
        unset($block);

        return $blocks;
    }

    /**
     * @param array<int,int> $blockIds
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function buttonsByBlock(array $blockIds): array
    {
        $placeholders = implode(',', array_fill(0, count($blockIds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT id, block_id, label, url, variant, sort_order
             FROM homepage_block_buttons
             WHERE block_id IN (' . $placeholders . ')
             ORDER BY block_id ASC, sort_order ASC, id ASC'
        );
        $statement->execute($blockIds);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        $grouped = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $grouped[(int) ($row['block_id'] ?? 0)][] = $row;
        }

        return $grouped;
    }

    /**
     * @param array<int,int> $blockIds
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function itemsByBlock(array $blockIds): array
    {
        $placeholders = implode(',', array_fill(0, count($blockIds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT id, block_id, title, content_markdown, sort_order
             FROM homepage_block_items
             WHERE block_id IN (' . $placeholders . ')
             ORDER BY block_id ASC, sort_order ASC, id ASC'
        );
        $statement->execute($blockIds);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        $grouped = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $grouped[(int) ($row['block_id'] ?? 0)][] = $row;
        }

        return $grouped;
    }

    /**
     * @param array<int,array{label:string,url:string,variant:string}> $buttons
     */
    private function replaceButtons(int $blockId, array $buttons): void
    {
        $this->pdo->prepare('DELETE FROM homepage_block_buttons WHERE block_id = :block_id')->execute(['block_id' => $blockId]);
        if ($buttons === []) {
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO homepage_block_buttons (block_id, label, url, variant, sort_order)
             VALUES (:block_id, :label, :url, :variant, :sort_order)'
        );
        $order = 10;
        foreach ($buttons as $button) {
            $insert->execute([
                'block_id' => $blockId,
                'label' => $button['label'],
                'url' => $button['url'],
                'variant' => in_array($button['variant'], self::BUTTON_VARIANTS, true) ? $button['variant'] : 'primary',
                'sort_order' => $order,
            ]);
            $order += 10;
        }
    }

    /**
     * @param array<int,array{title:string,content_markdown:?string}> $items
     */
    private function replaceItems(int $blockId, array $items): void
    {
        $this->pdo->prepare('DELETE FROM homepage_block_items WHERE block_id = :block_id')->execute(['block_id' => $blockId]);
        if ($items === []) {
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO homepage_block_items (block_id, title, content_markdown, sort_order)
             VALUES (:block_id, :title, :content_markdown, :sort_order)'
        );
        $order = 10;
        foreach ($items as $item) {
            $insert->execute([
                'block_id' => $blockId,
                'title' => $item['title'],
                'content_markdown' => $item['content_markdown'],
                'sort_order' => $order,
            ]);
            $order += 10;
        }
    }

    private function normalizeColumnSpan(string $span): string
    {
        return $span === 'third' ? 'one_third' : (in_array($span, ['full', 'half', 'two_thirds', 'one_third'], true) ? $span : 'full');
    }

    private function normalizeButtonLayout(string $layout): string
    {
        return in_array($layout, self::BUTTON_LAYOUTS, true) ? $layout : 'below_text';
    }
}
