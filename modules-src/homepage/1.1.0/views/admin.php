<?php
declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$csrfToken = (string) ($csrf_token ?? '');
$message = (string) ($message ?? '');
$error = (string) ($error ?? '');
$isPublished = (bool) ($is_published ?? false);
$blocks = is_array($blocks ?? null) ? $blocks : [];
$editBlock = is_array($edit_block ?? null) ? $edit_block : null;
$previewBlocks = is_array($preview_blocks ?? null) ? $preview_blocks : [];
$previewMode = (string) ($preview_mode ?? 'all');
if (!in_array($previewMode, ['all', 'guest', 'user', 'admin'], true)) {
    $previewMode = 'all';
}

$enabledBlocks = array_values(array_filter($blocks, static fn (mixed $block): bool => is_array($block) && (int) ($block['is_enabled'] ?? 0) === 1));
$isEdit = $editBlock !== null;
$formBlock = $editBlock ?? [
    'type' => 'custom_content',
    'title' => '',
    'show_title' => 1,
    'content_markdown' => '',
    'button_label' => '',
    'button_url' => '',
    'buttons' => [],
    'items' => [],
    'visibility_guest' => 1,
    'visibility_user' => 1,
    'visibility_admin' => 1,
    'column_span' => 'full',
    'button_layout' => 'below_text',
    'is_enabled' => 1,
];
$formButtons = is_array($formBlock['buttons'] ?? null) ? $formBlock['buttons'] : [];
if ($formButtons === [] && (string) ($formBlock['button_label'] ?? '') !== '' && (string) ($formBlock['button_url'] ?? '') !== '') {
    $formButtons[] = [
        'label' => (string) ($formBlock['button_label'] ?? ''),
        'url' => (string) ($formBlock['button_url'] ?? ''),
        'variant' => 'primary',
    ];
}
$formItems = is_array($formBlock['items'] ?? null) ? $formBlock['items'] : [];

$visibilityLabels = [
    'visibility_guest' => 'Gäste',
    'visibility_user' => 'User',
    'visibility_admin' => 'Admins',
];
$typeLabels = [
    'custom_content' => 'Inhalt',
    'feature_list' => 'Feature-Liste',
    'module_list' => 'Modulliste',
];
$spanLabels = [
    'full' => 'Volle Breite',
    'half' => 'Halb',
    'two_thirds' => 'Zwei Drittel',
    'one_third' => 'Ein Drittel',
];
$buttonVariants = [
    'primary' => 'Primär',
    'secondary' => 'Sekundär',
];
$buttonLayoutLabels = [
    'below_text' => 'Buttons unter dem Text',
    'inline_right' => 'Text links, Buttons rechts',
];
$columnClass = static function (string $span): string {
    return match ($span) {
        'half' => 'col-12 col-lg-6',
        'two_thirds' => 'col-12 col-lg-8',
        'one_third', 'third' => 'col-12 col-lg-4',
        default => 'col-12',
    };
};
$buttonClass = static fn (string $variant): string => $variant === 'secondary' ? 'btn btn-outline-secondary btn-sm' : 'btn btn-primary btn-sm';
$renderButtons = static function (array $buttons, bool $withMargin = true) use ($e, $buttonClass): string {
    if ($buttons === []) {
        return '';
    }
    $html = '<div class="homepage-button-row' . ($withMargin ? ' mt-3' : '') . '">';
    foreach ($buttons as $button) {
        if (!is_array($button)) {
            continue;
        }
        $label = trim((string) ($button['label'] ?? ''));
        $url = trim((string) ($button['url'] ?? ''));
        if ($label === '' || $url === '') {
            continue;
        }
        $html .= '<a href="' . $e($url) . '" class="' . $e($buttonClass((string) ($button['variant'] ?? 'primary'))) . '">' . $e($label) . '</a>';
    }
    return $html . '</div>';
};
$renderMetaBar = static function (array $block) use ($e, $typeLabels, $spanLabels, $visibilityLabels): string {
    $type = (string) ($block['type'] ?? '');
    $span = (string) ($block['column_span'] ?? 'full');
    $enabled = (bool) ($block['is_enabled'] ?? true);
    $showTitle = (bool) ($block['show_title'] ?? true);
    $html = '<div class="homepage-preview-meta">';
    $html .= '<div class="homepage-preview-meta-group"><span class="homepage-preview-meta-label">Einstellungen:</span>';
    $html .= '<span class="badge text-bg-secondary">' . $e($typeLabels[$type] ?? $type) . '</span>';
    $html .= '<span class="badge text-bg-secondary">' . $e($spanLabels[$span] ?? $span) . '</span>';
    $html .= '<span class="badge ' . ($enabled ? 'text-bg-success' : 'text-bg-secondary') . '">' . ($enabled ? 'Aktiv' : 'Inaktiv') . '</span>';
    $html .= '<span class="badge ' . ($showTitle ? 'text-bg-secondary' : 'text-bg-warning') . '">' . ($showTitle ? 'Titel sichtbar' : 'Titel ausgeblendet') . '</span>';
    $html .= '</div>';
    $html .= '<div class="homepage-preview-meta-group"><span class="homepage-preview-meta-label">Sichtbar für:</span>';
    foreach ($visibilityLabels as $field => $label) {
        $visible = (bool) ($block[$field] ?? false);
        $html .= '<span class="badge ' . ($visible ? 'text-bg-primary' : 'text-bg-secondary') . '">' . $e($label) . '</span>';
    }
    $html .= '</div></div>';
    return $html;
};
$renderPreviewBlock = static function (array $block, bool $showMeta = true) use ($e, $columnClass, $renderButtons, $renderMetaBar): string {
    $title = (string) ($block['title'] ?? '');
    $type = (string) ($block['type'] ?? '');
    $span = (string) ($block['column_span'] ?? 'full');
    $enabled = (bool) ($block['is_enabled'] ?? true);
    $showTitle = (bool) ($block['show_title'] ?? true);
    $buttonLayout = (string) ($block['button_layout'] ?? 'below_text');
    $html = '<div class="' . $e($columnClass($span)) . '">';
    $html .= '<div class="homepage-preview-block-shell' . (!$enabled ? ' is-disabled' : '') . '">';
    if ($showMeta) {
        $html .= $renderMetaBar($block);
    }

    if ($type === 'module_list') {
        $html .= '<section class="homepage-module-list-section homepage-block homepage-block-' . $e($type) . '">';
        $content = (string) ($block['content_html'] ?? '');
        if ($showTitle || $content !== '') {
            $html .= '<div class="homepage-module-list-header">';
            if ($showTitle) {
                $html .= '<h3 class="h6 text-uppercase text-body-secondary mb-0">' . $e($title) . '</h3>';
            }
            if ($content !== '') {
                $html .= '<div class="mn-markdown homepage-markdown small">' . $content . '</div>';
            }
            $html .= '</div>';
        }
        $modules = is_array($block['modules'] ?? null) ? $block['modules'] : [];
        if ($modules === []) {
            $html .= '<p class="text-body-secondary mb-0">Aktuell sind keine passenden Module aktiv.</p>';
        } else {
            $html .= '<div class="row g-3">';
            foreach ($modules as $module) {
                if (!is_array($module)) {
                    continue;
                }
                $html .= '<div class="col-12 col-md-6 col-xl-4"><div class="card shadow-sm border-0 app-card h-100 homepage-module-card"><div class="card-body p-3 d-flex flex-column">';
                $html .= '<h4 class="h6 mb-2">' . $e((string) ($module['name'] ?? 'Modul')) . '</h4>';
                $description = trim((string) ($module['description'] ?? ''));
                $html .= '<p class="text-body-secondary small mb-3">' . $e($description !== '' ? $description : 'Keine Beschreibung hinterlegt.') . '</p>';
                $html .= '<div class="mt-auto"><a href="' . $e((string) ($module['url'] ?? '/')) . '" class="btn btn-primary btn-sm">Modul öffnen</a></div>';
                $html .= '</div></div></div>';
            }
            $html .= '</div>';
        }
        $html .= '</section></div></div>';
        return $html;
    }

    $inlineClass = $type === 'custom_content' && $buttonLayout === 'inline_right' ? ' homepage-block-inline-right' : '';
    $html .= '<section class="card shadow-sm border-0 app-card homepage-block homepage-block-' . $e($type) . $inlineClass . '">';
    $html .= '<div class="card-body p-4">';
    if ($showTitle) {
        $html .= '<h3 class="' . ($type === 'feature_list' ? 'h6 text-uppercase text-body-secondary' : 'h4') . ' mb-3">' . $e($title) . '</h3>';
    }

    $content = (string) ($block['content_html'] ?? '');

    if ($type === 'custom_content') {
        $buttons = is_array($block['buttons'] ?? null) ? $block['buttons'] : [];
        if ($buttonLayout === 'inline_right' && $buttons !== []) {
            $html .= '<div class="row g-3 align-items-center homepage-content-inline">';
            $html .= '<div class="col-12 col-lg"><div class="mn-markdown homepage-markdown">' . $content . '</div></div>';
            $html .= '<div class="col-12 col-lg-auto">' . $renderButtons($buttons, false) . '</div>';
            $html .= '</div>';
        } else {
            if ($content !== '') {
                $html .= '<div class="mn-markdown homepage-markdown">' . $content . '</div>';
            }
            $html .= $renderButtons($buttons);
        }
    } elseif ($type === 'feature_list') {
        if ($content !== '') {
            $html .= '<div class="mn-markdown homepage-markdown mb-3">' . $content . '</div>';
        }
        $items = is_array($block['items'] ?? null) ? $block['items'] : [];
        if ($items === []) {
            $html .= '<p class="text-body-secondary mb-0">Noch keine Feature-Items angelegt.</p>';
        } else {
            $html .= '<div class="homepage-feature-list">';
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $html .= '<article class="homepage-feature-item">';
                $html .= '<strong class="d-block small mb-1">' . $e((string) ($item['title'] ?? '')) . '</strong>';
                $html .= '<div class="mn-markdown homepage-markdown small">' . (string) ($item['content_html'] ?? '') . '</div>';
                $html .= '</article>';
            }
            $html .= '</div>';
        }
    }

    $html .= '</div></section></div></div>';
    return $html;
};
?>
<div class="row g-4">
    <div class="col-12">
        <div class="card shadow-sm border-0 app-card">
            <div class="card-body p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                    <div>
                        <p class="text-uppercase text-body-secondary small fw-semibold mb-1">Admin</p>
                        <h1 class="h4 mb-2">Startseite</h1>
                        <p class="text-body-secondary mb-0">Verwalte die Inhalte der Root-Startseite, ohne View-Dateien zu bearbeiten.</p>
                    </div>
                    <div class="homepage-status-stack text-end">
                        <span class="badge <?= $isPublished ? 'text-bg-success' : 'text-bg-secondary' ?>" data-homepage-published-badge>
                            <?= $isPublished ? 'Konfigurierte Homepage veröffentlicht' : 'Standard-Startseite aktiv' ?>
                        </span>
                        <div class="small text-body-secondary mt-2"><span data-homepage-active-count><?= $e((string) count($enabledBlocks)) ?></span> aktive Blöcke</div>
                    </div>
                </div>
                <div class="alert alert-info small mt-3 mb-0" role="status">
                    Wenn das Modul deaktiviert oder nicht veröffentlicht ist, zeigt <code>/</code> weiter die Standard-Startseite. Bei Fehlern oder ungültigen Blöcken greift derselbe Fallback.
                </div>
                <div class="homepage-ajax-message mt-3 d-none" data-homepage-message role="status"></div>
                <?php if ($message !== ''): ?>
                    <div class="alert alert-success mt-3 mb-0" role="status"><?= $e($message) ?></div>
                <?php endif; ?>
                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger mt-3 mb-0" role="alert"><?= $e($error) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-4">
        <div class="card shadow-sm border-0 app-card h-100">
            <div class="card-body p-4">
                <h2 class="h6 mb-3">Veröffentlichung</h2>
                <p class="text-body-secondary small mb-3" data-homepage-published-help>
                    <?= $isPublished
                        ? 'Die Root-Startseite rendert sichtbare Homepage-Blöcke, sofern gültige Blöcke vorhanden sind.'
                        : 'Änderungen bleiben vorbereitet. Die öffentliche Root-Startseite nutzt weiterhin die Standard-Ansicht.' ?>
                </p>
                <form method="post" action="/admin/homepage/publish" data-homepage-ajax-form data-homepage-action="publish">
                    <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                    <input type="hidden" name="is_published" value="<?= $isPublished ? '0' : '1' ?>" data-homepage-published-input>
                    <button type="submit" class="btn <?= $isPublished ? 'btn-outline-secondary' : 'btn-primary' ?>" data-homepage-published-button>
                        <?= $isPublished ? 'Standard-Startseite verwenden' : 'Konfigurierte Startseite veröffentlichen' ?>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-8">
        <div class="card shadow-sm border-0 app-card h-100">
            <div class="card-body p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                    <div>
                        <h2 class="h6 mb-1"><?= $isEdit ? 'Block bearbeiten' : 'Block erstellen' ?></h2>
                        <p class="text-body-secondary small mb-0">Markdown ist erlaubt, HTML wird beim Rendern entfernt. Die gerenderte Vorschau findest du unten auf der Seite.</p>
                    </div>
                    <?php if ($isEdit): ?>
                        <a href="/admin/homepage" class="btn btn-outline-secondary btn-sm">Bearbeiten abbrechen</a>
                    <?php endif; ?>
                </div>

                <form method="post" action="<?= $isEdit ? '/admin/homepage/blocks/update' : '/admin/homepage/blocks/create' ?>" class="row g-3" data-homepage-block-form>
                    <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                    <?php if ($isEdit): ?>
                        <input type="hidden" name="block_id" value="<?= $e((string) ($formBlock['id'] ?? 0)) ?>">
                    <?php endif; ?>

                    <div class="col-12 col-md-4">
                        <label class="form-label" for="homepage_type">Typ</label>
                        <select class="form-select" id="homepage_type" name="type" data-homepage-block-type>
                            <?php foreach ($typeLabels as $value => $label): ?>
                                <option value="<?= $e($value) ?>"<?= (string) ($formBlock['type'] ?? '') === $value ? ' selected' : '' ?>><?= $e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-8">
                        <label class="form-label" for="homepage_title">Titel</label>
                        <input class="form-control" id="homepage_title" type="text" name="title" maxlength="190" required value="<?= $e((string) ($formBlock['title'] ?? '')) ?>">
                        <div class="form-check mt-2">
                            <input type="hidden" name="show_title" value="0">
                            <input class="form-check-input" id="homepage_show_title" type="checkbox" name="show_title" value="1"<?= (int) ($formBlock['show_title'] ?? 1) === 1 ? ' checked' : '' ?>>
                            <label class="form-check-label" for="homepage_show_title">Titel auf Startseite anzeigen</label>
                        </div>
                        <div class="form-text">Der Titel bleibt im Adminbereich zur Organisation sichtbar, kann aber auf der öffentlichen Startseite ausgeblendet werden.</div>
                    </div>

                    <div class="col-12" data-homepage-type-field="content">
                        <label class="form-label" for="homepage_content" data-homepage-content-label>Markdown-Inhalt oder Einleitung</label>
                        <textarea class="form-control" id="homepage_content" name="content_markdown" rows="7"><?= $e((string) ($formBlock['content_markdown'] ?? '')) ?></textarea>
                        <div class="form-text" data-homepage-content-help>Markdown-Inhalt für diesen Block. HTML wird aus Sicherheitsgründen entfernt.</div>
                        <div class="alert alert-info small mt-2 mb-0 d-none" data-homepage-module-list-hint>Die Modulliste wird automatisch aus den sichtbaren Modulen der jeweiligen Zielgruppe erzeugt.</div>
                        <div class="alert alert-info small mt-2 mb-0 d-none" data-homepage-feature-list-hint>Feature-Listen erzeugen mehrere kleine Infoboxen innerhalb eines Blocks.</div>
                        <details class="markdown-help mt-2" data-homepage-markdown-help>
                            <summary>Markdown-Beispiele anzeigen</summary>
                            <pre><code>**fett**
*kursiv*
`app/Config/version.php`

- Punkt 1
- Punkt 2

[Linktext](https://example.com)

## Überschrift</code></pre>
                        </details>
                    </div>

                    <div class="col-12" data-homepage-button-fieldset>
                        <label class="form-label d-block">Buttons</label>
                        <div class="row g-2 mb-2" data-homepage-button-layout-field>
                            <div class="col-12 col-md-5">
                                <label class="form-label small" for="homepage_button_layout">Button-Anordnung</label>
                                <select class="form-select" id="homepage_button_layout" name="button_layout">
                                    <?php foreach ($buttonLayoutLabels as $value => $label): ?>
                                        <option value="<?= $e($value) ?>"<?= (string) ($formBlock['button_layout'] ?? 'below_text') === $value ? ' selected' : '' ?>><?= $e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-7 d-flex align-items-end">
                                <div class="form-text">„Text links, Buttons rechts“ eignet sich für breite Hinweisblöcke. Mobil werden Buttons darunter angeordnet.</div>
                            </div>
                        </div>
                        <div class="homepage-repeater" data-homepage-buttons>
                            <?php $buttonRows = $formButtons !== [] ? $formButtons : [['label' => '', 'url' => '', 'variant' => 'primary']]; ?>
                            <?php foreach ($buttonRows as $index => $button): ?>
                                <div class="row g-2 align-items-end homepage-repeater-row" data-homepage-button-row>
                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">Text</label>
                                        <input class="form-control" type="text" name="buttons[<?= $e((string) $index) ?>][label]" maxlength="120" value="<?= $e((string) ($button['label'] ?? '')) ?>">
                                    </div>
                                    <div class="col-12 col-md-5">
                                        <label class="form-label small">URL</label>
                                        <input class="form-control" type="text" name="buttons[<?= $e((string) $index) ?>][url]" maxlength="255" placeholder="/login oder https://example.com" value="<?= $e((string) ($button['url'] ?? '')) ?>">
                                    </div>
                                    <div class="col-8 col-md-3">
                                        <label class="form-label small">Variante</label>
                                        <select class="form-select" name="buttons[<?= $e((string) $index) ?>][variant]">
                                            <?php foreach ($buttonVariants as $value => $label): ?>
                                                <option value="<?= $e($value) ?>"<?= (string) ($button['variant'] ?? 'primary') === $value ? ' selected' : '' ?>><?= $e($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-auto homepage-repeater-action">
                                        <button class="btn btn-outline-danger w-100" type="button" data-homepage-remove-row>Entfernen</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button class="btn btn-outline-secondary btn-sm mt-2" type="button" data-homepage-add-button>Button hinzufügen</button>
                    </div>

                    <div class="col-12 d-none" data-homepage-items-fieldset>
                        <label class="form-label d-block">Feature-Items</label>
                        <div class="homepage-repeater" data-homepage-items>
                            <?php $itemRows = $formItems !== [] ? $formItems : [['title' => '', 'content_markdown' => '']]; ?>
                            <?php foreach ($itemRows as $index => $item): ?>
                                <div class="row g-2 align-items-end homepage-repeater-row" data-homepage-item-row>
                                    <div class="col-12 col-md-4">
                                        <label class="form-label small">Titel</label>
                                        <input class="form-control" type="text" name="items[<?= $e((string) $index) ?>][title]" maxlength="190" value="<?= $e((string) ($item['title'] ?? '')) ?>">
                                    </div>
                                    <div class="col-12 col-md-7">
                                        <label class="form-label small">Text</label>
                                        <textarea class="form-control" name="items[<?= $e((string) $index) ?>][content_markdown]" rows="2"><?= $e((string) ($item['content_markdown'] ?? '')) ?></textarea>
                                    </div>
                                    <div class="col-12 col-md-auto homepage-repeater-action">
                                        <button class="btn btn-outline-danger w-100" type="button" data-homepage-remove-row>Entfernen</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button class="btn btn-outline-secondary btn-sm mt-2" type="button" data-homepage-add-item>Feature hinzufügen</button>
                    </div>

                    <div class="col-12 col-md-4">
                        <label class="form-label" for="homepage_span">Breite</label>
                        <select class="form-select" id="homepage_span" name="column_span">
                            <?php foreach ($spanLabels as $value => $label): ?>
                                <option value="<?= $e($value) ?>"<?= (string) ($formBlock['column_span'] ?? 'full') === $value ? ' selected' : '' ?>><?= $e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-8">
                        <label class="form-label d-block">Sichtbarkeit</label>
                        <div class="d-flex flex-wrap gap-3">
                            <?php foreach ($visibilityLabels as $field => $label): ?>
                                <div class="form-check">
                                    <input class="form-check-input" id="homepage_<?= $e($field) ?>" type="checkbox" name="<?= $e($field) ?>" value="1"<?= (int) ($formBlock[$field] ?? 0) === 1 ? ' checked' : '' ?>>
                                    <label class="form-check-label" for="homepage_<?= $e($field) ?>"><?= $e($label) ?></label>
                                </div>
                            <?php endforeach; ?>
                            <div class="form-check">
                                <input class="form-check-input" id="homepage_is_enabled" type="checkbox" name="is_enabled" value="1"<?= (int) ($formBlock['is_enabled'] ?? 0) === 1 ? ' checked' : '' ?>>
                                <label class="form-check-label" for="homepage_is_enabled">Aktiv</label>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Block speichern' : 'Block erstellen' ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card shadow-sm border-0 app-card">
            <div class="card-body p-4">
                <h2 class="h6 mb-3">Blöcke</h2>
                <?php if ($blocks === []): ?>
                    <div class="border rounded-2 p-4 text-body-secondary">Noch keine Blöcke angelegt. Die Standard-Startseite bleibt sichtbar.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Titel</th>
                                    <th>Typ</th>
                                    <th>Sichtbar</th>
                                    <th>Breite</th>
                                    <th>Sortierung</th>
                                    <th>Status</th>
                                    <th class="text-end">Aktionen</th>
                                </tr>
                            </thead>
                            <tbody data-homepage-block-list>
                                <?php foreach ($blocks as $index => $block): ?>
                                    <?php
                                    if (!is_array($block)) {
                                        continue;
                                    }
                                    $id = (int) ($block['id'] ?? 0);
                                    $type = (string) ($block['type'] ?? '');
                                    $span = (string) ($block['column_span'] ?? 'full');
                                    $enabled = (int) ($block['is_enabled'] ?? 0) === 1;
                                    ?>
                                    <tr data-homepage-block-row data-block-id="<?= $e((string) $id) ?>">
                                        <td>
                                            <div class="fw-medium"><?= $e((string) ($block['title'] ?? '')) ?></div>
                                            <div class="small text-body-secondary">ID <?= $e((string) $id) ?></div>
                                        </td>
                                        <td><span class="badge text-bg-secondary"><?= $e($typeLabels[$type] ?? $type) ?></span></td>
                                        <td>
                                            <div class="d-flex flex-wrap gap-1">
                                                <?php foreach ($visibilityLabels as $field => $label): ?>
                                                    <?php $visible = (int) ($block[$field] ?? 0) === 1; ?>
                                                    <form method="post" action="/admin/homepage/blocks/visibility" data-homepage-ajax-form data-homepage-action="visibility" class="d-inline">
                                                        <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                                        <input type="hidden" name="block_id" value="<?= $e((string) $id) ?>">
                                                        <input type="hidden" name="field" value="<?= $e($field) ?>">
                                                        <input type="hidden" name="visible" value="<?= $visible ? '0' : '1' ?>">
                                                        <button type="submit" class="badge border-0 <?= $visible ? 'text-bg-primary' : 'text-bg-secondary' ?>" data-homepage-visibility-badge data-field="<?= $e($field) ?>"><?= $e($label) ?></button>
                                                    </form>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                        <td><?= $e($spanLabels[$span] ?? $span) ?></td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <form method="post" action="/admin/homepage/blocks/move" data-homepage-ajax-form data-homepage-action="move">
                                                    <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                                    <input type="hidden" name="block_id" value="<?= $e((string) $id) ?>">
                                                    <input type="hidden" name="direction" value="up">
                                                    <button class="btn btn-outline-secondary btn-sm" type="submit"<?= $index === 0 ? ' disabled' : '' ?>>Hoch</button>
                                                </form>
                                                <form method="post" action="/admin/homepage/blocks/move" data-homepage-ajax-form data-homepage-action="move">
                                                    <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                                    <input type="hidden" name="block_id" value="<?= $e((string) $id) ?>">
                                                    <input type="hidden" name="direction" value="down">
                                                    <button class="btn btn-outline-secondary btn-sm" type="submit"<?= $index === count($blocks) - 1 ? ' disabled' : '' ?>>Runter</button>
                                                </form>
                                            </div>
                                        </td>
                                        <td><span class="badge <?= $enabled ? 'text-bg-success' : 'text-bg-secondary' ?>" data-homepage-status-badge><?= $enabled ? 'Aktiv' : 'Inaktiv' ?></span></td>
                                        <td class="text-end text-nowrap">
                                            <a class="btn btn-outline-secondary btn-sm" href="/admin/homepage?edit=<?= $e((string) $id) ?>">Bearbeiten</a>
                                            <form method="post" action="/admin/homepage/blocks/toggle" class="d-inline" data-homepage-ajax-form data-homepage-action="toggle">
                                                <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                                <input type="hidden" name="block_id" value="<?= $e((string) $id) ?>">
                                                <input type="hidden" name="is_enabled" value="<?= $enabled ? '0' : '1' ?>">
                                                <button class="btn btn-outline-secondary btn-sm" type="submit" data-homepage-toggle-button><?= $enabled ? 'Deaktivieren' : 'Aktivieren' ?></button>
                                            </form>
                                            <form method="post" action="/admin/homepage/blocks/delete" class="d-inline" data-homepage-ajax-form data-homepage-action="delete" onsubmit="return confirm('Homepage-Block wirklich löschen?');">
                                                <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                                <input type="hidden" name="block_id" value="<?= $e((string) $id) ?>">
                                                <button class="btn btn-outline-danger btn-sm" type="submit">Löschen</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12" id="homepage-preview" data-homepage-preview-fragment>
        <div class="card shadow-sm border-0 app-card homepage-preview-toolbar">
            <div class="card-body p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                    <div>
                        <h2 class="h6 mb-1">Gerenderte Vorschau</h2>
                        <p class="text-body-secondary small mb-0">Diese Vorschau ist nur im Adminbereich. Sie geht nicht live, solange die konfigurierte Startseite nicht veröffentlicht ist.</p>
                    </div>
                    <div class="btn-group btn-group-sm" role="group" aria-label="Homepage-Vorschau filtern">
                        <?php foreach (['all' => 'Alle', 'guest' => 'Gäste', 'user' => 'User', 'admin' => 'Admin'] as $mode => $label): ?>
                            <a class="btn <?= $previewMode === $mode ? 'btn-primary' : 'btn-outline-secondary' ?>" href="/admin/homepage?preview=<?= $e($mode) ?>#homepage-preview" data-homepage-preview-tab="<?= $e($mode) ?>" aria-pressed="<?= $previewMode === $mode ? 'true' : 'false' ?>"><?= $e($label) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="alert <?= $isPublished ? 'alert-success' : 'alert-info' ?> small mt-3 mb-0" role="status">
                    <?= $isPublished
                        ? 'Veröffentlicht: / nutzt gültige aktive Blöcke der jeweiligen Zielgruppe. Falls keine gültigen Blöcke sichtbar sind, greift der Standard-Fallback.'
                        : 'Nicht veröffentlicht: / zeigt weiterhin die Standard-Startseite.' ?>
                </div>
            </div>
        </div>

        <div class="homepage-preview-stage mt-3">
            <?php foreach (['all' => 'Alle', 'guest' => 'Gäste', 'user' => 'User', 'admin' => 'Admin'] as $mode => $label): ?>
                <?php $items = is_array($previewBlocks[$mode] ?? null) ? $previewBlocks[$mode] : []; ?>
                <div data-homepage-preview-panel="<?= $e($mode) ?>"<?= $previewMode === $mode ? '' : ' hidden' ?>>
                    <?php if ($items === []): ?>
                        <div class="homepage-preview-empty">
                            <?= $mode === 'all'
                                ? 'Keine renderbaren Blöcke vorhanden.'
                                : 'Für diese Zielgruppe sind aktuell keine aktiven sichtbaren Blöcke vorhanden. Die echte Startseite würde auf die Standard-Startseite zurückfallen.' ?>
                        </div>
                    <?php else: ?>
                        <div class="row g-3">
                            <?php foreach ($items as $block): ?>
                                <?= is_array($block) ? $renderPreviewBlock($block) : '' ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
