<?php
declare(strict_types=1);

$widgetCounts = is_array($widget_counts ?? null) ? $widget_counts : ['links' => 0, 'tasks' => 0, 'notes' => 0];
$widgets = is_array($widgets ?? null) ? $widgets : [];
$activeWidgets = is_array($active_widgets ?? null) ? $active_widgets : [];
$foldersByWidget = is_array($folders_by_widget ?? null) ? $folders_by_widget : [];
$linksByWidget = is_array($links_by_widget ?? null) ? $links_by_widget : [];
$tasksByWidget = is_array($tasks_by_widget ?? null) ? $tasks_by_widget : [];
$archivedTasksByWidget = is_array($archived_tasks_by_widget ?? null) ? $archived_tasks_by_widget : [];
$notesByWidget = is_array($notes_by_widget ?? null) ? $notes_by_widget : [];
$archivedNotesByWidget = is_array($archived_notes_by_widget ?? null) ? $archived_notes_by_widget : [];
$formState = is_array($form_state ?? null) ? $form_state : [];
$message = (string) ($message ?? '');
$error = (string) ($error ?? '');
$csrfToken = (string) ($csrf_token ?? '');
$dashboardNowUtc = (string) ($dashboard_now_utc ?? '');
$dashboardTimezoneName = (string) ($dashboard_timezone_name ?? 'UTC');
$dashboardAutoRefreshEnabled = (bool) ($dashboard_auto_refresh_enabled ?? true);
$dashboardAutoRefreshIntervalMinutes = (int) ($dashboard_auto_refresh_interval_minutes ?? 30);
$dashboardAutoRefreshIntervalMin = (int) ($dashboard_auto_refresh_interval_min ?? 5);
$dashboardAutoRefreshIntervalMax = (int) ($dashboard_auto_refresh_interval_max ?? 240);
$moduleFeatures = is_array($module_features ?? null) ? $module_features : [];
$profileSettingsAvailable = (bool) ($moduleFeatures['profile_settings_available'] ?? false);
$dashboardClockFallback = '--:--:--';
$dashboardLastUpdatedFallback = '--:--:--';
try {
    $dashboardTz = new DateTimeZone($dashboardTimezoneName);
    $dashboardLoadedAt = new DateTimeImmutable($dashboardNowUtc !== '' ? $dashboardNowUtc : 'now', new DateTimeZone('UTC'));
    $dashboardLoadedAt = $dashboardLoadedAt->setTimezone($dashboardTz);
    $dashboardClockFallback = $dashboardLoadedAt->format('H:i:s');
    $dashboardLastUpdatedFallback = $dashboardLoadedAt->format('H:i:s');
} catch (\Throwable) {
}

$widthToClass = static function (int $width): string {
    return $width === 12 ? 'col-12' : 'col-12 col-xl-6';
};

$dashboardWeekdays = [1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag', 6 => 'Samstag', 7 => 'Sonntag'];
$dashboardMonthOrdinals = [1 => '1.', 2 => '2.', 3 => '3.', 4 => '4.', 5 => '5. / letzter'];

$taskRepeatLabel = static function (array $task): string {
    $type = strtolower((string) ($task['repeat_type'] ?? 'none'));
    $time = (string) ($task['repeat_time'] ?? '');

    if ($type === 'none') {
        return 'Einmalig';
    }

    if ($type === 'daily') {
        return $time !== '' ? 'Täglich ' . substr($time, 0, 5) : 'Täglich';
    }

    if ($type === 'weekly') {
        $days = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];
        $weekday = (int) ($task['repeat_weekday'] ?? 1);
        $label = 'Wöchentlich ' . ($days[$weekday] ?? 'Mo');
        return $time !== '' ? $label . ' ' . substr($time, 0, 5) : $label;
    }

    if ($type === 'monthly') {
        $mode = (string) ($task['repeat_month_mode'] ?? 'first_day');
        $modeMap = [
            'first_day' => 'Monatlich (1. Tag)',
            'middle_day' => 'Monatlich (15. Tag)',
            'last_day' => 'Monatlich (letzter Tag)',
            'fixed_day' => 'Monatlich (fester Tag)',
            'ordinal_weekday' => 'Monatlich',
        ];
        $label = $modeMap[$mode] ?? 'Monatlich';
        if ($mode === 'fixed_day') {
            $label .= ' #' . (int) ($task['repeat_month_day'] ?? 1);
        } elseif ($mode === 'ordinal_weekday') {
            $days = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];
            $ordinal = max(1, min((int) ($task['repeat_month_ordinal'] ?? 1), 5));
            $weekday = max(1, min((int) ($task['repeat_month_weekday'] ?? 1), 7));
            $label .= ' (' . $ordinal . '. ' . ($days[$weekday] ?? 'Mo') . ')';
        }
        return $time !== '' ? $label . ' ' . substr($time, 0, 5) : $label;
    }

    return 'Einmalig';
};
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h1 class="h4 mb-0">Dashboard</h1>
</div>

<?php if ($message !== ''): ?>
    <div class="alert alert-success" role="alert"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<div id="dashboard-ajax-feedback" class="module-toggle-feedback small mb-2" aria-live="polite"></div>

<div class="row g-3 mb-4 dashboard-info-zone">
    <div class="col-12 col-md-6 col-xl-4">
        <div class="card shadow-sm border-0 app-card h-100 dashboard-info-card">
            <div class="card-body dashboard-info-card-body">
                <div class="text-body-secondary small mb-1">Aktuelle Zeit</div>
                <div
                    id="dashboard-clock"
                    class="h4 mb-1 dashboard-info-value"
                    data-server-utc="<?= htmlspecialchars($dashboardNowUtc, ENT_QUOTES, 'UTF-8') ?>"
                    data-timezone="<?= htmlspecialchars($dashboardTimezoneName, ENT_QUOTES, 'UTF-8') ?>"
                ><?= htmlspecialchars($dashboardClockFallback, ENT_QUOTES, 'UTF-8') ?></div>
                <div id="dashboard-timezone-row" class="small text-body-secondary">
                    Zeitzone:
                    <span id="dashboard-timezone-label"><?= htmlspecialchars($dashboardTimezoneName, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if ($profileSettingsAvailable): ?>
                        <a href="/profil/settings" class="inline-settings-link" title="Einstellungen anpassen" aria-label="Einstellungen anpassen">
                            <i class="bi bi-gear-fill inline-settings-link-icon" aria-hidden="true"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-4">
        <div
            id="dashboard-auto-refresh-config"
            class="card shadow-sm border-0 app-card h-100 dashboard-info-card"
            data-enabled="<?= $dashboardAutoRefreshEnabled ? '1' : '0' ?>"
            data-interval-minutes="<?= $dashboardAutoRefreshIntervalMinutes ?>"
            data-interval-min="<?= $dashboardAutoRefreshIntervalMin ?>"
            data-interval-max="<?= $dashboardAutoRefreshIntervalMax ?>"
        >
            <div class="card-body dashboard-info-card-body">
                <div class="d-flex align-items-start justify-content-between gap-2 mb-1">
                    <div class="text-body-secondary small">Auto-Aktualisierung</div>
                    <div class="form-check form-switch m-0">
                        <input
                            id="dashboard-auto-refresh-toggle"
                            class="form-check-input"
                            type="checkbox"
                            role="switch"
                            <?= $dashboardAutoRefreshEnabled ? 'checked' : '' ?>
                        >
                    </div>
                </div>
                <div id="dashboard-auto-refresh-status" class="small dashboard-info-value mb-1">
                    <span id="dashboard-auto-refresh-status-value">
                        <?= $dashboardAutoRefreshEnabled ? 'Aktiv' : 'Inaktiv' ?>
                        · Intervall <?= $dashboardAutoRefreshIntervalMinutes ?> Minuten
                    </span>
                    <?php if ($profileSettingsAvailable): ?>
                        <a href="/profil/settings" class="inline-settings-link" title="Einstellungen anpassen" aria-label="Einstellungen anpassen">
                            <i class="bi bi-gear-fill inline-settings-link-icon" aria-hidden="true"></i>
                        </a>
                    <?php endif; ?>
                </div>
                <div id="dashboard-auto-refresh-countdown" class="small text-body-secondary">
                    <?= $dashboardAutoRefreshEnabled ? 'Nächster Refresh wird berechnet ...' : 'Automatische Aktualisierung deaktiviert.' ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-4">
        <div class="card shadow-sm border-0 app-card h-100 dashboard-info-card">
            <div class="card-body dashboard-info-card-body">
                <div class="text-body-secondary small mb-1">Letzte Aktualisierung</div>
                <div class="h5 mb-1 dashboard-info-value">
                    <span id="dashboard-last-updated"><?= htmlspecialchars($dashboardLastUpdatedFallback, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="small text-body-secondary">Zeitpunkt des aktuell sichtbaren Dashboard-Standes.</div>
            </div>
        </div>
    </div>
</div>

<?php if (!$profileSettingsAvailable): ?>
    <div class="small text-body-secondary mb-4">
        Profil-Einstellungen sind aktuell nicht aktiv. Zeitzone und Auto-Aktualisierung nutzen die gespeicherten Werte oder Modulon-Defaults.
    </div>
<?php endif; ?>

<?php if ($activeWidgets === []): ?>
    <div class="card shadow-sm border-0 app-card">
        <div class="card-body p-4">
            <p class="mb-0 text-body-secondary">Keine aktiven Widgets konfiguriert.</p>
        </div>
    </div>
<?php else: ?>
    <div class="row g-4 dashboard-widget-grid" id="dashboard-widget-grid" data-reorder-url="/dashboard/widgets/reorder">
        <?php foreach ($activeWidgets as $widget): ?>
            <?php
            $widgetId = (int) ($widget['id'] ?? 0);
            $widgetType = strtolower((string) ($widget['widget_type'] ?? ''));
            $widgetTitle = (string) ($widget['title'] ?? 'Widget');
            $layoutWidth = (int) ($widget['layout_width'] ?? 12);
            $widgetClass = $widthToClass($layoutWidth);
            $formToggleLabel = match ($widgetType) {
                'links' => '+ Link anlegen',
                'tasks' => '+ Aufgabe anlegen',
                'notes' => '+ Notiz anlegen',
                default => '+ Neu',
            };
            $activeItemCount = match ($widgetType) {
                'tasks' => count(is_array($tasksByWidget[$widgetId] ?? null) ? $tasksByWidget[$widgetId] : []),
                'notes' => count(is_array($notesByWidget[$widgetId] ?? null) ? $notesByWidget[$widgetId] : []),
                default => null,
            };
            ?>
            <div
                id="widget-<?= $widgetId ?>"
                class="<?= htmlspecialchars($widgetClass, ENT_QUOTES, 'UTF-8') ?> dashboard-widget-shell"
                data-widget-id="<?= $widgetId ?>"
                data-widget-width="<?= $layoutWidth === 12 ? '12' : '6' ?>"
            >
                <section class="card shadow-sm border-0 app-card dashboard-widget-window">
                    <div class="dashboard-widget-titlebar" title="Widget verschieben">
                        <div class="d-flex align-items-center gap-2 flex-wrap min-w-0">
                            <button
                                type="button"
                                class="app-sort-handle dashboard-widget-grip"
                                aria-label="Widget verschieben"
                                title="Per Drag & Drop verschieben"
                            ><span class="app-sort-handle-dots" aria-hidden="true"></span></button>
                            <h2 class="h6 text-uppercase text-body-secondary mb-0 text-truncate js-widget-title"><?= htmlspecialchars($widgetTitle, ENT_QUOTES, 'UTF-8') ?></h2>
                            <span class="badge text-bg-secondary"><?= htmlspecialchars($widgetType, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php if ($activeItemCount !== null): ?>
                                <span
                                    class="badge text-bg-secondary dashboard-active-count-badge"
                                    data-dashboard-active-count="<?= htmlspecialchars($widgetType, ENT_QUOTES, 'UTF-8') ?>"
                                    data-widget-id="<?= $widgetId ?>"
                                ><span class="js-dashboard-active-count"><?= $activeItemCount ?></span> aktiv</span>
                            <?php endif; ?>
                        </div>
                        <div class="dashboard-widget-window-actions">
                            <button type="button" class="btn btn-sm btn-outline-secondary dashboard-window-btn js-widget-collapse" title="Einklappen" aria-label="Widget einklappen">
                                <i class="bi bi-chevron-up" aria-hidden="true"></i>
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-secondary dashboard-window-btn js-widget-width"
                                data-next-width="<?= $layoutWidth === 12 ? '6' : '12' ?>"
                                title="<?= $layoutWidth === 12 ? 'Auf halbe Breite wiederherstellen' : 'Auf ganze Zeile vergrößern' ?>"
                                aria-label="<?= $layoutWidth === 12 ? 'Auf halbe Breite wiederherstellen' : 'Auf ganze Zeile vergrößern' ?>"
                            >
                                <i class="bi <?= $layoutWidth === 12 ? 'bi-layout-sidebar-inset' : 'bi-arrows-fullscreen' ?>" aria-hidden="true"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger dashboard-window-btn js-widget-close" title="Widget schließen" aria-label="Widget schließen">
                                <i class="bi bi-x-lg" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-4 dashboard-widget-body">
                        <div class="d-flex align-items-center justify-content-end mb-3">
                            <?php if (in_array($widgetType, ['links', 'tasks', 'notes'], true)): ?>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary js-widget-form-toggle"
                                    data-target-id="widget-form-<?= $widgetId ?>"
                                    aria-expanded="false"
                                ><?= htmlspecialchars($formToggleLabel, ENT_QUOTES, 'UTF-8') ?></button>
                            <?php endif; ?>
                        </div>

                        <?php if ($widgetType === 'links'): ?>
                            <?php
                            $folders = is_array($foldersByWidget[$widgetId] ?? null) ? $foldersByWidget[$widgetId] : [];
                            $links = is_array($linksByWidget[$widgetId] ?? null) ? $linksByWidget[$widgetId] : [];
                            $widgetState = is_array($formState[(string) $widgetId] ?? null) ? $formState[(string) $widgetId] : [];
                            $stateUrl = (string) ($widgetState['url'] ?? '');
                            $stateTitle = (string) ($widgetState['title'] ?? '');
                            $stateFaviconRaw = (string) ($widgetState['favicon_url'] ?? '');
                            $stateFavicon = preg_match('#^https?://#i', $stateFaviconRaw) === 1 ? $stateFaviconRaw : '';
                            $stateFolder = (int) ($widgetState['folder_id'] ?? 0);
                            ?>

                            <div id="widget-form-<?= $widgetId ?>" class="js-widget-form-block mb-4">
                            <div class="border rounded-2 p-3 mb-3">
                                <h3 class="h6 text-uppercase text-body-secondary mb-2">Ordner anlegen</h3>
                                <form method="post" action="/dashboard/links/folders/create" class="row g-2">
                                    <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                    <input type="hidden" name="widget_id" value="<?= $widgetId ?>">
                                    <div class="col-12 col-md-8">
                                        <input class="form-control form-control-sm" type="text" name="folder_name" maxlength="120" placeholder="Neuer Ordnername" required>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary w-100">Ordner speichern</button>
                                    </div>
                                </form>
                            </div>

                            <div class="border rounded-2 p-3">
                                <h3 class="h6 text-uppercase text-body-secondary mb-2">Link erfassen</h3>
                                <form method="post" action="/dashboard/links/analyze" class="row g-2 mb-2 js-link-analyze-form">
                                    <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                    <input type="hidden" name="widget_id" value="<?= $widgetId ?>">
                                    <div class="col-12 col-md-8">
                                        <label class="form-label mb-1 small" for="analyze_url_<?= $widgetId ?>">URL</label>
                                        <input id="analyze_url_<?= $widgetId ?>" class="form-control form-control-sm" type="url" name="url" value="<?= htmlspecialchars($stateUrl, ENT_QUOTES, 'UTF-8') ?>" placeholder="https://example.org" required>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="form-label mb-1 small" for="analyze_folder_<?= $widgetId ?>">Ordner</label>
                                        <select id="analyze_folder_<?= $widgetId ?>" class="form-select form-select-sm" name="folder_id">
                                            <option value="0">Allgemein</option>
                                            <?php foreach ($folders as $folder): ?>
                                                <?php $folderId = (int) ($folder['id'] ?? 0); ?>
                                                <option value="<?= $folderId ?>"<?= $folderId === $stateFolder ? ' selected' : '' ?>><?= htmlspecialchars((string) ($folder['name'] ?? 'Ordner'), ENT_QUOTES, 'UTF-8') ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-sm btn-outline-primary">URL analysieren</button>
                                    </div>
                                </form>

                                <form method="post" action="/dashboard/links/save" enctype="multipart/form-data" class="row g-2">
                                    <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                    <input type="hidden" name="widget_id" value="<?= $widgetId ?>">
                                    <div class="col-12 col-md-8">
                                        <label class="form-label mb-1 small" for="save_url_<?= $widgetId ?>">URL</label>
                                        <input id="save_url_<?= $widgetId ?>" class="form-control form-control-sm" type="url" name="url" value="<?= htmlspecialchars($stateUrl, ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="form-label mb-1 small" for="save_folder_<?= $widgetId ?>">Ordner</label>
                                        <select id="save_folder_<?= $widgetId ?>" class="form-select form-select-sm" name="folder_id">
                                            <option value="0">Allgemein</option>
                                            <?php foreach ($folders as $folder): ?>
                                                <?php $folderId = (int) ($folder['id'] ?? 0); ?>
                                                <option value="<?= $folderId ?>"<?= $folderId === $stateFolder ? ' selected' : '' ?>><?= htmlspecialchars((string) ($folder['name'] ?? 'Ordner'), ENT_QUOTES, 'UTF-8') ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <label class="form-label mb-1 small" for="save_title_<?= $widgetId ?>">Titel</label>
                                        <input id="save_title_<?= $widgetId ?>" class="form-control form-control-sm" type="text" name="title" maxlength="180" value="<?= htmlspecialchars($stateTitle, ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <label class="form-label mb-1 small" for="save_favicon_<?= $widgetId ?>">Favicon-URL (optional)</label>
                                        <input id="save_favicon_<?= $widgetId ?>" class="form-control form-control-sm" type="url" name="favicon_url" value="<?= htmlspecialchars($stateFavicon, ENT_QUOTES, 'UTF-8') ?>" placeholder="https://example.org/favicon.ico">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label mb-1 small" for="favicon_file_<?= $widgetId ?>">Eigenes Favicon hochladen (optional)</label>
                                        <input id="favicon_file_<?= $widgetId ?>" class="form-control form-control-sm" type="file" name="favicon_file" accept=".png,.ico,.svg,.jpg,.jpeg,image/png,image/x-icon,image/vnd.microsoft.icon,image/svg+xml,image/jpeg">
                                        <div class="form-text">Erlaubt: PNG, ICO, SVG, JPG. Maximal 1 MB.</div>
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-sm btn-primary">Link speichern</button>
                                    </div>
                                </form>
                            </div>
                            </div>

                            <div class="vstack gap-2">
                                <?php if ($links === []): ?>
                                    <p class="small text-body-secondary mb-0">Noch keine Links gespeichert.</p>
                                <?php else: ?>
                                    <?php foreach ($links as $link): ?>
                                        <?php
                                        $linkId = (int) ($link['id'] ?? 0);
                                        $linkTitle = (string) ($link['title'] ?? 'Link');
                                        $linkUrl = (string) ($link['url'] ?? '#');
                                        $linkFavicon = trim((string) ($link['favicon_url'] ?? ''));
                                        if ($linkFavicon === '') {
                                            $linkFavicon = '/assets/img/favicon-placeholder.svg';
                                        }
                                        $linkFolderId = (int) ($link['folder_id'] ?? 0);
                                        $folderName = (string) ($link['folder_name'] ?? 'Allgemein');
                                        $editBlockId = 'link-edit-' . $widgetId . '-' . $linkId;
                                        ?>
                                        <div class="border rounded-2 p-2 dashboard-link-item">
                                            <div class="dashboard-link-row">
                                                <a
                                                    class="dashboard-link-anchor"
                                                    href="<?= htmlspecialchars($linkUrl, ENT_QUOTES, 'UTF-8') ?>"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    title="<?= htmlspecialchars($linkTitle, ENT_QUOTES, 'UTF-8') ?>"
                                                >
                                                    <img
                                                        class="dashboard-link-favicon"
                                                        src="<?= htmlspecialchars($linkFavicon, ENT_QUOTES, 'UTF-8') ?>"
                                                        alt=""
                                                        width="18"
                                                        height="18"
                                                        style="object-fit:contain"
                                                        onerror="this.onerror=null;this.src='/assets/img/favicon-placeholder.svg';"
                                                    >
                                                    <span class="dashboard-link-title"><?= htmlspecialchars($linkTitle, ENT_QUOTES, 'UTF-8') ?></span>
                                                </a>
                                                <span class="dashboard-link-folder small text-body-secondary" title="<?= htmlspecialchars($folderName, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($folderName, ENT_QUOTES, 'UTF-8') ?></span>
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-secondary js-link-edit-toggle"
                                                    data-target-id="<?= htmlspecialchars($editBlockId, ENT_QUOTES, 'UTF-8') ?>"
                                                    aria-expanded="false"
                                                >Bearbeiten</button>
                                                <form method="post" action="/dashboard/links/delete" class="m-0">
                                                    <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                                    <input type="hidden" name="link_id" value="<?= $linkId ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Link wirklich löschen?');">Löschen</button>
                                                </form>
                                            </div>

                                            <div id="<?= htmlspecialchars($editBlockId, ENT_QUOTES, 'UTF-8') ?>" class="mt-2 pt-2 border-top js-link-edit-block">
                                                <form method="post" action="/dashboard/links/update" enctype="multipart/form-data" class="row g-2">
                                                    <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                                    <input type="hidden" name="link_id" value="<?= $linkId ?>">
                                                    <div class="col-12 col-md-8">
                                                        <label class="form-label mb-1 small">URL</label>
                                                        <input class="form-control form-control-sm" type="url" name="url" value="<?= htmlspecialchars($linkUrl, ENT_QUOTES, 'UTF-8') ?>" required>
                                                    </div>
                                                    <div class="col-12 col-md-4">
                                                        <label class="form-label mb-1 small">Ordner</label>
                                                        <select class="form-select form-select-sm" name="folder_id">
                                                            <option value="0">Allgemein</option>
                                                            <?php foreach ($folders as $folder): ?>
                                                                <?php $folderId = (int) ($folder['id'] ?? 0); ?>
                                                                <option value="<?= $folderId ?>"<?= $folderId === $linkFolderId ? ' selected' : '' ?>><?= htmlspecialchars((string) ($folder['name'] ?? 'Ordner'), ENT_QUOTES, 'UTF-8') ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-12 col-md-6">
                                                        <label class="form-label mb-1 small">Titel</label>
                                                        <input class="form-control form-control-sm" type="text" name="title" maxlength="180" value="<?= htmlspecialchars($linkTitle, ENT_QUOTES, 'UTF-8') ?>" required>
                                                    </div>
                                                    <div class="col-12 col-md-6">
                                                        <label class="form-label mb-1 small">Favicon-URL (optional)</label>
                                                        <input class="form-control form-control-sm" type="url" name="favicon_url" value="<?= preg_match('#^https?://#i', $linkFavicon) === 1 ? htmlspecialchars($linkFavicon, ENT_QUOTES, 'UTF-8') : '' ?>" placeholder="https://example.org/favicon.ico">
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label mb-1 small">Eigenes Favicon hochladen (optional)</label>
                                                        <input class="form-control form-control-sm" type="file" name="favicon_file" accept=".png,.ico,.svg,.jpg,.jpeg,image/png,image/x-icon,image/vnd.microsoft.icon,image/svg+xml,image/jpeg">
                                                    </div>
                                                    <div class="col-12">
                                                        <div class="form-check">
                                                            <input id="remove_favicon_<?= $linkId ?>" class="form-check-input" type="checkbox" name="remove_favicon" value="1">
                                                            <label class="form-check-label small" for="remove_favicon_<?= $linkId ?>">Gespeichertes Favicon entfernen</label>
                                                        </div>
                                                    </div>
                                                    <div class="col-12 d-flex gap-2">
                                                        <button type="submit" class="btn btn-sm btn-primary">Änderungen speichern</button>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary js-link-edit-toggle" data-target-id="<?= htmlspecialchars($editBlockId, ENT_QUOTES, 'UTF-8') ?>" aria-expanded="false">Abbrechen</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($widgetType === 'tasks'): ?>
                            <?php $tasks = is_array($tasksByWidget[$widgetId] ?? null) ? $tasksByWidget[$widgetId] : []; ?>
                            <?php $archivedTasks = is_array($archivedTasksByWidget[$widgetId] ?? null) ? $archivedTasksByWidget[$widgetId] : []; ?>
                            <div id="widget-form-<?= $widgetId ?>" class="js-widget-form-block mb-3">
                            <div class="border rounded-2 p-3">
                                <h3 class="h6 text-uppercase text-body-secondary mb-2">Aufgabe erfassen</h3>
                                <form method="post" action="/dashboard/tasks/create" class="row g-2 js-task-form">
                                    <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                    <input type="hidden" name="widget_id" value="<?= $widgetId ?>">
                                    <div class="col-12">
                                        <label class="form-label mb-1 small" for="task_title_<?= $widgetId ?>">Titel</label>
                                        <input id="task_title_<?= $widgetId ?>" class="form-control form-control-sm" type="text" name="title" maxlength="255" required>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label mb-1 small" for="task_details_<?= $widgetId ?>">Beschreibung (optional)</label>
                                        <textarea id="task_details_<?= $widgetId ?>" class="form-control form-control-sm" name="details" rows="2" maxlength="4000"></textarea>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label mb-1 small" for="task_link_<?= $widgetId ?>">Link (optional)</label>
                                        <input id="task_link_<?= $widgetId ?>" class="form-control form-control-sm" type="url" name="link_url" placeholder="https://example.org">
                                    </div>
                                    <div class="col-12 col-md-5">
                                        <label class="form-label mb-1 small" for="task_repeat_type_<?= $widgetId ?>">Wiederholung</label>
                                        <select id="task_repeat_type_<?= $widgetId ?>" class="form-select form-select-sm js-repeat-type" name="repeat_type">
                                            <option value="none" selected>Einmalig</option>
                                            <option value="daily">Täglich</option>
                                            <option value="weekly">Wöchentlich</option>
                                            <option value="monthly">Monatlich</option>
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-3">
                                        <label class="form-label mb-1 small" for="task_repeat_time_<?= $widgetId ?>">Uhrzeit</label>
                                        <input id="task_repeat_time_<?= $widgetId ?>" class="form-control form-control-sm" type="time" name="repeat_time" value="09:00">
                                    </div>
                                    <div class="col-12 col-md-4 js-repeat-weekly d-none">
                                        <label class="form-label mb-1 small" for="task_repeat_weekday_<?= $widgetId ?>">Wochentag</label>
                                        <select id="task_repeat_weekday_<?= $widgetId ?>" class="form-select form-select-sm" name="repeat_weekday">
                                            <?php foreach ($dashboardWeekdays as $weekdayValue => $weekdayLabel): ?>
                                                <option value="<?= $weekdayValue ?>"><?= htmlspecialchars($weekdayLabel, ENT_QUOTES, 'UTF-8') ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-4 js-repeat-monthly d-none">
                                        <label class="form-label mb-1 small" for="task_repeat_month_mode_<?= $widgetId ?>">Monatsmodus</label>
                                        <select id="task_repeat_month_mode_<?= $widgetId ?>" class="form-select form-select-sm js-repeat-month-mode" name="repeat_month_mode">
                                            <option value="first_day">Erster Tag</option>
                                            <option value="middle_day">Mittlerer Tag (15)</option>
                                            <option value="last_day">Letzter Tag</option>
                                            <option value="fixed_day">Fester Tag</option>
                                            <option value="ordinal_weekday">N-ter Wochentag</option>
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-3 js-repeat-fixed-day d-none">
                                        <label class="form-label mb-1 small" for="task_repeat_month_day_<?= $widgetId ?>">Tag (1-31)</label>
                                        <input id="task_repeat_month_day_<?= $widgetId ?>" class="form-control form-control-sm" type="number" min="1" max="31" name="repeat_month_day" value="1">
                                    </div>
                                    <div class="col-12 col-md-3 js-repeat-month-ordinal d-none">
                                        <label class="form-label mb-1 small" for="task_repeat_month_ordinal_<?= $widgetId ?>">Position</label>
                                        <select id="task_repeat_month_ordinal_<?= $widgetId ?>" class="form-select form-select-sm" name="repeat_month_ordinal">
                                            <?php foreach ($dashboardMonthOrdinals as $ordinalValue => $ordinalLabel): ?>
                                                <option value="<?= $ordinalValue ?>"><?= htmlspecialchars($ordinalLabel, ENT_QUOTES, 'UTF-8') ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-4 js-repeat-month-weekday d-none">
                                        <label class="form-label mb-1 small" for="task_repeat_month_weekday_<?= $widgetId ?>">Wochentag im Monat</label>
                                        <select id="task_repeat_month_weekday_<?= $widgetId ?>" class="form-select form-select-sm" name="repeat_month_weekday">
                                            <?php foreach ($dashboardWeekdays as $weekdayValue => $weekdayLabel): ?>
                                                <option value="<?= $weekdayValue ?>"><?= htmlspecialchars($weekdayLabel, ENT_QUOTES, 'UTF-8') ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <div class="form-check">
                                            <input id="task_active_<?= $widgetId ?>" class="form-check-input" type="checkbox" name="is_active" value="1" checked>
                                            <label class="form-check-label small" for="task_active_<?= $widgetId ?>">Aktiv</label>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-sm btn-primary">Aufgabe speichern</button>
                                    </div>
                                </form>
                            </div>
                            </div>

                            <div class="vstack gap-2 js-dashboard-active-list" data-dashboard-list="tasks" data-widget-id="<?= $widgetId ?>">
                                <?php if ($tasks === []): ?>
                                    <p class="small text-body-secondary mb-0 js-dashboard-empty-state">Noch keine aktiven Aufgaben vorhanden.</p>
                                <?php else: ?>
                                    <?php foreach ($tasks as $task): ?>
                                        <?php
                                        $taskId = (int) ($task['id'] ?? 0);
                                        $isDone = (int) ($task['is_done'] ?? 0) === 1;
                                        $taskTitle = (string) ($task['title'] ?? 'Aufgabe');
                                        $taskDetails = (string) ($task['details'] ?? '');
                                        $taskLink = (string) ($task['link_url'] ?? '');
                                        $taskRepeatType = strtolower((string) ($task['repeat_type'] ?? 'none'));
                                        if (!in_array($taskRepeatType, ['none', 'daily', 'weekly', 'monthly'], true)) {
                                            $taskRepeatType = 'none';
                                        }
                                        $taskRepeatTime = (string) ($task['repeat_time'] ?? '');
                                        $taskRepeatTimeInput = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $taskRepeatTime) === 1
                                            ? substr($taskRepeatTime, 0, 5)
                                            : '09:00';
                                        $taskRepeatWeekday = (int) ($task['repeat_weekday'] ?? 1);
                                        if ($taskRepeatWeekday < 1 || $taskRepeatWeekday > 7) {
                                            $taskRepeatWeekday = 1;
                                        }
                                        $taskRepeatMonthMode = strtolower((string) ($task['repeat_month_mode'] ?? 'first_day'));
                                        if (!in_array($taskRepeatMonthMode, ['first_day', 'middle_day', 'last_day', 'fixed_day', 'ordinal_weekday'], true)) {
                                            $taskRepeatMonthMode = 'first_day';
                                        }
                                        $taskRepeatMonthDay = (int) ($task['repeat_month_day'] ?? 1);
                                        if ($taskRepeatMonthDay < 1 || $taskRepeatMonthDay > 31) {
                                            $taskRepeatMonthDay = 1;
                                        }
                                        $taskRepeatMonthOrdinal = (int) ($task['repeat_month_ordinal'] ?? 1);
                                        if ($taskRepeatMonthOrdinal < 1 || $taskRepeatMonthOrdinal > 5) {
                                            $taskRepeatMonthOrdinal = 1;
                                        }
                                        $taskRepeatMonthWeekday = (int) ($task['repeat_month_weekday'] ?? 1);
                                        if ($taskRepeatMonthWeekday < 1 || $taskRepeatMonthWeekday > 7) {
                                            $taskRepeatMonthWeekday = 1;
                                        }
                                        $taskIsActive = (int) ($task['is_active'] ?? 1) === 1;
                                        $taskEditBlockId = 'task-edit-' . $taskId;
                                        ?>
                                        <div class="border rounded-2 p-2 modulon-task-item<?= $isDone ? ' is-done' : '' ?>" data-dashboard-archive-item="task" data-task-item="<?= $taskId ?>">
                                            <div class="d-flex align-items-start gap-2">
                                                <input class="form-check-input mt-1 js-task-toggle" type="checkbox" data-task-id="<?= $taskId ?>"<?= $isDone ? ' checked' : '' ?>>
                                                <div class="flex-grow-1">
                                                    <div class="d-flex align-items-center gap-2 flex-wrap js-task-title-row">
                                                        <strong><?= htmlspecialchars($taskTitle, ENT_QUOTES, 'UTF-8') ?></strong>
                                                        <span class="badge text-bg-secondary"><?= htmlspecialchars($taskRepeatLabel($task), ENT_QUOTES, 'UTF-8') ?></span>
                                                        <?php if ($isDone): ?>
                                                            <span class="badge text-bg-success js-task-done-badge">Erledigt</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($taskDetails !== ''): ?>
                                                        <div class="small text-body-secondary mt-1"><?= nl2br(htmlspecialchars($taskDetails, ENT_QUOTES, 'UTF-8')) ?></div>
                                                    <?php endif; ?>
                                                    <?php if ($taskLink !== ''): ?>
                                                        <div class="mt-2">
                                                            <a
                                                                href="<?= htmlspecialchars($taskLink, ENT_QUOTES, 'UTF-8') ?>"
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                class="btn btn-sm btn-outline-secondary js-task-open-link"
                                                                data-task-id="<?= $taskId ?>"
                                                                data-task-url="<?= htmlspecialchars($taskLink, ENT_QUOTES, 'UTF-8') ?>"
                                                            >Link öffnen</a>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="d-flex align-items-center gap-2 flex-wrap mt-2">
                                                        <button
                                                            type="button"
                                                            class="btn btn-sm btn-outline-secondary js-task-edit-toggle"
                                                            data-target-id="<?= htmlspecialchars($taskEditBlockId, ENT_QUOTES, 'UTF-8') ?>"
                                                            aria-expanded="false"
                                                        >Bearbeiten</button>
                                                        <form method="post" action="/dashboard/tasks/delete" class="m-0" onsubmit="return window.confirm('Aufgabe wirklich löschen?');">
                                                            <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                                            <input type="hidden" name="task_id" value="<?= $taskId ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger">Löschen</button>
                                                        </form>
                                                        <form method="post" action="/dashboard/tasks/archive" class="m-0 js-dashboard-archive-form" data-dashboard-archive-kind="task" data-dashboard-archive-state="1">
                                                            <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                                            <input type="hidden" name="task_id" value="<?= $taskId ?>">
                                                            <input type="hidden" name="archived" value="1">
                                                            <button type="submit" class="btn btn-sm btn-outline-secondary">Archivieren</button>
                                                        </form>
                                                        <form method="post" action="/dashboard/tasks/archive" class="m-0 js-dashboard-archive-form d-none" data-dashboard-archive-kind="task" data-dashboard-archive-state="0">
                                                            <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                                            <input type="hidden" name="task_id" value="<?= $taskId ?>">
                                                            <input type="hidden" name="archived" value="0">
                                                            <button type="submit" class="btn btn-sm btn-outline-success">Wiederherstellen</button>
                                                        </form>
                                                    </div>

                                                    <div id="<?= htmlspecialchars($taskEditBlockId, ENT_QUOTES, 'UTF-8') ?>" class="border rounded-2 p-3 mt-3 js-task-edit-block">
                                                        <h3 class="h6 text-uppercase text-body-secondary mb-2">Aufgabe bearbeiten</h3>
                                                        <form method="post" action="/dashboard/tasks/update" class="row g-2 js-task-form">
                                                            <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                                            <input type="hidden" name="task_id" value="<?= $taskId ?>">
                                                            <div class="col-12">
                                                                <label class="form-label mb-1 small" for="task_edit_title_<?= $taskId ?>">Titel</label>
                                                                <input id="task_edit_title_<?= $taskId ?>" class="form-control form-control-sm" type="text" name="title" maxlength="255" value="<?= htmlspecialchars($taskTitle, ENT_QUOTES, 'UTF-8') ?>" required>
                                                            </div>
                                                            <div class="col-12">
                                                                <label class="form-label mb-1 small" for="task_edit_details_<?= $taskId ?>">Beschreibung (optional)</label>
                                                                <textarea id="task_edit_details_<?= $taskId ?>" class="form-control form-control-sm" name="details" rows="2" maxlength="4000"><?= htmlspecialchars($taskDetails, ENT_QUOTES, 'UTF-8') ?></textarea>
                                                            </div>
                                                            <div class="col-12">
                                                                <label class="form-label mb-1 small" for="task_edit_link_<?= $taskId ?>">Link (optional)</label>
                                                                <input id="task_edit_link_<?= $taskId ?>" class="form-control form-control-sm" type="url" name="link_url" placeholder="https://example.org" value="<?= htmlspecialchars($taskLink, ENT_QUOTES, 'UTF-8') ?>">
                                                            </div>
                                                            <div class="col-12 col-md-5">
                                                                <label class="form-label mb-1 small" for="task_edit_repeat_type_<?= $taskId ?>">Wiederholung</label>
                                                                <select id="task_edit_repeat_type_<?= $taskId ?>" class="form-select form-select-sm js-repeat-type" name="repeat_type">
                                                                    <option value="none"<?= $taskRepeatType === 'none' ? ' selected' : '' ?>>Einmalig</option>
                                                                    <option value="daily"<?= $taskRepeatType === 'daily' ? ' selected' : '' ?>>Täglich</option>
                                                                    <option value="weekly"<?= $taskRepeatType === 'weekly' ? ' selected' : '' ?>>Wöchentlich</option>
                                                                    <option value="monthly"<?= $taskRepeatType === 'monthly' ? ' selected' : '' ?>>Monatlich</option>
                                                                </select>
                                                            </div>
                                                            <div class="col-12 col-md-3">
                                                                <label class="form-label mb-1 small" for="task_edit_repeat_time_<?= $taskId ?>">Uhrzeit</label>
                                                                <input id="task_edit_repeat_time_<?= $taskId ?>" class="form-control form-control-sm" type="time" name="repeat_time" value="<?= htmlspecialchars($taskRepeatTimeInput, ENT_QUOTES, 'UTF-8') ?>">
                                                            </div>
                                                            <div class="col-12 col-md-4 js-repeat-weekly<?= $taskRepeatType === 'weekly' ? '' : ' d-none' ?>">
                                                                <label class="form-label mb-1 small" for="task_edit_repeat_weekday_<?= $taskId ?>">Wochentag</label>
                                                                <select id="task_edit_repeat_weekday_<?= $taskId ?>" class="form-select form-select-sm" name="repeat_weekday">
                                                                    <?php foreach ($dashboardWeekdays as $weekdayValue => $weekdayLabel): ?>
                                                                        <option value="<?= $weekdayValue ?>"<?= $taskRepeatWeekday === $weekdayValue ? ' selected' : '' ?>><?= htmlspecialchars($weekdayLabel, ENT_QUOTES, 'UTF-8') ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                            <div class="col-12 col-md-4 js-repeat-monthly<?= $taskRepeatType === 'monthly' ? '' : ' d-none' ?>">
                                                                <label class="form-label mb-1 small" for="task_edit_repeat_month_mode_<?= $taskId ?>">Monatsmodus</label>
                                                                <select id="task_edit_repeat_month_mode_<?= $taskId ?>" class="form-select form-select-sm js-repeat-month-mode" name="repeat_month_mode">
                                                                    <option value="first_day"<?= $taskRepeatMonthMode === 'first_day' ? ' selected' : '' ?>>Erster Tag</option>
                                                                    <option value="middle_day"<?= $taskRepeatMonthMode === 'middle_day' ? ' selected' : '' ?>>Mittlerer Tag (15)</option>
                                                                    <option value="last_day"<?= $taskRepeatMonthMode === 'last_day' ? ' selected' : '' ?>>Letzter Tag</option>
                                                                    <option value="fixed_day"<?= $taskRepeatMonthMode === 'fixed_day' ? ' selected' : '' ?>>Fester Tag</option>
                                                                    <option value="ordinal_weekday"<?= $taskRepeatMonthMode === 'ordinal_weekday' ? ' selected' : '' ?>>N-ter Wochentag</option>
                                                                </select>
                                                            </div>
                                                            <div class="col-12 col-md-3 js-repeat-fixed-day<?= $taskRepeatType === 'monthly' && $taskRepeatMonthMode === 'fixed_day' ? '' : ' d-none' ?>">
                                                                <label class="form-label mb-1 small" for="task_edit_repeat_month_day_<?= $taskId ?>">Tag (1-31)</label>
                                                                <input id="task_edit_repeat_month_day_<?= $taskId ?>" class="form-control form-control-sm" type="number" min="1" max="31" name="repeat_month_day" value="<?= $taskRepeatMonthDay ?>">
                                                            </div>
                                                            <div class="col-12 col-md-3 js-repeat-month-ordinal<?= $taskRepeatType === 'monthly' && $taskRepeatMonthMode === 'ordinal_weekday' ? '' : ' d-none' ?>">
                                                                <label class="form-label mb-1 small" for="task_edit_repeat_month_ordinal_<?= $taskId ?>">Position</label>
                                                                <select id="task_edit_repeat_month_ordinal_<?= $taskId ?>" class="form-select form-select-sm" name="repeat_month_ordinal">
                                                                    <?php foreach ($dashboardMonthOrdinals as $ordinalValue => $ordinalLabel): ?>
                                                                        <option value="<?= $ordinalValue ?>"<?= $taskRepeatMonthOrdinal === $ordinalValue ? ' selected' : '' ?>><?= htmlspecialchars($ordinalLabel, ENT_QUOTES, 'UTF-8') ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                            <div class="col-12 col-md-4 js-repeat-month-weekday<?= $taskRepeatType === 'monthly' && $taskRepeatMonthMode === 'ordinal_weekday' ? '' : ' d-none' ?>">
                                                                <label class="form-label mb-1 small" for="task_edit_repeat_month_weekday_<?= $taskId ?>">Wochentag im Monat</label>
                                                                <select id="task_edit_repeat_month_weekday_<?= $taskId ?>" class="form-select form-select-sm" name="repeat_month_weekday">
                                                                    <?php foreach ($dashboardWeekdays as $weekdayValue => $weekdayLabel): ?>
                                                                        <option value="<?= $weekdayValue ?>"<?= $taskRepeatMonthWeekday === $weekdayValue ? ' selected' : '' ?>><?= htmlspecialchars($weekdayLabel, ENT_QUOTES, 'UTF-8') ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                            <div class="col-12">
                                                                <div class="form-check">
                                                                    <input id="task_edit_active_<?= $taskId ?>" class="form-check-input" type="checkbox" name="is_active" value="1"<?= $taskIsActive ? ' checked' : '' ?>>
                                                                    <label class="form-check-label small" for="task_edit_active_<?= $taskId ?>">Aktiv</label>
                                                                </div>
                                                            </div>
                                                            <div class="col-12 d-flex gap-2">
                                                                <button type="submit" class="btn btn-sm btn-primary">Änderungen speichern</button>
                                                                <button
                                                                    type="button"
                                                                    class="btn btn-sm btn-outline-secondary js-task-edit-toggle"
                                                                    data-target-id="<?= htmlspecialchars($taskEditBlockId, ENT_QUOTES, 'UTF-8') ?>"
                                                                    aria-expanded="false"
                                                                >Abbrechen</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <details class="dashboard-archive-panel mt-3" data-dashboard-archive-panel="tasks" data-widget-id="<?= $widgetId ?>">
                                <summary class="dashboard-archive-summary">
                                    Archivierte Aufgaben anzeigen
                                    <span class="badge text-bg-secondary js-dashboard-archive-count"><?= count($archivedTasks) ?></span>
                                </summary>
                                <div class="vstack gap-2 mt-2 js-dashboard-archive-list" data-dashboard-list="tasks" data-widget-id="<?= $widgetId ?>">
                                    <?php if ($archivedTasks === []): ?>
                                        <p class="small text-body-secondary mb-0 js-dashboard-empty-state">Keine archivierten Aufgaben.</p>
                                    <?php else: ?>
                                        <?php foreach ($archivedTasks as $task): ?>
                                            <?php
                                            $taskId = (int) ($task['id'] ?? 0);
                                            $isDone = (int) ($task['is_done'] ?? 0) === 1;
                                            $taskTitle = (string) ($task['title'] ?? 'Aufgabe');
                                            ?>
                                            <div class="border rounded-2 p-2 modulon-task-item is-archived<?= $isDone ? ' is-done' : '' ?>" data-dashboard-archive-item="task" data-task-item="<?= $taskId ?>">
                                                <div class="d-flex align-items-start justify-content-between gap-2">
                                                    <div class="min-w-0">
                                                        <div class="d-flex align-items-center gap-2 flex-wrap js-task-title-row">
                                                            <strong><?= htmlspecialchars($taskTitle, ENT_QUOTES, 'UTF-8') ?></strong>
                                                            <span class="badge text-bg-secondary"><?= htmlspecialchars($taskRepeatLabel($task), ENT_QUOTES, 'UTF-8') ?></span>
                                                            <?php if ($isDone): ?>
                                                                <span class="badge text-bg-success js-task-done-badge">Erledigt</span>
                                                            <?php endif; ?>
                                                            <span class="badge text-bg-dark">Archiv</span>
                                                        </div>
                                                    </div>
                                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                                        <form method="post" action="/dashboard/tasks/archive" class="m-0 js-dashboard-archive-form d-none" data-dashboard-archive-kind="task" data-dashboard-archive-state="1">
                                                            <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                                            <input type="hidden" name="task_id" value="<?= $taskId ?>">
                                                            <input type="hidden" name="archived" value="1">
                                                            <button type="submit" class="btn btn-sm btn-outline-secondary">Archivieren</button>
                                                        </form>
                                                        <form method="post" action="/dashboard/tasks/archive" class="m-0 js-dashboard-archive-form" data-dashboard-archive-kind="task" data-dashboard-archive-state="0">
                                                            <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                                            <input type="hidden" name="task_id" value="<?= $taskId ?>">
                                                            <input type="hidden" name="archived" value="0">
                                                            <button type="submit" class="btn btn-sm btn-outline-success">Wiederherstellen</button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </details>
                        <?php elseif ($widgetType === 'notes'): ?>
                            <?php $notes = is_array($notesByWidget[$widgetId] ?? null) ? $notesByWidget[$widgetId] : []; ?>
                            <?php $archivedNotes = is_array($archivedNotesByWidget[$widgetId] ?? null) ? $archivedNotesByWidget[$widgetId] : []; ?>
                            <div id="widget-form-<?= $widgetId ?>" class="js-widget-form-block mb-3">
                            <div class="border rounded-2 p-3">
                                <h3 class="h6 text-uppercase text-body-secondary mb-2">Neue Notiz</h3>
                                <form method="post" action="/dashboard/notes/create" class="row g-2">
                                    <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                    <input type="hidden" name="widget_id" value="<?= $widgetId ?>">
                                    <input type="hidden" name="textarea_height" value="" class="js-dashboard-note-height">
                                    <div class="col-12">
                                        <label class="form-label mb-1 small" for="note_title_<?= $widgetId ?>">Titel (optional)</label>
                                        <input id="note_title_<?= $widgetId ?>" class="form-control form-control-sm" type="text" name="title" maxlength="180">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label mb-1 small" for="note_content_<?= $widgetId ?>">Inhalt</label>
                                        <textarea id="note_content_<?= $widgetId ?>" class="form-control form-control-sm js-dashboard-note-textarea" name="content" rows="8" maxlength="20000" data-note-textarea-key="new-<?= $widgetId ?>" required></textarea>
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-sm btn-primary">Notiz speichern</button>
                                    </div>
                                </form>
                            </div>
                            </div>

                            <div class="vstack gap-2 js-dashboard-active-list" data-dashboard-list="notes" data-widget-id="<?= $widgetId ?>">
                                <?php if ($notes === []): ?>
                                    <p class="small text-body-secondary mb-0 js-dashboard-empty-state">Noch keine aktiven Notizen vorhanden.</p>
                                <?php else: ?>
                                    <?php foreach ($notes as $note): ?>
                                        <?php
                                        $noteId = (int) ($note['id'] ?? 0);
                                        $noteTitle = (string) ($note['title'] ?? '');
                                        $noteContent = (string) ($note['content'] ?? '');
                                        $noteTextareaHeight = max(0, (int) ($note['textarea_height'] ?? 0));
                                        $noteTextareaStyle = $noteTextareaHeight > 0 ? ' style="height: ' . $noteTextareaHeight . 'px;"' : '';
                                        ?>
                                        <div class="border rounded-2 p-2 dashboard-note-item" data-dashboard-archive-item="note" data-note-item="<?= $noteId ?>">
                                            <form method="post" action="/dashboard/notes/update" class="row g-2">
                                                <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                                <input type="hidden" name="note_id" value="<?= $noteId ?>">
                                                <input type="hidden" name="textarea_height" value="<?= $noteTextareaHeight > 0 ? $noteTextareaHeight : '' ?>" class="js-dashboard-note-height">
                                                <div class="col-12">
                                                    <input class="form-control form-control-sm" type="text" name="title" maxlength="180" value="<?= htmlspecialchars($noteTitle, ENT_QUOTES, 'UTF-8') ?>" placeholder="Titel (optional)">
                                                </div>
                                                <div class="col-12">
                                                    <textarea class="form-control form-control-sm js-dashboard-note-textarea" name="content" rows="6" maxlength="20000" data-note-textarea-key="<?= $noteId ?>" required<?= $noteTextareaStyle ?>><?= htmlspecialchars($noteContent, ENT_QUOTES, 'UTF-8') ?></textarea>
                                                </div>
                                                <div class="col-12 d-flex gap-2">
                                                    <button type="submit" class="btn btn-sm btn-outline-primary">Speichern</button>
                                                    <button formaction="/dashboard/notes/archive" formmethod="post" name="archived" value="1" type="submit" class="btn btn-sm btn-outline-secondary js-dashboard-note-archive-submit">Archivieren</button>
                                                    <button formaction="/dashboard/notes/delete" formmethod="post" type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Notiz wirklich löschen?');">Löschen</button>
                                                </div>
                                            </form>
                                            <form method="post" action="/dashboard/notes/archive" class="m-0 js-dashboard-archive-form d-none" data-dashboard-archive-kind="note" data-dashboard-archive-state="1">
                                                <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                                <input type="hidden" name="note_id" value="<?= $noteId ?>">
                                                <input type="hidden" name="archived" value="1">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary">Archivieren</button>
                                            </form>
                                            <form method="post" action="/dashboard/notes/archive" class="m-0 js-dashboard-archive-form d-none" data-dashboard-archive-kind="note" data-dashboard-archive-state="0">
                                                <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                                <input type="hidden" name="note_id" value="<?= $noteId ?>">
                                                <input type="hidden" name="archived" value="0">
                                                <button type="submit" class="btn btn-sm btn-outline-success">Wiederherstellen</button>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <details class="dashboard-archive-panel mt-3" data-dashboard-archive-panel="notes" data-widget-id="<?= $widgetId ?>">
                                <summary class="dashboard-archive-summary">
                                    Archivierte Notizen anzeigen
                                    <span class="badge text-bg-secondary js-dashboard-archive-count"><?= count($archivedNotes) ?></span>
                                </summary>
                                <div class="vstack gap-2 mt-2 js-dashboard-archive-list" data-dashboard-list="notes" data-widget-id="<?= $widgetId ?>">
                                    <?php if ($archivedNotes === []): ?>
                                        <p class="small text-body-secondary mb-0 js-dashboard-empty-state">Keine archivierten Notizen.</p>
                                    <?php else: ?>
                                        <?php foreach ($archivedNotes as $note): ?>
                                            <?php
                                            $noteId = (int) ($note['id'] ?? 0);
                                            $noteTitle = (string) ($note['title'] ?? '');
                                            $noteContent = (string) ($note['content'] ?? '');
                                            ?>
                                            <div class="border rounded-2 p-2 dashboard-note-item is-archived" data-dashboard-archive-item="note" data-note-item="<?= $noteId ?>">
                                                <div class="d-flex align-items-start justify-content-between gap-2">
                                                    <div class="min-w-0">
                                                        <?php if ($noteTitle !== ''): ?>
                                                            <strong><?= htmlspecialchars($noteTitle, ENT_QUOTES, 'UTF-8') ?></strong>
                                                        <?php endif; ?>
                                                        <div class="small text-body-secondary"><?= nl2br(htmlspecialchars(mb_substr($noteContent, 0, 240), ENT_QUOTES, 'UTF-8')) ?><?= mb_strlen($noteContent) > 240 ? ' ...' : '' ?></div>
                                                    </div>
                                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                                        <form method="post" action="/dashboard/notes/archive" class="m-0 js-dashboard-archive-form d-none" data-dashboard-archive-kind="note" data-dashboard-archive-state="1">
                                                            <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                                            <input type="hidden" name="note_id" value="<?= $noteId ?>">
                                                            <input type="hidden" name="archived" value="1">
                                                            <button type="submit" class="btn btn-sm btn-outline-secondary">Archivieren</button>
                                                        </form>
                                                        <form method="post" action="/dashboard/notes/archive" class="m-0 js-dashboard-archive-form" data-dashboard-archive-kind="note" data-dashboard-archive-state="0">
                                                            <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                                            <input type="hidden" name="note_id" value="<?= $noteId ?>">
                                                            <input type="hidden" name="archived" value="0">
                                                            <button type="submit" class="btn btn-sm btn-outline-success">Wiederherstellen</button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </details>
                        <?php else: ?>
                            <p class="text-body-secondary mb-0">Dieser Widget-Typ ist in diesem Schritt noch nicht implementiert.</p>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card shadow-sm border-0 app-card dashboard-widget-table-card overflow-hidden mt-4">
    <div class="card-body p-4 border-bottom">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
            <div>
                <h2 class="h5 mb-1">Widget-Übersicht</h2>
                <p class="small text-body-secondary mb-0">Geschlossene Widgets bleiben erhalten und können hier wiederhergestellt werden. Löschen entfernt Widget und Inhalte endgültig.</p>
            </div>
            <form method="post" action="/dashboard/widgets/create" class="row g-2 align-items-end dashboard-widget-create-form">
                <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                <div class="col-12 col-md">
                    <label class="form-label small mb-1" for="dashboard_new_widget_type">Typ</label>
                    <select id="dashboard_new_widget_type" class="form-select form-select-sm" name="widget_type">
                        <option value="links">Links</option>
                        <option value="tasks">Aufgaben</option>
                        <option value="notes">Notizen</option>
                    </select>
                </div>
                <div class="col-12 col-md">
                    <label class="form-label small mb-1" for="dashboard_new_widget_title">Titel</label>
                    <input id="dashboard_new_widget_title" class="form-control form-control-sm" type="text" name="title" maxlength="120" placeholder="Optionaler Titel">
                </div>
                <div class="col-12 col-md">
                    <label class="form-label small mb-1" for="dashboard_new_widget_width">Breite</label>
                    <select id="dashboard_new_widget_width" class="form-select form-select-sm" name="layout_width">
                        <option value="6">Halbe Zeile</option>
                        <option value="12">Ganze Zeile</option>
                    </select>
                </div>
                <div class="col-12 col-md-auto">
                    <button type="submit" class="btn btn-sm btn-primary w-100">Widget hinzufügen</button>
                </div>
            </form>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 app-table">
                <thead>
                    <tr>
                        <th class="ps-4">Widget</th>
                        <th>Typ</th>
                        <th>Breite</th>
                        <th>Sortierung</th>
                        <th>Status</th>
                        <th class="pe-4 text-end">Aktionen</th>
                    </tr>
                </thead>
                <tbody id="dashboard-widget-overview-body">
                    <?php if ($widgets === []): ?>
                        <tr>
                            <td colspan="6" class="ps-4 text-body-secondary">Noch keine Widget-Instanzen vorhanden.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($widgets as $widget): ?>
                            <?php
                            $widgetId = (int) ($widget['id'] ?? 0);
                            $title = (string) ($widget['title'] ?? '');
                            $type = (string) ($widget['widget_type'] ?? '');
                            $layoutWidth = (int) ($widget['layout_width'] ?? 6);
                            $sortOrder = (int) ($widget['sort_order'] ?? 0);
                            $isActive = (int) ($widget['is_active'] ?? 0) === 1;
                            ?>
                            <tr data-widget-row-id="<?= $widgetId ?>" data-widget-row-active="<?= $isActive ? '1' : '0' ?>">
                                <td class="ps-4">
                                    <form method="post" action="/dashboard/widgets/update" class="dashboard-widget-title-form">
                                        <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                        <input type="hidden" name="widget_id" value="<?= $widgetId ?>">
                                        <label class="visually-hidden" for="dashboard_widget_title_<?= $widgetId ?>">Widget-Titel</label>
                                        <input
                                            id="dashboard_widget_title_<?= $widgetId ?>"
                                            class="form-control form-control-sm"
                                            type="text"
                                            name="title"
                                            value="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>"
                                            maxlength="120"
                                            required
                                        >
                                        <button type="submit" class="btn btn-sm btn-outline-primary">Speichern</button>
                                    </form>
                                </td>
                                <td><span class="badge text-bg-secondary"><?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td class="js-widget-row-width"><?= $layoutWidth === 12 ? 'Ganze Zeile' : 'Halbe Zeile' ?></td>
                                <td class="js-widget-row-sort"><?= $sortOrder ?></td>
                                <td class="js-widget-row-status"><?= $isActive ? '<span class="badge text-bg-success">sichtbar</span>' : '<span class="badge text-bg-secondary">geschlossen</span>' ?></td>
                                <td class="pe-4 text-end js-widget-row-actions">
                                    <div class="d-inline-flex flex-wrap justify-content-end gap-2">
                                        <?php if (!$isActive): ?>
                                            <form method="post" action="/dashboard/widgets/update" class="m-0">
                                                <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                                <input type="hidden" name="widget_id" value="<?= $widgetId ?>">
                                                <input type="hidden" name="is_active" value="1">
                                                <button type="submit" class="btn btn-sm btn-outline-primary">Wiederherstellen</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post" action="/dashboard/widgets/delete" class="m-0" onsubmit="return confirm('Widget endgültig löschen? Alle Inhalte dieses Widgets werden entfernt.');">
                                            <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                            <input type="hidden" name="widget_id" value="<?= $widgetId ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Löschen</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="row g-3 mt-2">
    <div class="col-12 col-md-4">
        <div class="card shadow-sm border-0 app-card h-100">
            <div class="card-body">
                <div class="text-body-secondary small mb-1">Links-Widgets</div>
                <div class="h4 mb-0"><?= (int) ($widgetCounts['links'] ?? 0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card shadow-sm border-0 app-card h-100">
            <div class="card-body">
                <div class="text-body-secondary small mb-1">Aufgaben-Widgets</div>
                <div class="h4 mb-0"><?= (int) ($widgetCounts['tasks'] ?? 0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card shadow-sm border-0 app-card h-100">
            <div class="card-body">
                <div class="text-body-secondary small mb-1">Notizen-Widgets</div>
                <div class="h4 mb-0"><?= (int) ($widgetCounts['notes'] ?? 0) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="task-complete-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="h6 mb-0">Aufgabe als erledigt markieren?</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Soll diese Aufgabe nach dem Öffnen des Links als erledigt markiert werden?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Nein, offen lassen</button>
                <button type="button" class="btn btn-sm btn-primary" id="task-complete-confirm">Ja, als erledigt markieren</button>
            </div>
        </div>
    </div>
</div>

<script>
(() => {
    if (window.__modulonDashboardUiInit === true) {
        return;
    }
    window.__modulonDashboardUiInit = true;

    const feedback = document.getElementById('dashboard-ajax-feedback');
    const setFeedback = (text, isError = false) => {
        if (!feedback) return;
        feedback.textContent = text;
        feedback.classList.toggle('is-error', isError);
        if (text !== '') {
            window.setTimeout(() => {
                if (feedback.textContent === text) {
                    feedback.textContent = '';
                    feedback.classList.remove('is-error');
                }
            }, 2600);
        }
    };
    let pauseDashboardAutoRefresh = () => {};
    let resumeDashboardAutoRefresh = () => {};
    const csrfHeaders = {'X-CSRF-Token': <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>};

    const noteHeightInputFor = (textarea) => textarea.closest('form')?.querySelector('.js-dashboard-note-height');
    const syncNoteTextareaHeight = (textarea) => {
        const input = noteHeightInputFor(textarea);
        if (input instanceof HTMLInputElement) {
            input.value = String(Math.round(textarea.getBoundingClientRect().height));
        }
    };
    const applyStoredNoteTextareaHeight = (textarea) => {
        if (!(textarea instanceof HTMLTextAreaElement)) {
            return;
        }
        const input = noteHeightInputFor(textarea);
        const storedHeight = input instanceof HTMLInputElement ? Number(input.value || 0) : 0;
        if (storedHeight > 0) {
            textarea.style.height = `${storedHeight}px`;
        }
        syncNoteTextareaHeight(textarea);
    };
    document.querySelectorAll('.js-dashboard-note-textarea').forEach((textarea) => {
        if (!(textarea instanceof HTMLTextAreaElement)) {
            return;
        }
        applyStoredNoteTextareaHeight(textarea);
        textarea.addEventListener('change', () => syncNoteTextareaHeight(textarea));
        textarea.addEventListener('mouseup', () => syncNoteTextareaHeight(textarea));
        textarea.closest('form')?.addEventListener('submit', () => syncNoteTextareaHeight(textarea));
    });

    const widgetGrid = document.getElementById('dashboard-widget-grid');
    const widgetUpdateUrl = '/dashboard/widgets/update';
    const widgetCollapsedKeyPrefix = 'modulon.dashboard.widget.collapsed.';

    const updateWidgetShellWidth = (shell, width) => {
        shell.dataset.widgetWidth = String(width);
        shell.classList.toggle('col-xl-6', Number(width) !== 12);
        shell.classList.toggle('col-12', true);
        const row = document.querySelector(`[data-widget-row-id="${shell.dataset.widgetId || ''}"]`);
        const widthCell = row?.querySelector('.js-widget-row-width');
        if (widthCell) {
            widthCell.textContent = Number(width) === 12 ? 'Ganze Zeile' : 'Halbe Zeile';
        }
        const button = shell.querySelector('.js-widget-width');
        if (button instanceof HTMLButtonElement) {
            const nextWidth = Number(width) === 12 ? 6 : 12;
            button.dataset.nextWidth = String(nextWidth);
            button.title = nextWidth === 12 ? 'Auf ganze Zeile vergrößern' : 'Auf halbe Breite wiederherstellen';
            button.setAttribute('aria-label', button.title);
            const icon = button.querySelector('i');
            if (icon) {
                icon.className = 'bi ' + (nextWidth === 12 ? 'bi-arrows-fullscreen' : 'bi-layout-sidebar-inset');
            }
        }
    };

    const setWidgetCollapsed = (shell, collapsed) => {
        shell.classList.toggle('is-widget-collapsed', collapsed);
        const button = shell.querySelector('.js-widget-collapse');
        if (button instanceof HTMLButtonElement) {
            button.title = collapsed ? 'Ausklappen' : 'Einklappen';
            button.setAttribute('aria-label', button.title);
            const icon = button.querySelector('i');
            if (icon) {
                icon.className = 'bi ' + (collapsed ? 'bi-chevron-down' : 'bi-chevron-up');
            }
        }
        const widgetId = String(shell.dataset.widgetId || '');
        if (widgetId !== '') {
            window.localStorage.setItem(widgetCollapsedKeyPrefix + widgetId, collapsed ? '1' : '0');
        }
    };

    const postWidgetUpdate = async (payload) => {
        const response = await fetch(widgetUpdateUrl, {
            method: 'POST',
            headers: {
                ...csrfHeaders,
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(payload),
        });
        const data = await response.json();
        if (!response.ok || !data.ok) {
            throw new Error(data.message || 'Widget konnte nicht aktualisiert werden.');
        }
        return data;
    };

    const persistWidgetOrder = async () => {
        if (!widgetGrid || !widgetGrid.dataset.reorderUrl) {
            return;
        }
        const widgetIds = Array.from(widgetGrid.querySelectorAll('.dashboard-widget-shell'))
            .map((shell) => Number(shell.dataset.widgetId || 0))
            .filter((id) => id > 0);
        if (widgetIds.length === 0) {
            return;
        }
        const response = await fetch(widgetGrid.dataset.reorderUrl, {
            method: 'POST',
            headers: {
                ...csrfHeaders,
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ widget_ids: widgetIds }),
        });
        const data = await response.json();
        if (!response.ok || !data.ok) {
            throw new Error(data.message || 'Widget-Reihenfolge konnte nicht gespeichert werden.');
        }
    };

    const updateWidgetRowStatus = (widgetId, active) => {
        const row = document.querySelector(`[data-widget-row-id="${widgetId}"]`);
        if (row instanceof HTMLElement) {
            row.dataset.widgetRowActive = active ? '1' : '0';
        }
        const status = row?.querySelector('.js-widget-row-status');
        if (status) {
            status.innerHTML = active
                ? '<span class="badge text-bg-success">sichtbar</span>'
                : '<span class="badge text-bg-secondary">geschlossen</span>';
        }
        const actions = row?.querySelector('.js-widget-row-actions > div');
        if (!active && actions && !actions.querySelector('form[data-widget-restore-form]')) {
            actions.insertAdjacentHTML('afterbegin', [
                '<form method="post" action="/dashboard/widgets/update" class="m-0" data-widget-restore-form>',
                '<?= \Modulon\Core\View::csrfField($csrfToken) ?>',
                '<input type="hidden" name="widget_id" value="' + String(widgetId) + '">',
                '<input type="hidden" name="is_active" value="1">',
                '<button type="submit" class="btn btn-sm btn-outline-primary">Wiederherstellen</button>',
                '</form>'
            ].join(''));
        }
    };

    const syncWidgetOverviewOrder = () => {
        if (!widgetGrid) return;
        const tbody = document.getElementById('dashboard-widget-overview-body');
        if (!(tbody instanceof HTMLTableSectionElement)) return;

        const rows = Array.from(tbody.querySelectorAll('tr[data-widget-row-id]'));
        const rowById = new Map(rows.map((row) => [String(row.dataset.widgetRowId || ''), row]));
        const activeIds = Array.from(widgetGrid.querySelectorAll('.dashboard-widget-shell'))
            .map((shell) => String(shell.dataset.widgetId || ''))
            .filter((id) => id !== '');
        const activeLookup = new Set(activeIds);
        const fragment = document.createDocumentFragment();

        activeIds.forEach((id, index) => {
            const row = rowById.get(id);
            if (!row) return;
            row.dataset.widgetRowActive = '1';
            const sortCell = row.querySelector('.js-widget-row-sort');
            if (sortCell) {
                sortCell.textContent = String((index + 1) * 10);
            }
            fragment.appendChild(row);
        });

        rows.forEach((row) => {
            const id = String(row.dataset.widgetRowId || '');
            if (!activeLookup.has(id)) {
                fragment.appendChild(row);
            }
        });

        tbody.appendChild(fragment);
    };

    document.querySelectorAll('.dashboard-widget-shell').forEach((shell) => {
        const widgetId = String(shell.dataset.widgetId || '');
        if (widgetId !== '' && window.localStorage.getItem(widgetCollapsedKeyPrefix + widgetId) === '1') {
            setWidgetCollapsed(shell, true);
        }
    });

    document.querySelectorAll('.js-widget-collapse').forEach((button) => {
        button.addEventListener('click', () => {
            const shell = button.closest('.dashboard-widget-shell');
            if (!(shell instanceof HTMLElement)) return;
            setWidgetCollapsed(shell, !shell.classList.contains('is-widget-collapsed'));
        });
    });

    document.querySelectorAll('.js-widget-width').forEach((button) => {
        button.addEventListener('click', async () => {
            const shell = button.closest('.dashboard-widget-shell');
            if (!(shell instanceof HTMLElement)) return;
            const widgetId = Number(shell.dataset.widgetId || 0);
            const nextWidth = Number(button.dataset.nextWidth || 6);
            if (widgetId <= 0) return;
            button.disabled = true;
            try {
                const data = await postWidgetUpdate({ widget_id: widgetId, layout_width: nextWidth });
                updateWidgetShellWidth(shell, Number(data.layout_width || nextWidth));
                setFeedback('Widget-Größe gespeichert.');
            } catch (error) {
                setFeedback(error instanceof Error ? error.message : 'Widget-Größe konnte nicht gespeichert werden.', true);
            } finally {
                button.disabled = false;
            }
        });
    });

    document.querySelectorAll('.js-widget-close').forEach((button) => {
        button.addEventListener('click', async () => {
            const shell = button.closest('.dashboard-widget-shell');
            if (!(shell instanceof HTMLElement)) return;
            const widgetId = Number(shell.dataset.widgetId || 0);
            if (widgetId <= 0) return;
            button.disabled = true;
            try {
                await postWidgetUpdate({ widget_id: widgetId, is_active: 0 });
                shell.remove();
                updateWidgetRowStatus(widgetId, false);
                syncWidgetOverviewOrder();
                setFeedback('Widget geschlossen. Es kann unten in der Übersicht wiederhergestellt werden.');
            } catch (error) {
                button.disabled = false;
                setFeedback(error instanceof Error ? error.message : 'Widget konnte nicht geschlossen werden.', true);
            }
        });
    });

    if (widgetGrid) {
        let draggedWidget = null;
        let dragStartX = 0;
        let dragStartY = 0;
        let dragStarted = false;

        const finishWidgetDrag = async () => {
            if (!draggedWidget) return;
            if (draggedWidget) {
                draggedWidget.classList.remove('is-dragging');
            }
            const shouldPersist = dragStarted;
            draggedWidget = null;
            dragStarted = false;
            if (shouldPersist) {
                try {
                    await persistWidgetOrder();
                    syncWidgetOverviewOrder();
                    setFeedback('Widget-Reihenfolge gespeichert.');
                } catch (error) {
                    setFeedback(error instanceof Error ? error.message : 'Widget-Reihenfolge konnte nicht gespeichert werden.', true);
                }
            }
        };

        widgetGrid.querySelectorAll('.dashboard-widget-titlebar').forEach((titlebar) => {
            titlebar.addEventListener('mousedown', (event) => {
                if (event.button !== 0) return;
                if (!(event.target instanceof Element)) return;
                if (event.target.closest('.dashboard-widget-window-actions')) return;
                const shell = titlebar.closest('.dashboard-widget-shell');
                if (!(shell instanceof HTMLElement)) return;
                draggedWidget = shell;
                dragStartX = event.clientX;
                dragStartY = event.clientY;
                dragStarted = false;
                event.preventDefault();
            });
        });

        document.addEventListener('mousemove', (event) => {
            if (!draggedWidget) return;
            const distance = Math.hypot(event.clientX - dragStartX, event.clientY - dragStartY);
            if (distance < 6) return;
            event.preventDefault();
            if (!dragStarted) {
                dragStarted = true;
                draggedWidget.classList.add('is-dragging');
            }

            const pointedElement = document.elementFromPoint(event.clientX, event.clientY);
            const target = pointedElement instanceof Element ? pointedElement.closest('.dashboard-widget-shell') : null;
            if (!(target instanceof HTMLElement) || target === draggedWidget) return;

            const rect = target.getBoundingClientRect();
            const sameRow = event.clientY >= rect.top && event.clientY <= rect.bottom;
            const before = sameRow
                ? event.clientX < rect.left + rect.width / 2
                : event.clientY < rect.top + rect.height / 2;
            widgetGrid.insertBefore(draggedWidget, before ? target : target.nextSibling);
        });

        document.addEventListener('mouseup', () => {
            void finishWidgetDrag();
        });

        document.addEventListener('mouseleave', () => {
            void finishWidgetDrag();
        });
    }

    const autoRefreshConfig = document.getElementById('dashboard-auto-refresh-config');
    const autoRefreshToggle = document.getElementById('dashboard-auto-refresh-toggle');
    const autoRefreshStatusValue = document.getElementById('dashboard-auto-refresh-status-value');
    const autoRefreshCountdown = document.getElementById('dashboard-auto-refresh-countdown');
    const clockElement = document.getElementById('dashboard-clock');
    const lastUpdatedElement = document.getElementById('dashboard-last-updated');
    if (autoRefreshConfig && autoRefreshToggle instanceof HTMLInputElement) {
        const intervalMin = Number(autoRefreshConfig.dataset.intervalMin || '5');
        const intervalMax = Number(autoRefreshConfig.dataset.intervalMax || '240');
        const timezone = String(clockElement?.dataset.timezone || 'UTC');
        const serverUtcRaw = String(clockElement?.dataset.serverUtc || '');
        const parsedServerUtc = Date.parse(serverUtcRaw);
        const serverBaseMs = Number.isFinite(parsedServerUtc) ? parsedServerUtc : Date.now();
        const clientBaseMs = Date.now();

        let enabled = String(autoRefreshConfig.dataset.enabled || '1') === '1';
        let intervalMinutes = Number(autoRefreshConfig.dataset.intervalMinutes || '30');
        let refreshTimeoutHandle = null;
        let tickIntervalHandle = null;
        let nextRefreshAtMs = null;
        const pauseReasons = new Set();

        const createTimeFormatter = () => {
            try {
                return new Intl.DateTimeFormat('de-DE', {
                    timeZone: timezone,
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit',
                    hour12: false,
                });
            } catch (error) {
                return new Intl.DateTimeFormat('de-DE', {
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit',
                    hour12: false,
                });
            }
        };
        const timeFormatter = createTimeFormatter();

        const formatDuration = (secondsTotal) => {
            const safe = Math.max(0, Math.floor(secondsTotal));
            const hours = Math.floor(safe / 3600);
            const minutes = Math.floor((safe % 3600) / 60);
            const seconds = safe % 60;
            if (hours > 0) {
                return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
            }
            return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
        };

        const clampInterval = (value) => {
            const fallback = 30;
            if (!Number.isFinite(value)) return fallback;
            if (value < intervalMin) return intervalMin;
            if (value > intervalMax) return intervalMax;
            return Math.floor(value);
        };

        const renderAutoRefreshState = () => {
            if (!autoRefreshStatusValue) return;
            autoRefreshStatusValue.textContent = `${enabled ? 'Aktiv' : 'Inaktiv'} · Intervall ${intervalMinutes} Minuten`;
        };

        const isAutoRefreshPaused = () => pauseReasons.size > 0;

        const renderTimeState = () => {
            const nowMs = serverBaseMs + (Date.now() - clientBaseMs);
            if (clockElement) {
                clockElement.textContent = timeFormatter.format(new Date(nowMs));
            }
            if (lastUpdatedElement) {
                lastUpdatedElement.textContent = timeFormatter.format(new Date(serverBaseMs));
            }

            if (!autoRefreshCountdown) {
                return;
            }
            if (!enabled || nextRefreshAtMs === null) {
                autoRefreshCountdown.textContent = 'Automatische Aktualisierung deaktiviert.';
                return;
            }
            if (isAutoRefreshPaused()) {
                autoRefreshCountdown.textContent = 'Auto-Refresh pausiert - Dialog geöffnet.';
                return;
            }

            const remainingSeconds = Math.max(0, Math.ceil((nextRefreshAtMs - Date.now()) / 1000));
            autoRefreshCountdown.textContent = `Nächster Refresh in ${formatDuration(remainingSeconds)}`;
        };

        const scheduleAutoRefresh = () => {
            if (refreshTimeoutHandle !== null) {
                window.clearTimeout(refreshTimeoutHandle);
                refreshTimeoutHandle = null;
            }
            if (!enabled) {
                nextRefreshAtMs = null;
                renderTimeState();
                return;
            }
            if (isAutoRefreshPaused()) {
                renderTimeState();
                return;
            }
            const delayMs = intervalMinutes * 60 * 1000;
            nextRefreshAtMs = Date.now() + delayMs;
            refreshTimeoutHandle = window.setTimeout(() => {
                refreshTimeoutHandle = null;
                if (pauseReasons.size > 0) {
                    renderTimeState();
                    return;
                }
                window.location.reload();
            }, delayMs);
            renderTimeState();
        };

        const startTimeTicker = () => {
            if (tickIntervalHandle !== null) {
                return;
            }
            tickIntervalHandle = window.setInterval(renderTimeState, 1000);
        };

        pauseDashboardAutoRefresh = (reason = 'unknown') => {
            pauseReasons.add(String(reason || 'unknown'));
            if (refreshTimeoutHandle !== null) {
                window.clearTimeout(refreshTimeoutHandle);
                refreshTimeoutHandle = null;
            }
            renderTimeState();
        };

        resumeDashboardAutoRefresh = (reason = 'unknown') => {
            pauseReasons.delete(String(reason || 'unknown'));
            if (pauseReasons.size === 0) {
                scheduleAutoRefresh();
                return;
            }
            renderTimeState();
        };

        window.pauseDashboardAutoRefresh = pauseDashboardAutoRefresh;
        window.resumeDashboardAutoRefresh = resumeDashboardAutoRefresh;

        intervalMinutes = clampInterval(intervalMinutes);
        enabled = autoRefreshToggle.checked;
        renderAutoRefreshState();
        renderTimeState();
        scheduleAutoRefresh();
        startTimeTicker();

        autoRefreshToggle.addEventListener('change', async () => {
            const previousEnabled = enabled;
            enabled = autoRefreshToggle.checked;
            autoRefreshToggle.disabled = true;
            try {
                const response = await fetch('/dashboard/settings/auto-refresh', {
                    method: 'POST',
                    headers: {
                        ...csrfHeaders,
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        enabled: enabled ? 1 : 0,
                        interval_minutes: intervalMinutes,
                    }),
                });
                const payload = await response.json();
                if (!response.ok || !payload.ok) {
                    throw new Error(payload.message || 'Einstellung konnte nicht gespeichert werden.');
                }

                enabled = Number(payload.enabled || 0) === 1;
                intervalMinutes = clampInterval(Number(payload.interval_minutes || intervalMinutes));
                autoRefreshConfig.dataset.enabled = enabled ? '1' : '0';
                autoRefreshConfig.dataset.intervalMinutes = String(intervalMinutes);
                autoRefreshToggle.checked = enabled;
                renderAutoRefreshState();
                scheduleAutoRefresh();
                setFeedback(payload.message || 'Auto-Aktualisieren gespeichert.');
            } catch (error) {
                enabled = previousEnabled;
                autoRefreshToggle.checked = previousEnabled;
                renderAutoRefreshState();
                scheduleAutoRefresh();
                setFeedback(error instanceof Error ? error.message : 'Einstellung konnte nicht gespeichert werden.', true);
            } finally {
                autoRefreshToggle.disabled = false;
            }
        });
    }

    const updateTaskUi = (taskId, isDone) => {
        const row = document.querySelector(`[data-task-item="${taskId}"]`);
        if (row) row.classList.toggle('is-done', isDone);
        const checkbox = document.querySelector(`.js-task-toggle[data-task-id="${taskId}"]`);
        if (checkbox) {
            checkbox.checked = isDone;
            checkbox.setAttribute('aria-checked', isDone ? 'true' : 'false');
        }
        if (row) {
            const titleRow = row.querySelector('.js-task-title-row');
            let badge = row.querySelector('.js-task-done-badge');
            if (isDone && !badge && titleRow) {
                badge = document.createElement('span');
                badge.className = 'badge text-bg-success js-task-done-badge';
                badge.textContent = 'Erledigt';
                titleRow.appendChild(badge);
            }
            if (!isDone && badge) {
                badge.remove();
            }
        }
    };

    const submitTaskToggleFallback = (taskId, isDone) => {
        const form = document.createElement('form');
        form.method = 'post';
        form.action = '/dashboard/tasks/toggle';
        form.hidden = true;

        const taskInput = document.createElement('input');
        taskInput.type = 'hidden';
        taskInput.name = 'task_id';
        taskInput.value = String(Number(taskId));
        form.appendChild(taskInput);

        const doneInput = document.createElement('input');
        doneInput.type = 'hidden';
        doneInput.name = 'is_done';
        doneInput.value = isDone ? '1' : '0';
        form.appendChild(doneInput);

        document.body.appendChild(form);
        form.submit();
    };

    const toggleTask = async (taskId, isDone, options = {}) => {
        const allowFallback = options.allowFallback !== false;
        const body = JSON.stringify({ task_id: Number(taskId), is_done: isDone ? 1 : 0 });
        const headers = {
            ...csrfHeaders,
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        };
        let lastError = null;

        for (let attempt = 0; attempt < 2; attempt += 1) {
            try {
                const response = await fetch('/dashboard/tasks/toggle', {
                    method: 'POST',
                    headers,
                    credentials: 'same-origin',
                    cache: 'no-store',
                    body,
                });

                const contentType = response.headers.get('content-type') || '';
                if (response.status === 401 || response.redirected || !contentType.includes('application/json')) {
                    if (allowFallback) {
                        window.location.reload();
                    }
                    throw new Error('Sitzung wurde erneuert. Dashboard wird neu geladen.');
                }

                const payload = await response.json();
                if (!response.ok || !payload.ok) {
                    throw new Error(payload.message || 'Speichern fehlgeschlagen.');
                }

                return payload;
            } catch (error) {
                lastError = error;
                if (attempt === 0) {
                    await new Promise((resolve) => window.setTimeout(resolve, 350));
                    continue;
                }
            }
        }

        if (allowFallback) {
            submitTaskToggleFallback(taskId, isDone);
            throw new Error('Direktes Speichern war nicht möglich. Modulon speichert per Seitenfallback.');
        }

        throw lastError instanceof Error ? lastError : new Error('Speichern fehlgeschlagen.');
    };

    document.querySelectorAll('.js-task-toggle').forEach((toggle) => {
        toggle.addEventListener('change', async () => {
            const taskId = Number(toggle.dataset.taskId || 0);
            const targetState = !!toggle.checked;
            const previousState = !targetState;
            toggle.disabled = true;
            try {
                await toggleTask(taskId, targetState);
                updateTaskUi(taskId, targetState);
                setFeedback(targetState ? 'Aufgabe als erledigt markiert.' : 'Aufgabe wieder geöffnet.');
            } catch (error) {
                toggle.checked = previousState;
                updateTaskUi(taskId, previousState);
                setFeedback(error instanceof Error ? error.message : 'Speichern fehlgeschlagen.', true);
            } finally {
                toggle.disabled = false;
            }
        });
    });

    const parseDashboardJsonResponse = async (response) => {
        const contentType = response.headers.get('content-type') || '';
        if (response.redirected || !contentType.includes('application/json')) {
            throw new Error('Sitzung abgelaufen oder Antwort ungültig. Bitte Seite neu laden.');
        }

        const payload = await response.json();
        if (!response.ok || !payload.ok) {
            throw new Error(payload.message || 'Aktion konnte nicht ausgeführt werden.');
        }

        return payload;
    };

    const dashboardArchiveEmptyText = (listType, archived) => {
        if (listType === 'tasks') {
            return archived ? 'Keine archivierten Aufgaben.' : 'Noch keine aktiven Aufgaben vorhanden.';
        }

        return archived ? 'Keine archivierten Notizen.' : 'Noch keine aktiven Notizen vorhanden.';
    };

    const ensureArchiveEmptyState = (list, listType, archived) => {
        const items = list.querySelectorAll('[data-dashboard-archive-item]');
        let empty = list.querySelector('.js-dashboard-empty-state');
        if (items.length > 0) {
            if (empty) empty.remove();
            return;
        }

        if (!empty) {
            empty = document.createElement('p');
            empty.className = 'small text-body-secondary mb-0 js-dashboard-empty-state';
            list.appendChild(empty);
        }
        empty.textContent = dashboardArchiveEmptyText(listType, archived);
    };

    const updateDashboardArchiveButtons = (item, archived) => {
        item.classList.toggle('is-archived', archived);
        const hasInlineNoteArchive = item.querySelector('.js-dashboard-note-archive-submit') !== null;
        item.querySelectorAll('.js-dashboard-archive-form').forEach((form) => {
            const state = Number(form.dataset.dashboardArchiveState || 0) === 1;
            const isInlineNoteFallback = hasInlineNoteArchive && String(form.dataset.dashboardArchiveKind || '') === 'note' && state;
            form.classList.toggle('d-none', isInlineNoteFallback || state === archived);
        });
        item.querySelectorAll('.js-dashboard-note-archive-submit').forEach((button) => {
            button.classList.toggle('d-none', archived);
        });
    };

    const refreshDashboardArchiveCount = (widgetId, listType) => {
        const archiveList = document.querySelector(`.js-dashboard-archive-list[data-dashboard-list="${listType}"][data-widget-id="${widgetId}"]`);
        const panel = document.querySelector(`[data-dashboard-archive-panel="${listType}"][data-widget-id="${widgetId}"]`);
        if (!archiveList || !panel) return;

        const count = archiveList.querySelectorAll('[data-dashboard-archive-item]').length;
        const badge = panel.querySelector('.js-dashboard-archive-count');
        if (badge) badge.textContent = String(count);
    };

    const refreshDashboardActiveCount = (widgetId, listType) => {
        const activeList = document.querySelector(`.js-dashboard-active-list[data-dashboard-list="${listType}"][data-widget-id="${widgetId}"]`);
        const badge = document.querySelector(`[data-dashboard-active-count="${listType}"][data-widget-id="${widgetId}"] .js-dashboard-active-count`);
        if (!activeList || !badge) return;

        const count = activeList.querySelectorAll('[data-dashboard-archive-item]').length;
        badge.textContent = String(count);
    };

    const applyDashboardArchiveResult = (payload) => {
        const kind = String(payload.type || '');
        const widgetId = Number(payload.widget_id || 0);
        const archived = Number(payload.archived || 0) === 1;
        const listType = kind === 'task' ? 'tasks' : 'notes';
        const itemSelector = kind === 'task'
            ? `[data-dashboard-archive-item="task"][data-task-item="${Number(payload.id || 0)}"]`
            : `[data-dashboard-archive-item="note"][data-note-item="${Number(payload.id || 0)}"]`;
        const item = document.querySelector(itemSelector);
        const activeList = document.querySelector(`.js-dashboard-active-list[data-dashboard-list="${listType}"][data-widget-id="${widgetId}"]`);
        const archiveList = document.querySelector(`.js-dashboard-archive-list[data-dashboard-list="${listType}"][data-widget-id="${widgetId}"]`);
        if (!item || !activeList || !archiveList) {
            return;
        }

        const sourceList = item.parentElement;
        const targetList = archived ? archiveList : activeList;
        const targetEmpty = targetList.querySelector('.js-dashboard-empty-state');
        if (targetEmpty) targetEmpty.remove();

        updateDashboardArchiveButtons(item, archived);
        targetList.appendChild(item);

        if (sourceList instanceof HTMLElement) {
            ensureArchiveEmptyState(sourceList, listType, sourceList.classList.contains('js-dashboard-archive-list'));
        }
        ensureArchiveEmptyState(targetList, listType, archived);
        refreshDashboardActiveCount(widgetId, listType);
        refreshDashboardArchiveCount(widgetId, listType);
    };

    const submitDashboardArchive = async (form, submitter = null) => {
        const button = submitter instanceof HTMLButtonElement ? submitter : form.querySelector('button[type="submit"]');
        if (button instanceof HTMLButtonElement) {
            button.disabled = true;
        }

        const formData = new FormData(form);
        let action = form.action;
        if (submitter instanceof HTMLButtonElement) {
            action = submitter.formAction || action;
            if (submitter.name) {
                formData.set(submitter.name, submitter.value);
            }
        }

        try {
            const response = await fetch(action, {
                method: 'POST',
                body: formData,
                headers: {
                    ...csrfHeaders,
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            const payload = await parseDashboardJsonResponse(response);
            applyDashboardArchiveResult(payload);
            setFeedback(payload.message || 'Archiv aktualisiert.');
        } catch (error) {
            setFeedback(error instanceof Error ? error.message : 'Archiv-Aktion fehlgeschlagen.', true);
        } finally {
            if (button instanceof HTMLButtonElement) {
                button.disabled = false;
            }
        }
    };

    document.querySelectorAll('.js-dashboard-archive-form').forEach((form) => {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            submitDashboardArchive(form);
        });
    });

    document.querySelectorAll('.js-dashboard-note-archive-submit').forEach((button) => {
        button.addEventListener('click', (event) => {
            const form = button.form;
            if (!(form instanceof HTMLFormElement)) return;
            event.preventDefault();
            submitDashboardArchive(form, button);
        });
    });

    document.querySelectorAll('.js-widget-form-toggle').forEach((button) => {
        const targetId = String(button.dataset.targetId || '');
        if (targetId === '') return;

        const block = document.getElementById(targetId);
        if (!block) return;

        // Progressive Enhancement: ohne JS bleibt das Formular sichtbar.
        block.classList.add('d-none');
        button.setAttribute('aria-expanded', 'false');

        button.addEventListener('click', () => {
            const shouldOpen = block.classList.contains('d-none');
            block.classList.toggle('d-none', !shouldOpen);
            button.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
        });
    });

    document.querySelectorAll('.js-link-edit-block').forEach((block) => {
        block.classList.add('d-none');
    });
    document.querySelectorAll('.js-link-edit-toggle').forEach((button) => {
        const targetId = String(button.dataset.targetId || '');
        if (targetId === '') return;

        const block = document.getElementById(targetId);
        if (!block) return;

        button.addEventListener('click', () => {
            const shouldOpen = block.classList.contains('d-none');
            block.classList.toggle('d-none', !shouldOpen);
            document.querySelectorAll(`.js-link-edit-toggle[data-target-id="${targetId}"]`).forEach((targetButton) => {
                targetButton.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
            });
        });
    });

    document.querySelectorAll('.js-task-edit-block').forEach((block) => {
        block.classList.add('d-none');
    });
    document.querySelectorAll('.js-task-edit-toggle').forEach((button) => {
        const targetId = String(button.dataset.targetId || '');
        if (targetId === '') return;

        const block = document.getElementById(targetId);
        if (!block) return;

        button.addEventListener('click', () => {
            const shouldOpen = block.classList.contains('d-none');
            block.classList.toggle('d-none', !shouldOpen);
            document.querySelectorAll(`.js-task-edit-toggle[data-target-id="${targetId}"]`).forEach((targetButton) => {
                targetButton.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
            });
        });
    });

    document.querySelectorAll('.js-link-analyze-form').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const submitButton = form.querySelector('button[type="submit"]');
            if (submitButton instanceof HTMLButtonElement) {
                submitButton.disabled = true;
            }

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: {
                        ...csrfHeaders,
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const payload = await response.json();
                if (!response.ok || !payload.ok) {
                    throw new Error(payload.message || 'Analyse fehlgeschlagen.');
                }

                const block = form.closest('.border');
                const saveForm = block ? block.querySelector('form[action="/dashboard/links/save"]') : null;
                const setInputValue = (selector, value) => {
                    if (!(saveForm instanceof HTMLFormElement)) return;
                    const input = saveForm.querySelector(selector);
                    if (input instanceof HTMLInputElement || input instanceof HTMLSelectElement) {
                        input.value = String(value ?? '');
                    }
                };

                setInputValue('input[name="url"]', payload.url || '');
                setInputValue('input[name="title"]', payload.title || '');
                setInputValue('input[name="favicon_url"]', payload.favicon_url || '');

                if (saveForm instanceof HTMLFormElement) {
                    const folderSelect = saveForm.querySelector('select[name="folder_id"]');
                    if (folderSelect instanceof HTMLSelectElement) {
                        const nextFolder = payload.folder_id ?? 0;
                        folderSelect.value = String(nextFolder);
                    }
                }

                setFeedback(payload.message || 'URL analysiert.');
            } catch (error) {
                setFeedback(error instanceof Error ? error.message : 'Analyse fehlgeschlagen.', true);
            } finally {
                if (submitButton instanceof HTMLButtonElement) {
                    submitButton.disabled = false;
                }
            }
        });
    });

    let pendingTaskId = 0;
    const modalElement = document.getElementById('task-complete-modal');
    let modalInstance = null;
    const getTaskModal = () => {
        if (!modalElement) return null;
        if (modalInstance) return modalInstance;
        if (!window.bootstrap || typeof window.bootstrap.Modal !== 'function') return null;
        modalInstance = new window.bootstrap.Modal(modalElement);
        return modalInstance;
    };
    const modalConfirm = document.getElementById('task-complete-confirm');
    if (modalElement) {
        modalElement.addEventListener('show.bs.modal', () => {
            pauseDashboardAutoRefresh('task-completion-dialog');
        });
        modalElement.addEventListener('hidden.bs.modal', () => {
            pendingTaskId = 0;
            resumeDashboardAutoRefresh('task-completion-dialog');
        });
    }

    document.querySelectorAll('.js-task-open-link').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            const taskId = Number(button.dataset.taskId || 0);
            const url = String(button.dataset.taskUrl || '');
            if (!taskId || url === '') return;

            window.open(url, '_blank', 'noopener');

            const taskItem = button.closest('.modulon-task-item');
            const checkbox = taskItem
                ? taskItem.querySelector('.js-task-toggle')
                : document.querySelector(`.js-task-toggle[data-task-id="${taskId}"]`);
            const isDone = checkbox
                ? checkbox.checked
                : (taskItem ? taskItem.classList.contains('is-done') : false);

            if (isDone) {
                return;
            }

            pendingTaskId = taskId;
            const modal = getTaskModal();
            if (modal) {
                pauseDashboardAutoRefresh('task-completion-dialog');
                modal.show();
                return;
            }

            let confirmed = false;
            pauseDashboardAutoRefresh('task-completion-confirm');
            try {
                confirmed = window.confirm('Aufgabe als erledigt markieren?');
            } finally {
                resumeDashboardAutoRefresh('task-completion-confirm');
            }

            if (confirmed) {
                toggleTask(taskId, true)
                    .then(() => {
                        updateTaskUi(taskId, true);
                        setFeedback('Aufgabe als erledigt markiert.');
                    })
                    .catch((error) => {
                        setFeedback(error instanceof Error ? error.message : 'Speichern fehlgeschlagen.', true);
                    });
            }
        });
    });

    if (modalConfirm) {
        modalConfirm.addEventListener('click', async () => {
            if (!pendingTaskId) return;
            modalConfirm.setAttribute('disabled', 'disabled');
            try {
                await toggleTask(pendingTaskId, true);
                updateTaskUi(pendingTaskId, true);
                setFeedback('Aufgabe als erledigt markiert.');
                const modal = getTaskModal();
                if (modal) modal.hide();
            } catch (error) {
                setFeedback(error instanceof Error ? error.message : 'Speichern fehlgeschlagen.', true);
            } finally {
                modalConfirm.removeAttribute('disabled');
                pendingTaskId = 0;
            }
        });
    }

    const syncTaskRepeatFields = (form) => {
        const repeatType = form.querySelector('.js-repeat-type');
        if (!repeatType) return;
        const weekly = form.querySelector('.js-repeat-weekly');
        const monthly = form.querySelector('.js-repeat-monthly');
        const fixedDay = form.querySelector('.js-repeat-fixed-day');
        const monthOrdinal = form.querySelector('.js-repeat-month-ordinal');
        const monthWeekday = form.querySelector('.js-repeat-month-weekday');
        const monthMode = form.querySelector('.js-repeat-month-mode');
        const type = String(repeatType.value || 'none');

        if (weekly) weekly.classList.toggle('d-none', type !== 'weekly');
        if (monthly) monthly.classList.toggle('d-none', type !== 'monthly');

        const monthModeValue = monthMode ? String(monthMode.value) : '';
        const isFixed = type === 'monthly' && monthModeValue === 'fixed_day';
        const isOrdinalWeekday = type === 'monthly' && monthModeValue === 'ordinal_weekday';
        if (fixedDay) fixedDay.classList.toggle('d-none', !isFixed);
        if (monthOrdinal) monthOrdinal.classList.toggle('d-none', !isOrdinalWeekday);
        if (monthWeekday) monthWeekday.classList.toggle('d-none', !isOrdinalWeekday);
    };

    document.querySelectorAll('.js-task-form').forEach((form) => {
        const repeatType = form.querySelector('.js-repeat-type');
        const monthMode = form.querySelector('.js-repeat-month-mode');
        if (repeatType) {
            repeatType.addEventListener('change', () => syncTaskRepeatFields(form));
        }
        if (monthMode) {
            monthMode.addEventListener('change', () => syncTaskRepeatFields(form));
        }
        syncTaskRepeatFields(form);
    });
})();
</script>
