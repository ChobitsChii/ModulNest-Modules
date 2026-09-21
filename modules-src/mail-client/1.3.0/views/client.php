<?php
declare(strict_types=1);
/**
 * Haupt-View des Mail-Clients (Sidebar links, Liste oben, Mailansicht unten).
 *
 * @var array  $accounts    Liste der Mail-Konten des Benutzers
 * @var string $csrf_token
 * @var string $current_path
 */
$accounts  = is_array($accounts ?? null) ? $accounts : [];
$csrfToken = (string) ($csrf_token ?? '');
$success   = (string) ($mail_success ?? '');
?>
<!-- Progressive Web App Meta-Tags & Manifest -->
<script>
(function() {
    function injectHeadTag(tag, attrs) {
        var el = document.createElement(tag);
        for (var k in attrs) { el.setAttribute(k, attrs[k]); }
        document.head.appendChild(el);
    }
    if (!document.querySelector('head link[rel="manifest"]')) {
        injectHeadTag('link', { rel: 'manifest', href: '/mail-client/manifest.json' });
    }
    if (!document.querySelector('head link[rel="apple-touch-icon"]')) {
        injectHeadTag('link', { rel: 'apple-touch-icon', href: '/mail-client/icon-192.png' });
    }
    if (!document.querySelector('head meta[name="theme-color"]')) {
        injectHeadTag('meta', { name: 'theme-color', content: '#0d6efd' });
    }
})();
</script>
<link rel="manifest" href="/mail-client/manifest.json">
<link rel="icon" type="image/svg+xml" href="/mail-client/icon.svg">
<link rel="apple-touch-icon" href="/mail-client/icon-192.png">
<meta name="theme-color" content="#0d6efd">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Modulon Mail">
<style><?php require __DIR__ . '/../assets/css/mail-client.css'; ?></style>
<script>
(function() {
    const isApp = window.matchMedia('(display-mode: standalone)').matches
        || window.matchMedia('(display-mode: fullscreen)').matches
        || window.matchMedia('(display-mode: minimal-ui)').matches
        || (window.navigator && window.navigator.standalone === true)
        || localStorage.getItem('mc_is_maximized') === '1'
        || (window.opener && window.name === 'ModulonMailClientPopout')
        || (new URLSearchParams(window.location.search).get('frameless') === '1');
    if (isApp) {
        document.body.classList.add('mc-app-mode');
        if (localStorage.getItem('mc_is_maximized') === '1') {
            document.body.classList.add('mc-maximized');
        }
    }
})();
</script>

<div class="mc-app">

    <!-- ── Toolbar ──────────────────────────────────────────────────────────── -->
    <div class="mc-toolbar">
        <!-- Mobile: Sidebar öffnen -->
        <button class="btn btn-sm btn-outline-secondary d-sm-none" onclick="mailClientApp.openSidebar()" aria-label="Ordner">
            <i class="bi bi-list"></i>
        </button>

        <span class="mc-toolbar-brand d-none d-sm-inline-flex">
            <i class="bi bi-envelope-at"></i> Mail-Client
        </span>

        <div class="mc-toolbar-actions d-flex align-items-center gap-1">
            <?php if ($accounts !== []): ?>
            <a href="/mail-client/compose" class="btn btn-sm btn-primary">
                <i class="bi bi-pencil-square"></i> <span class="d-none d-md-inline">Neue Mail</span>
            </a>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="mc-btn-refresh" title="Aktuellen Ordner abrufen">
                <i class="bi bi-arrow-clockwise"></i> <span class="d-none d-xl-inline ms-1">Abrufen</span>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="mc-btn-sync-all" title="Alle Ordner nacheinander synchronisieren">
                <i class="bi bi-arrow-repeat"></i> <span class="d-none d-md-inline ms-1">Alle synchronisieren</span>
            </button>
            <?php endif; ?>
            <a href="/mail-client/accounts/create" class="btn btn-sm btn-outline-secondary" title="Konto hinzufügen">
                <i class="bi bi-person-plus"></i>
                <span class="d-none d-xl-inline ms-1">Konto hinzufügen</span>
            </a>
            <button class="btn btn-sm btn-outline-secondary" id="mc-btn-maximize" type="button" title="Im Fenster maximieren (ohne Modulon Header/Footer)">
                <i class="bi bi-arrows-fullscreen"></i>
                <span class="d-none d-xl-inline ms-1" id="mc-btn-maximize-text">Maximieren</span>
            </button>
            <button class="btn btn-sm btn-outline-secondary" id="mc-btn-popup" type="button" title="In eigenem App-Fenster öffnen (ohne Adress-/Favoritenleiste)">
                <i class="bi bi-window-stack"></i>
                <span class="d-none d-xl-inline ms-1">App-Fenster</span>
            </button>
            <button class="btn btn-sm btn-outline-secondary" id="mc-btn-calendar" type="button" title="Termine-Leiste ein-/ausblenden">
                <i class="bi bi-calendar3-event"></i>
                <span class="d-none d-xl-inline ms-1">Termine</span>
            </button>
            <button class="btn btn-sm btn-outline-secondary" id="mc-btn-settings" type="button" data-bs-toggle="modal" data-bs-target="#mcSettingsModal" title="Einstellungen">
                <i class="bi bi-gear"></i>
                <span class="d-none d-xl-inline ms-1">Einstellungen</span>
            </button>
        </div>
    </div>

    <?php if ($success !== ''): ?>
    <div class="alert alert-success mb-0 rounded-0 border-x-0 py-1 px-3" style="font-size:0.84rem;">
        <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <?php if ($accounts === []): ?>
    <!-- Kein Konto angelegt -->
    <div class="flex-grow-1 d-flex flex-column align-items-center justify-content-center text-center p-4 gap-3">
        <i class="bi bi-envelope-x" style="font-size:3.5rem;opacity:0.4;"></i>
        <div>
            <h2 class="h5">Noch kein Mailkonto hinterlegt</h2>
            <p class="text-body-secondary mb-3">Füge dein erstes IMAP-Konto hinzu, um loszulegen.</p>
            <a href="/mail-client/accounts/create" class="btn btn-primary">
                <i class="bi bi-person-plus"></i> Konto hinzufügen
            </a>
        </div>
    </div>
    <?php else: ?>

    <!-- ── Layout-Panes ─────────────────────────────────────────────────── -->
    <div class="mc-panes">

        <!-- ─ Linkes Pane: Ordnerbaum ─ -->
        <div class="mc-sidebar" id="mc-sidebar">

            <?php foreach ($accounts as $acc): ?>
            <?php
            $accId    = (int) $acc['id'];
            $accName  = (string) $acc['display_name'];
            $accEmail = (string) $acc['email_address'];
            ?>
            <div class="mc-account-group" data-account-group="<?= $accId ?>">
                <div class="mc-account-header" data-account="<?= $accId ?>" draggable="true">
                    <span class="mc-account-drag-handle me-1 text-secondary" title="Konto verschieben" style="cursor: grab; display: inline-flex; align-items: center;">
                        <i class="bi bi-grip-vertical"></i>
                    </span>
                    <i class="bi bi-chevron-down mc-account-toggle"></i>
                    <span class="flex-grow-1 overflow-hidden">
                        <span class="d-block text-truncate"><?= htmlspecialchars($accName, ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="mc-account-email"><?= htmlspecialchars($accEmail, ENT_QUOTES, 'UTF-8') ?></span>
                    </span>
                    <div class="mc-account-actions d-inline-flex align-items-center gap-1" onclick="event.stopPropagation()">
                        <button type="button" class="btn btn-link btn-sm p-0 text-secondary mc-btn-move-account" data-account="<?= $accId ?>" data-dir="up" title="Konto nach oben verschieben">
                            <i class="bi bi-chevron-up"></i>
                        </button>
                        <button type="button" class="btn btn-link btn-sm p-0 text-secondary mc-btn-move-account" data-account="<?= $accId ?>" data-dir="down" title="Konto nach unten verschieben">
                            <i class="bi bi-chevron-down"></i>
                        </button>
                        <a href="/mail-client/accounts/<?= $accId ?>/edit"
                           class="btn btn-link btn-sm p-0 text-secondary"
                           title="Konto bearbeiten">
                            <i class="bi bi-gear"></i>
                        </a>
                    </div>
                </div>
                <ul class="mc-folder-list" data-account="<?= $accId ?>">
                    <li class="mc-folder-item"
                        data-account="<?= $accId ?>"
                        data-folder="INBOX"
                        data-parent=""
                        data-level="0"
                        data-has-children="false"
                        style="padding-left:0.5rem;"
                        title="Posteingang">
                        <span class="mc-folder-toggle-spacer"></span>
                        <i class="bi bi-inbox mc-folder-icon"></i>
                        <span class="mc-folder-name">Posteingang</span>
                        <?php if (!empty($acc['inbox_unseen'])): ?>
                        <span class="mc-folder-unseen"><?= number_format((int) $acc['inbox_unseen'], 0, ',', '.') ?></span>
                        <?php endif; ?>
                    </li>
                    <li class="mc-folder-item"
                        data-account="<?= $accId ?>"
                        data-folder-load="true"
                        data-folder=""
                        style="padding-left:0.5rem;"
                        onclick="mailClientApp.loadFolders(<?= $accId ?>, this)"
                        title="Alle Ordner laden">
                        <span class="mc-folder-toggle-spacer"></span>
                        <i class="bi bi-three-dots mc-folder-icon"></i>
                        <span class="mc-folder-name text-secondary" style="font-size:0.78rem;">Alle Ordner laden…</span>
                    </li>
                </ul>
            </div>
            <?php endforeach; ?>

        </div>
        <!-- Mobile: Sidebar-Backdrop -->
        <div class="d-sm-none" id="mc-sidebar-backdrop"
             style="display:none!important;position:fixed;inset:0;z-index:1059;background:rgba(0,0,0,0.4)"
             onclick="mailClientApp.closeSidebar()"></div>

        <div class="mc-sidebar-resizer" id="mc-sidebar-resizer"></div>

        <!-- ─ Rechter Bereich: Oben Liste, Unten Mail ─ -->
        <div class="mc-main-area" id="mc-main-area">

            <!-- Hauptinhalts-Bereich: Liste + Trenner + Mail-Vorschau -->
            <div class="mc-main-content" id="mc-main-content">

                <!-- ─ Oberer Teil: Nachrichten-Liste (im 3-Spalten-Layout: linke Spalte) ─ -->
                <div class="mc-list-pane" id="mc-list-pane">
                <div class="mc-list-header d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-2 overflow-hidden">
                        <span class="mc-list-header-title text-secondary">Ordner wählen</span>
                        <button type="button" class="btn btn-sm btn-link p-0 text-secondary ms-1" id="mc-list-refresh" title="Aktuellen Ordner abrufen" style="line-height:1;display:none;">
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                        <span class="mc-list-unseen-count d-none text-secondary small"></span>
                    </div>
                    <div class="d-flex align-items-center gap-1">
                        <!-- Spalten anpassen Dropdown -->
                        <div class="dropdown" id="mc-cols-dropdown-wrap">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle mc-cols-btn" type="button" id="mcColsDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" title="Spalten anpassen">
                                <i class="bi bi-layout-three-columns"></i> <span class="d-none d-md-inline">Spalten</span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm p-2" aria-labelledby="mcColsDropdown" style="min-width: 220px;">
                                <li class="dropdown-header small text-uppercase fw-bold text-secondary px-3 py-1">Spalten anzeigen</li>
                                <li>
                                    <label class="dropdown-item d-flex align-items-center justify-content-between px-3 py-1 mb-0 user-select-none" style="cursor:pointer;">
                                        <span class="small">Status</span>
                                        <div class="form-check form-switch mb-0 ps-0">
                                            <input class="form-check-input ms-0 mc-col-toggle" type="checkbox" data-col="status" checked role="switch">
                                        </div>
                                    </label>
                                </li>
                                <li>
                                    <label class="dropdown-item d-flex align-items-center justify-content-between px-3 py-1 mb-0 user-select-none" style="cursor:pointer;">
                                        <span class="small">Von (Absender)</span>
                                        <div class="form-check form-switch mb-0 ps-0">
                                            <input class="form-check-input ms-0 mc-col-toggle" type="checkbox" data-col="sender" checked role="switch">
                                        </div>
                                    </label>
                                </li>
                                <li>
                                    <label class="dropdown-item d-flex align-items-center justify-content-between px-3 py-1 mb-0 user-select-none" style="cursor:pointer;">
                                        <span class="small">An (Empfänger)</span>
                                        <div class="form-check form-switch mb-0 ps-0">
                                            <input class="form-check-input ms-0 mc-col-toggle" type="checkbox" data-col="recipient" checked role="switch">
                                        </div>
                                    </label>
                                </li>
                                <li>
                                    <label class="dropdown-item d-flex align-items-center justify-content-between px-3 py-1 mb-0 user-select-none" style="cursor:pointer;">
                                        <span class="small">Betreff</span>
                                        <div class="form-check form-switch mb-0 ps-0">
                                            <input class="form-check-input ms-0 mc-col-toggle" type="checkbox" data-col="subject" checked role="switch">
                                        </div>
                                    </label>
                                </li>
                                <li>
                                    <label class="dropdown-item d-flex align-items-center justify-content-between px-3 py-1 mb-0 user-select-none" style="cursor:pointer;">
                                        <span class="small">Datum</span>
                                        <div class="form-check form-switch mb-0 ps-0">
                                            <input class="form-check-input ms-0 mc-col-toggle" type="checkbox" data-col="date" checked role="switch">
                                        </div>
                                    </label>
                                </li>
                                <li>
                                    <label class="dropdown-item d-flex align-items-center justify-content-between px-3 py-1 mb-0 user-select-none" style="cursor:pointer;">
                                        <span class="small">Größe</span>
                                        <div class="form-check form-switch mb-0 ps-0">
                                            <input class="form-check-input ms-0 mc-col-toggle" type="checkbox" data-col="size" role="switch">
                                        </div>
                                    </label>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Spalten-Kopfzeile -->
                <div class="mc-list-cols-header" id="mc-list-cols-header"></div>

                <div class="mc-message-list" id="mc-message-list">
                    <div class="mc-preview-empty py-5">
                        <i class="bi bi-folder2-open mc-preview-empty-icon"></i>
                        <div class="text-secondary small">Ordner in der Sidebar auswählen</div>
                    </div>
                </div>
                <!-- Pagination Bar -->
                <div class="mc-pagination-bar d-none" id="mc-pagination-bar">
                    <div class="mc-pagination-info text-secondary small">
                        <span class="mc-page-range">0–0</span> von <span class="mc-page-total">0</span> Mails
                    </div>
                    <div class="mc-pagination-controls btn-group btn-group-sm">
                        <button type="button" class="btn btn-outline-secondary mc-page-btn mc-page-first" title="Erste Seite" aria-label="Erste Seite">
                            <i class="bi bi-chevron-double-left"></i>
                        </button>
                        <button type="button" class="btn btn-outline-secondary mc-page-btn mc-page-prev" title="Vorherige Seite" aria-label="Vorherige Seite">
                            <i class="bi bi-chevron-left"></i>
                        </button>
                        <div class="mc-page-input-wrap d-flex align-items-center px-2">
                            <span class="small me-1 text-secondary">Seite</span>
                            <input type="number" class="form-control form-control-sm mc-page-input" min="1" max="1" value="1">
                            <span class="small ms-1 text-secondary">von <span class="mc-page-max">1</span></span>
                        </div>
                        <button type="button" class="btn btn-outline-secondary mc-page-btn mc-page-next" title="Nächste Seite" aria-label="Nächste Seite">
                            <i class="bi bi-chevron-right"></i>
                        </button>
                        <button type="button" class="btn btn-outline-secondary mc-page-btn mc-page-last" title="Letzte Seite" aria-label="Letzte Seite">
                            <i class="bi bi-chevron-double-right"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Horizontaler Resizer zwischen Liste und Mail-Vorschau -->
            <div class="mc-list-resizer" id="mc-list-resizer" title="Größe anpassen (ziehen)"></div>

            <!-- ─ Unterer Teil: Vorschau (UNTER der Liste / im 3-Spalten-Layout: rechte Spalte) ─ -->
            <div class="mc-preview-pane" id="mc-preview-pane">
                <!-- Mobile: Schließen-Button -->
                <div class="d-lg-none d-flex justify-content-end p-1 border-bottom">
                    <button class="btn btn-sm btn-outline-secondary" onclick="mailClientApp.closePreview()">
                        <i class="bi bi-x-lg"></i> Schließen
                    </button>
                </div>

                <div class="mc-preview-scroll-wrapper" id="mc-preview-scroll-wrapper">
                    <div class="mc-preview-header-wrap" id="mc-preview-header-wrap">
                        <div class="mc-preview-header d-none"></div>
                        <div class="mc-preview-actions d-none"></div>
                    </div>
                    <div class="mc-preview-body">
                        <div class="mc-preview-empty">
                            <i class="bi bi-envelope mc-preview-empty-icon"></i>
                            <div>Nachricht auswählen um sie hier anzuzeigen</div>
                            <div class="text-secondary small">Doppelklick öffnet im Vollbild (neuer Tab)</div>
                        </div>
                    </div>
                </div>
            </div>

            </div><!-- /.mc-main-content -->

            <!-- Statuszeile am unteren Rand unter der Mail-Liste und Mail-Vorschau -->
            <div id="mc-sync-status-bar" class="mc-sync-bar mc-sync-hidden"></div>

        </div><!-- /.mc-main-area -->

        <!-- Rechte Kalender-Sidebar -->
        <div class="mc-calendar-resizer" id="mc-calendar-resizer" style="display:none;" title="Größe anpassen (ziehen)"></div>
        <div class="mc-calendar-sidebar" id="mc-calendar-sidebar" style="display:none;">
            <div class="mc-cal-header d-flex align-items-center justify-content-between p-2 border-bottom">
                <div class="d-flex align-items-baseline gap-2">
                    <span class="mc-cal-today-num fw-bold fs-3 text-primary" id="mc-cal-today-num">20</span>
                    <div>
                        <div class="fw-semibold small lh-1" id="mc-cal-today-weekday">So</div>
                        <div class="text-secondary" style="font-size:0.75rem;" id="mc-cal-today-sub">Sept. 2026 KW 38</div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" id="mc-cal-refresh" title="Termine aktualisieren">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                    <a href="/calendar" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:0.78rem;" title="Kalender öffnen">
                        <i class="bi bi-box-arrow-up-right"></i>
                    </a>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" id="mc-cal-close" title="Leiste schließen">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
            </div>
            <div class="p-2 border-bottom d-flex justify-content-between align-items-center bg-body-tertiary">
                <span class="small fw-bold text-secondary text-uppercase" style="font-size:0.72rem;letter-spacing:0.5px;">Termine</span>
                <a href="/calendar" class="btn btn-link btn-sm p-0 text-decoration-none small" style="font-size:0.78rem;">
                    <i class="bi bi-plus-circle me-1"></i>Neuer Termin
                </a>
            </div>
            <div class="mc-cal-events-list flex-grow-1 overflow-auto p-2" id="mc-cal-events-list">
                <div class="text-center text-secondary small py-4">Lade Termine…</div>
            </div>
        </div>

    </div><!-- /.mc-panes -->
    <?php endif; ?>

    <!-- Modal: Termin-Details (Overlay) -->
    <div class="modal fade" id="mcAppointmentModal" tabindex="-1" aria-labelledby="mcAppointmentModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow border-0">
                <div class="modal-header py-2 px-3 border-bottom">
                    <div class="d-flex align-items-center gap-2 overflow-hidden me-2">
                        <span id="mcAptModalDot" class="rounded-circle flex-shrink-0" style="width: 12px; height: 12px; background-color: #0d6efd;"></span>
                        <h5 class="modal-title fs-6 fw-bold text-truncate mb-0" id="mcAppointmentModalLabel">Termin-Details</h5>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body p-3">
                    <!-- Titel -->
                    <div class="mb-3">
                        <div class="fs-5 fw-bold" id="mcAptModalTitle"></div>
                        <div class="text-secondary small mt-1 d-flex align-items-center gap-1" id="mcAptModalCalendarWrap">
                            <i class="bi bi-calendar3"></i>
                            <span id="mcAptModalCalendar">Kalender</span>
                        </div>
                    </div>

                    <!-- Zeit & Datum -->
                    <div class="p-2 mb-3 rounded bg-body-tertiary border d-flex gap-3 align-items-start">
                        <i class="bi bi-clock-fill text-primary fs-5 mt-1"></i>
                        <div class="flex-grow-1">
                            <div class="fw-semibold" id="mcAptModalDate"></div>
                            <div class="text-secondary small" id="mcAptModalTime"></div>
                            <div class="badge text-bg-light border text-secondary mt-1 small" id="mcAptModalRecurrence" style="display:none;">
                                <i class="bi bi-arrow-repeat me-1 text-primary"></i><span></span>
                            </div>
                        </div>
                    </div>

                    <!-- Ort -->
                    <div class="mb-3 d-flex gap-2 align-items-start" id="mcAptModalLocationWrap">
                        <i class="bi bi-geo-alt-fill text-danger fs-5 mt-0"></i>
                        <div>
                            <div class="small text-secondary fw-semibold">Ort</div>
                            <div id="mcAptModalLocation" class="small"></div>
                        </div>
                    </div>

                    <!-- Beschreibung -->
                    <div class="mb-2" id="mcAptModalDescWrap">
                        <div class="small text-secondary fw-semibold mb-1">
                            <i class="bi bi-text-paragraph me-1"></i>Beschreibung
                        </div>
                        <div class="p-2 rounded bg-body-tertiary small text-body" id="mcAptModalDesc" style="white-space: pre-wrap; max-height: 180px; overflow-y: auto;"></div>
                    </div>
                </div>
                <div class="modal-footer py-2 px-3 bg-body-tertiary d-flex justify-content-between">
                    <a href="/calendar" target="_blank" class="btn btn-sm btn-outline-primary" id="mcAptModalEditBtn">
                        <i class="bi bi-pencil-square me-1"></i>Im Kalender bearbeiten
                    </a>
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Schließen</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Termin in Kalender eintragen / bearbeiten -->
    <div class="modal fade" id="mcCalendarImportModal" tabindex="-1" aria-labelledby="mcCalendarImportModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow border-0">
                <div class="modal-header py-2 px-3 border-bottom">
                    <div class="d-flex align-items-center gap-2 overflow-hidden">
                        <span class="rounded-circle p-1 d-inline-flex align-items-center justify-content-center bg-primary-subtle text-primary" style="width:28px;height:28px;">
                            <i class="bi bi-calendar-plus"></i>
                        </span>
                        <h5 class="modal-title fs-6 fw-bold text-truncate mb-0" id="mcCalendarImportModalLabel">Termin in Kalender eintragen</h5>
                    </div>
                    <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <form id="mc-cal-import-form" onsubmit="event.preventDefault(); mailClientApp.submitCalendarImport();">
                    <div class="modal-body p-3">
                        <div class="mb-3">
                            <label for="mc-import-cal-select" class="form-label small fw-semibold mb-1">
                                <i class="bi bi-calendar3 me-1 text-primary"></i>Ziel-Kalender
                            </label>
                            <select class="form-select form-select-sm" id="mc-import-cal-select" required>
                                <option value="0">Standard-Kalender</option>
                            </select>
                            <div class="form-text small" style="font-size:0.75rem;">Wählen Sie den Kalender (z. B. Google Kalender oder lokaler Kalender).</div>
                        </div>

                        <div class="mb-3">
                            <label for="mc-import-cal-title" class="form-label small fw-semibold mb-1">Titel des Termins</label>
                            <input type="text" class="form-control form-control-sm" id="mc-import-cal-title" required>
                        </div>

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="mc-import-cal-allday">
                            <label class="form-check-label small" for="mc-import-cal-allday">Ganztägiger Termin</label>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label for="mc-import-cal-start" class="form-label small fw-semibold mb-1">Beginn</label>
                                <input type="datetime-local" class="form-control form-control-sm" id="mc-import-cal-start" required>
                            </div>
                            <div class="col-6">
                                <label for="mc-import-cal-end" class="form-label small fw-semibold mb-1">Ende</label>
                                <input type="datetime-local" class="form-control form-control-sm" id="mc-import-cal-end">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="mc-import-cal-location" class="form-label small fw-semibold mb-1">Ort / Konferenzlink</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text"><i class="bi bi-geo-alt"></i></span>
                                <input type="text" class="form-control" id="mc-import-cal-location" placeholder="Optionaler Ort oder Link">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="mc-import-cal-description" class="form-label small fw-semibold mb-1">Beschreibung / Notizen</label>
                            <textarea class="form-control form-control-sm" id="mc-import-cal-description" rows="3" placeholder="Zusätzliche Notizen oder Beschreibung"></textarea>
                        </div>

                        <div id="mc-import-cal-rrule-wrap" class="d-none alert alert-info py-1 px-2 small mb-0 d-flex align-items-center gap-2" style="font-size:0.78rem;">
                            <i class="bi bi-repeat"></i>
                            <span>Wiederholung: <strong id="mc-import-cal-rrule-text"></strong></span>
                        </div>

                        <input type="hidden" id="mc-import-cal-rrule" value="">
                        <input type="hidden" id="mc-import-cal-event-idx" value="">
                    </div>
                    <div class="modal-footer py-2 px-3 bg-body-tertiary d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-sm btn-primary" id="mc-import-cal-btn-submit">
                            <i class="bi bi-calendar-plus me-1"></i> In Kalender speichern
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Mail-Client Einstellungen -->
    <div class="modal fade" id="mcSettingsModal" tabindex="-1" aria-labelledby="mcSettingsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow">
                <div class="modal-header py-2 px-3">
                    <h5 class="modal-title fs-6" id="mcSettingsModalLabel">
                        <i class="bi bi-sliders me-1 text-primary"></i> Mail-Client Einstellungen
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body p-3">
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-uppercase text-secondary mb-2">Fensteraufteilung (Layout)</label>
                        <div class="d-flex flex-column gap-2">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="mcSettingLayout" id="mcLayout3Col" value="3col">
                                <label class="form-check-label small" for="mcLayout3Col">
                                    <strong>3-Spalten-Ansicht</strong> (Ordner | Liste | Nachricht nebeneinander – wie Thunderbird)
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="mcSettingLayout" id="mcLayoutSplit" value="split">
                                <label class="form-check-label small" for="mcLayoutSplit">
                                    <strong>Geteilte Ansicht</strong> (Ordner links | Liste oben & Nachricht unten)
                                </label>
                            </div>
                        </div>
                    </div>

                    <hr class="my-3 text-secondary opacity-25">

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-uppercase text-secondary mb-2">Nachrichten-Listenstil</label>
                        <div class="d-flex flex-column gap-2">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="mcSettingListMode" id="mcListModeCards" value="cards">
                                <label class="form-check-label small" for="mcListModeCards">
                                    <strong>Zweizeiliges Layout (Kompakt / Thunderbird-Stil)</strong><br>
                                    <span class="text-secondary" style="font-size:0.8rem;">Zeile 1: Stern, Ungelesen, Absender, Datum – Zeile 2: Betreff & Empfänger</span>
                                </label>
                            </div>
                            <div class="ms-4 mb-1">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="mcSettingShowRecipient" checked role="switch">
                                    <label class="form-check-label small" for="mcSettingShowRecipient">
                                        Empfänger (<code>An: …</code>) in der Liste anzeigen
                                    </label>
                                </div>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="mcSettingListMode" id="mcListModeTable" value="table">
                                <label class="form-check-label small" for="mcListModeTable">
                                    <strong>Klassische Spalten-Tabelle</strong><br>
                                    <span class="text-secondary" style="font-size:0.8rem;">Mehrspaltige Tabelle mit anpassbaren Spalten</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <hr class="my-3 text-secondary opacity-25">

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-uppercase text-secondary mb-2" for="mcSettingAutoRefresh">Automatisch neue E-Mails abrufen</label>
                        <select class="form-select form-select-sm" id="mcSettingAutoRefresh">
                            <option value="0">Deaktiviert (Nur manuell)</option>
                            <option value="60">Jede Minute</option>
                            <option value="120">Alle 2 Minuten (Standard)</option>
                            <option value="300">Alle 5 Minuten</option>
                            <option value="600">Alle 10 Minuten</option>
                        </select>
                        <div class="form-text text-secondary" style="font-size:0.75rem;">
                            Aktualisiert den aktuell geöffneten Ordner automatisch im Hintergrund.
                        </div>
                    </div>

                    <hr class="my-3 text-secondary opacity-25">

                    <div class="mb-1">
                        <label class="form-label fw-bold small text-uppercase text-secondary mb-2">Kalender-Seitenleiste</label>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="mcSettingShowCalendar" role="switch">
                            <label class="form-check-label small" for="mcSettingShowCalendar">
                                Termine-Leiste rechts standardmäßig anzeigen
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-2 px-3">
                    <button type="button" class="btn btn-sm btn-primary" data-bs-dismiss="modal">Fertig</button>
                </div>
            </div>
        </div>
    </div>
</div><!-- /.mc-app -->

<!-- CSRF-Token für AJAX -->
<span data-csrf="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>" style="display:none;"></span>
<input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

<script><?php require __DIR__ . '/../assets/js/mail-client.js'; ?></script>

<script>
// Persistierte Ordner-Selektion und Expand-State laden/speichern
(function() {
    const STORAGE_KEY = 'mailClientExpandedFolders';
    try {
        const saved = localStorage.getItem(STORAGE_KEY);
        if (saved) {
            const expanded = JSON.parse(saved);
            if (Array.isArray(expanded)) {
                expanded.forEach(function(accId) {
                    const header = document.querySelector('.mc-account-header[data-account="' + accId + '"]');
                    if (header) {
                        const group = header.closest('.mc-account-group');
                        const list = header.nextElementSibling;
                        if (group) group.classList.remove('mc-account-collapsed');
                        if (list && list.classList.contains('d-none')) list.classList.remove('d-none');
                    }
                });
            }
        }
    } catch(e) {}
    
    window.mcSaveExpandedFolders = function() {
        const expanded = [];
        document.querySelectorAll('.mc-account-header:not(.mc-account-collapsed)')
            .forEach(function(h) {
                const accId = h.dataset.account;
                if (accId) expanded.push(accId);
            });
        try { localStorage.setItem(STORAGE_KEY, JSON.stringify(expanded)); } catch(e) {}
    };
    
    // Beim Klicken auf Account-Header speichern
    document.querySelectorAll('.mc-account-header[data-account]').forEach(function(el) {
        el.addEventListener('click', function() {
            setTimeout(window.mcSaveExpandedFolders, 50);
        });
    });
})();

// Decodiert modifiziertes UTF-7 (RFC 3501 IMAP) in UTF-8
function mcDecodeUtf7Imap(str) {
    if (!str || typeof str !== 'string') return str || '';
    return str.replace(/&([A-Za-z0-9+,]*)-/g, function(match, b64) {
        if (b64 === '') return '&';
        let stdB64 = b64.replace(/,/g, '/');
        while (stdB64.length % 4 !== 0) stdB64 += '=';
        try {
            const binary = atob(stdB64);
            const bytes = new Uint8Array(binary.length);
            for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
            return new TextDecoder('utf-16be').decode(bytes);
        } catch (e) {
            return match;
        }
    });
}
window.mcDecodeUtf7Imap = mcDecodeUtf7Imap;

// Baut eine flache Baumstruktur mit Ebenen (level), Vorfahren (parent) und Unterordner-Flag
function mcBuildFolderTree(folders, delimiter) {
    let sep = delimiter || '/';
    if (!folders.some(f => f.name.includes(sep))) {
        if (folders.some(f => f.name.includes('.'))) sep = '.';
    }

    const folderMap = new Map();
    folders.forEach(f => {
        const decoded = mcDecodeUtf7Imap(f.display_name || f.name);
        folderMap.set(f.name, {
            ...f,
            decodedName: decoded,
            leafName: decoded,
            children: [],
            hasChildren: false,
            parentName: null,
            level: 0,
            isVirtual: false,
        });
    });

    folderMap.forEach((node, rawName) => {
        const lastSep = rawName.lastIndexOf(sep);
        if (lastSep > 0) {
            const parentName = rawName.substring(0, lastSep);
            const leafRaw = rawName.substring(lastSep + 1);
            node.parentName = parentName;
            node.leafName = mcDecodeUtf7Imap(leafRaw);

            if (!folderMap.has(parentName)) {
                const parentLeafRaw = parentName.split(sep).pop();
                const parentLeafDecoded = mcDecodeUtf7Imap(parentLeafRaw);
                const virtualNode = {
                    name: parentName,
                    display_name: parentLeafDecoded,
                    decodedName: parentLeafDecoded,
                    leafName: parentLeafDecoded,
                    special: 'folder',
                    unseen: 0,
                    total: 0,
                    children: [],
                    hasChildren: true,
                    parentName: parentName.lastIndexOf(sep) > 0 ? parentName.substring(0, parentName.lastIndexOf(sep)) : null,
                    level: 0,
                    isVirtual: true,
                };
                folderMap.set(parentName, virtualNode);
            }
            const parentNode = folderMap.get(parentName);
            parentNode.children.push(node);
            parentNode.hasChildren = true;
        }
    });

    const roots = [];
    folderMap.forEach((node) => {
        if (!node.parentName || !folderMap.has(node.parentName)) {
            roots.push(node);
        }
    });

    const specialOrder = { inbox: 1, sent: 2, drafts: 3, archive: 4, spam: 5, trash: 6, folder: 99 };
    function sortNodes(nodes) {
        nodes.sort((a, b) => {
            const orderA = specialOrder[a.special] || 99;
            const orderB = specialOrder[b.special] || 99;
            if (orderA !== orderB) return orderA - orderB;
            return a.leafName.localeCompare(b.leafName, 'de', { sensitivity: 'base' });
        });
    }

    const flatList = [];
    function traverse(nodes, level) {
        sortNodes(nodes);
        for (const n of nodes) {
            n.level = level;
            n.hasChildren = n.children.length > 0;
            flatList.push(n);
            if (n.hasChildren) {
                traverse(n.children, level + 1);
            }
        }
    }
    traverse(roots, 0);

    return { flatList, sep };
}

// Aktualisiert Sichtbarkeit (mc-folder-hidden) und Pfeil-Icons in einer Ordnerliste
function mcRefreshTreeVisibility(listEl, collapsedSet) {
    const items = listEl.querySelectorAll('.mc-folder-item[data-folder]:not([data-folder-load])');
    const itemMap = new Map();
    items.forEach(el => {
        itemMap.set(el.dataset.folder, {
            el: el,
            parent: el.dataset.parent || '',
            hasChildren: el.dataset.hasChildren === 'true',
        });
    });

    items.forEach(el => {
        const folder = el.dataset.folder;
        const info = itemMap.get(folder);
        if (!info) return;

        if (info.hasChildren) {
            const isCollapsed = collapsedSet.has(folder);
            const toggleIcon = el.querySelector('.mc-folder-toggle i');
            if (toggleIcon) {
                toggleIcon.className = 'bi ' + (isCollapsed ? 'bi-chevron-right' : 'bi-chevron-down');
            }
        }

        let isHidden = false;
        let p = info.parent;
        while (p) {
            if (collapsedSet.has(p)) {
                isHidden = true;
                break;
            }
            p = itemMap.get(p)?.parent || '';
        }

        if (isHidden) {
            el.classList.add('mc-folder-hidden');
        } else {
            el.classList.remove('mc-folder-hidden');
        }
    });
}

function mcToggleFolderBranch(accountId, folderName, listEl) {
    const storageKey = 'mc_collapsed_folders_' + accountId;
    let collapsed = [];
    try {
        collapsed = JSON.parse(localStorage.getItem(storageKey) || '[]');
        if (!Array.isArray(collapsed)) collapsed = [];
    } catch(e) { collapsed = []; }

    const set = new Set(collapsed);
    if (set.has(folderName)) {
        set.delete(folderName);
    } else {
        set.add(folderName);
    }
    try {
        localStorage.setItem(storageKey, JSON.stringify(Array.from(set)));
    } catch(e) {}

    mcRefreshTreeVisibility(listEl, set);
}

// Ordner per AJAX laden und in der Sidebar als Baumstruktur rendern
mailClientApp.loadFolders = async function(accountId, triggerEl) {
    const list = triggerEl?.closest('ul');
    if (!list) return;
    triggerEl.innerHTML = '<li class="mc-folder-item text-secondary" style="font-size:0.78rem;padding-left:0.5rem;"><span class="mc-folder-toggle-spacer"></span><span class="spinner-border spinner-border-sm me-1"></span>Laden…</li>';
    try {
        const data = await fetch(`/mail-client/api/folders?account=${accountId}`, {
            headers: { 'Accept': 'application/json', 'X-CSRF-Token': document.querySelector('[data-csrf]')?.dataset.csrf || '' },
            credentials: 'same-origin',
        }).then(r => r.json());

        const icons = {
            inbox:   'bi-inbox',
            sent:    'bi-send',
            drafts:  'bi-pencil-square',
            trash:   'bi-trash',
            spam:    'bi-shield-exclamation',
            archive: 'bi-archive',
            folder:  'bi-folder'
        };

        function translateFolder(name, special) {
            if (special === 'inbox' || name.toUpperCase() === 'INBOX') return 'Posteingang';
            const decoded = mcDecodeUtf7Imap(name);
            const lower = decoded.toLowerCase();
            const base = lower.split(/[./\\]/).pop();
            if (special === 'sent' || ['sent', 'sent items', 'gesendet', 'sent-mail', 'gesendete objekte'].includes(base)) return 'Gesendet';
            if (special === 'drafts' || ['drafts', 'draft', 'entwÃ¼rfe', 'entwuerfe'].includes(base)) return 'EntwÃ¼rfe';
            if (special === 'trash' || ['trash', 'deleted', 'papierkorb', 'gelÃ¶schte elemente', 'deleted items', 'bin'].includes(base)) return 'Papierkorb';
            if (special === 'spam' || ['spam', 'junk', 'junk e-mail', 'unerwÃ¼nscht', 'spamverdacht'].includes(base)) return 'Spam';
            if (special === 'archive' || ['archive', 'archiv', 'archives'].includes(base)) return 'Archiv';
            return decoded;
        }

        var activeFolder = '';
        var activeEl = document.querySelector(`.mc-folder-item[data-account="${accountId}"].active`);
        if (activeEl) activeFolder = activeEl.dataset.folder || '';

        var formatNumber = (typeof window.mcFormatNumber === 'function')
            ? window.mcFormatNumber
            : function(n) { return new Intl.NumberFormat('de-DE').format(n); };

        const { flatList, sep } = mcBuildFolderTree(data.folders || [], data.folders?.[0]?.delimiter || '/');

        // Gespeicherte eingeklappte Ordner laden
        const storageKey = 'mc_collapsed_folders_' + accountId;
        let collapsedArr = [];
        try {
            collapsedArr = JSON.parse(localStorage.getItem(storageKey) || '[]');
            if (!Array.isArray(collapsedArr)) collapsedArr = [];
        } catch(e) { collapsedArr = []; }
        const collapsedSet = new Set(collapsedArr);

        // Sicherstellen, dass aktive Ordner und deren Eltern nicht zugeklappt sind
        if (activeFolder) {
            let curr = flatList.find(f => f.name === activeFolder);
            while (curr && curr.parentName) {
                collapsedSet.delete(curr.parentName);
                curr = flatList.find(f => f.name === curr.parentName);
            }
            try { localStorage.setItem(storageKey, JSON.stringify(Array.from(collapsedSet))); } catch(e) {}
        }

        var folderHtml = flatList.map(function(f) {
            var isActive = (f.name === activeFolder);
            var displayName = f.leafName || translateFolder(f.name, f.special);
            var fullPathDecoded = f.name.split(sep).map(mcDecodeUtf7Imap).join(' / ');
            var iconCls = icons[f.special] || icons.folder;
            var isCollapsed = collapsedSet.has(f.name);
            var paddingLeft = (0.5 + f.level * 1.0) + 'rem';

            var toggleHtml = f.hasChildren
                ? '<button type="button" class="mc-folder-toggle" aria-label="Ordner ein-/ausklappen" title="Unterordner ein-/ausklappen"><i class="bi ' + (isCollapsed ? 'bi-chevron-right' : 'bi-chevron-down') + '"></i></button>'
                : '<span class="mc-folder-toggle-spacer"></span>';

            return '<li class="mc-folder-item' + (isActive ? ' active' : '') + '" ' +
                'data-account="' + accountId + '" ' +
                'data-folder="' + f.name.replace(/"/g, '&quot;') + '" ' +
                'data-parent="' + (f.parentName || '').replace(/"/g, '&quot;') + '" ' +
                'data-level="' + f.level + '" ' +
                'data-has-children="' + (f.hasChildren ? 'true' : 'false') + '" ' +
                'data-display-name="' + displayName.replace(/"/g, '&quot;') + '" ' +
                'style="padding-left:' + paddingLeft + ';" ' +
                'title="' + fullPathDecoded.replace(/"/g, '&quot;') + '">' +
                toggleHtml +
                '<i class="bi ' + iconCls + ' mc-folder-icon"></i>' +
                '<span class="mc-folder-name">' + displayName.replace(/</g, '&lt;') + '</span>' +
                (f.unseen > 0 ? '<span class="mc-folder-unseen">' + formatNumber(f.unseen) + '</span>' : '') +
                '</li>';
        }).join('');

        // Aktualisieren-Button wieder anhÃ¤ngen
        folderHtml += '<li class="mc-folder-item" ' +
            'data-account="' + accountId + '" ' +
            'data-folder-load="true" ' +
            'data-folder="" ' +
            'style="padding-left:0.5rem;" ' +
            'onclick="mailClientApp.loadFolders(' + accountId + ', this)" ' +
            'title="Ordner aktualisieren">' +
            '<span class="mc-folder-toggle-spacer"></span>' +
            '<i class="bi bi-arrow-clockwise mc-folder-icon"></i>' +
            '<span class="mc-folder-name text-secondary" style="font-size:0.78rem;">Ordner aktualisieren…</span>' +
            '</li>';

        list.innerHTML = folderHtml;

        // Initiale Sichtbarkeit gemÃ¤Ã collapsedSet anwenden
        mcRefreshTreeVisibility(list, collapsedSet);

        // Click-Binding aufsetzen
        list.querySelectorAll('.mc-folder-item[data-folder]:not([data-folder-load])').forEach(function(el) {
            // Klick auf den Aufklapp-Pfeil
            var toggleBtn = el.querySelector('.mc-folder-toggle');
            if (toggleBtn) {
                toggleBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    mcToggleFolderBranch(accountId, el.dataset.folder, list);
                });
            }

            // Doppelklick auf Ordnerzeile mit Unterordnern klappt ebenfalls ein/aus
            if (el.dataset.hasChildren === 'true') {
                el.addEventListener('dblclick', function(e) {
                    if (e.target.closest('.mc-folder-toggle')) return;
                    e.preventDefault();
                    mcToggleFolderBranch(accountId, el.dataset.folder, list);
                });
            }

            // Klick auf Ordner zur Auswahl
            el.addEventListener('click', async function(e) {
                if (e.target.closest('.mc-folder-toggle')) return;
                e.preventDefault();
                const folder = el.dataset.folder;
                if (!folder) return;
                document.querySelectorAll('.mc-folder-item').forEach(function(f) { f.classList.remove('active'); });
                el.classList.add('active');
                window.mailClientState = window.mailClientState || {};
                if (typeof window.mcTriggerSync === 'function') {
                    window.mcTriggerSync(accountId, folder);
                }
                el.dispatchEvent(new CustomEvent('mc-folder-select', { bubbles: true, detail: { accountId, folder } }));
            });
        });

        window.mcSaveExpandedFolders();
        try { localStorage.setItem('mc_folders_loaded_' + accountId, '1'); } catch (e) {}
    } catch (err) {
        list.innerHTML = '<li class="mc-folder-item text-danger small" style="padding-left:0.5rem;"><span class="mc-folder-toggle-spacer"></span>' + err.message + '</li>';
    }
};

// Beim Laden automatisch zuvor geladene Ordner wiederherstellen
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.mc-folder-item[data-folder-load="true"]').forEach(function(btn) {
        const accId = btn.dataset.account;
        if (accId && localStorage.getItem('mc_folders_loaded_' + accId) === '1') {
            mailClientApp.loadFolders(parseInt(accId, 10), btn);
        }
    });
});
</script>
