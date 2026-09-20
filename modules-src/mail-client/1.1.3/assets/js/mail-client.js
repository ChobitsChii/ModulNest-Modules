/**
 * Mail-Client – Frontend-Logik
 *
 * Funktionen:
 * - Resizable Panes (Sidebar vertikal trennen, Mail-Liste oben & Vorschau unten horizontal trennen)
 * - AJAX-basierte Ordner-Navigation (kein Seitenneuladen)
 * - AJAX-basiertes Nachrichten-Laden mit vollständiger Seitennavigation (Erste/Vorherige/Eingabe/Nächste/Letzte)
 * - Mail-Vorschau unter der Liste mit eigenem Scrollbalken in sandboxed iframe
 * - Doppelklick → neuer Tab (message-full.php)
 * - Flag-Aktionen (gelesen, markiert, löschen) per AJAX
 * - Absender-Whitelist (externe Bilder freigeben)
 * - Hintergrund-Sync mit Fortschrittsanzeige
 * - Responsive: Sidebar / Vorschau auf Mobile als Overlay
 */

(function () {
    'use strict';

    // ═══════════════════════════════════════════════════════════════════════════
    // State
    // ═══════════════════════════════════════════════════════════════════════════
    const state = {
        accountId: 0,
        folder: '',
        folderDisplayName: '',
        uid: 0,
        page: 1,
        totalPages: 1,
        total: 0,
        limit: 50,
        loading: false,
        csrfToken: '',
        messages: [],
    };

    // ── Progressive Web App (PWA) Initialisierung ───────────────────────────
    let deferredInstallPrompt = null;

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/mail-client/sw.js', { scope: '/mail-client' })
            .then(function(reg) {
                console.log('[Modulon Mail PWA] Service Worker aktiv, Scope:', reg.scope);
            })
            .catch(function(err) {
                console.warn('[Modulon Mail PWA] Service Worker Registrierung fehlgeschlagen:', err);
            });
    }

    window.addEventListener('beforeinstallprompt', function(e) {
        console.log('[Modulon Mail PWA] beforeinstallprompt abgefangen');
        e.preventDefault();
        deferredInstallPrompt = e;
        const btnPopup = document.getElementById('mc-btn-popup');
        if (btnPopup) {
            btnPopup.innerHTML = '<i class="bi bi-box-arrow-in-down"></i><span class="d-none d-xl-inline ms-1">App installieren</span>';
            btnPopup.title = 'Als eigenständige Desktop-App installieren (ohne Browser-Rahmen)';
        }
    });

    window.addEventListener('appinstalled', function() {
        console.log('[Modulon Mail PWA] Erfolgreich installiert');
        deferredInstallPrompt = null;
        const btnPopup = document.getElementById('mc-btn-popup');
        if (btnPopup) {
            btnPopup.innerHTML = '<i class="bi bi-check-circle"></i><span class="d-none d-xl-inline ms-1">App installiert</span>';
            btnPopup.disabled = true;
        }
    });

    // ═══════════════════════════════════════════════════════════════════════════
    // DOM-Referenzen (lazy, nach DOMContentLoaded)
    // ═══════════════════════════════════════════════════════════════════════════
    let $ = {};

    function initDom() {
        $ = {
            app:            document.querySelector('.mc-app'),
            sidebar:        document.querySelector('.mc-sidebar'),
            sidebarResizer: document.querySelector('.mc-sidebar-resizer'),
            listPane:       document.querySelector('.mc-list-pane'),
            listResizer:    document.querySelector('.mc-list-resizer'),
            previewPane:    document.querySelector('.mc-preview-pane'),
            messageList:    document.querySelector('.mc-message-list'),
            listHeader:     document.querySelector('.mc-list-header-title'),
            listUnseenCount:document.querySelector('.mc-list-unseen-count'),
            previewHeader:  document.querySelector('.mc-preview-header'),
            previewActions: document.querySelector('.mc-preview-actions'),
            previewBody:    document.querySelector('.mc-preview-body'),
            paginationBar:  document.querySelector('#mc-pagination-bar'),
            pageRange:      document.querySelector('.mc-page-range'),
            pageTotal:      document.querySelector('.mc-page-total'),
            pageInput:      document.querySelector('.mc-page-input'),
            pageMax:        document.querySelector('.mc-page-max'),
            btnPageFirst:   document.querySelector('.mc-page-first'),
            btnPagePrev:    document.querySelector('.mc-page-prev'),
            btnPageNext:    document.querySelector('.mc-page-next'),
            btnPageLast:    document.querySelector('.mc-page-last'),
            calendarSidebar:document.querySelector('#mc-calendar-sidebar'),
            calendarResizer:document.querySelector('#mc-calendar-resizer'),
            btnCalendar:    document.querySelector('#mc-btn-calendar'),
            btnSettings:    document.querySelector('#mc-btn-settings'),
            csrfInput:      document.querySelector('[name="_csrf"]') || document.querySelector('[data-csrf]'),
        };

        // CSRF-Token aus Seite lesen
        if ($.csrfInput) {
            state.csrfToken = $.csrfInput.value || $.csrfInput.dataset.csrf || '';
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Ordnernamen-Übersetzung
    // ═══════════════════════════════════════════════════════════════════════════
    function getFolderDisplayName(name, special) {
        if (!name) return '';
        if (special === 'inbox' || name.toUpperCase() === 'INBOX') return 'Posteingang';
        const lower = name.toLowerCase();
        const baseName = lower.split(/[./\\]/).pop();
        if (special === 'sent' || ['sent', 'sent items', 'gesendet', 'sent-mail', 'gesendete objekte'].includes(baseName)) return 'Gesendet';
        if (special === 'drafts' || ['drafts', 'draft', 'entwürfe', 'entwuerfe'].includes(baseName)) return 'Entwürfe';
        if (special === 'trash' || ['trash', 'deleted', 'papierkorb', 'gelöschte elemente', 'deleted items', 'bin'].includes(baseName)) return 'Papierkorb';
        if (special === 'spam' || ['spam', 'junk', 'junk e-mail', 'unerwünscht', 'spamverdacht'].includes(baseName)) return 'Spam';
        if (special === 'archive' || ['archive', 'archiv', 'archives'].includes(baseName)) return 'Archiv';
        return name;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Resize-Logik (Sidebar Breite & Liste/Vorschau Höhe)
    // ═══════════════════════════════════════════════════════════════════════════
    // ═════════════════════════════════════════════════════════════════════════════
    // Spalten-Verwaltung (Status, Sender, Recipient, Subject, Date, Size)
    // ═════════════════════════════════════════════════════════════════════════════
    const DEFAULT_COLS = {
        status: true,
        sender: true,
        recipient: true,
        subject: true,
        date: true,
        size: false,
    };
    const STORAGE_KEY_COLS = 'mailClientVisibleColumns';

    function getVisibleColumns() {
        try {
            const saved = localStorage.getItem(STORAGE_KEY_COLS);
            if (saved) {
                return Object.assign({}, DEFAULT_COLS, JSON.parse(saved));
            }
        } catch (_) {}
        return Object.assign({}, DEFAULT_COLS);
    }

    function saveVisibleColumns(cols) {
        try {
            localStorage.setItem(STORAGE_KEY_COLS, JSON.stringify(cols));
        } catch (_) {}
    }

    function renderColsHeader() {
        const hdr = document.getElementById('mc-list-cols-header');
        if (!hdr) return;
        const cols = getVisibleColumns();
        let html = '';
        if (cols.status) {
            html += '<div class="mc-col-hdr mc-col-hdr-status" title="Status"><i class="bi bi-circle-fill" style="font-size:7px;opacity:0.6;"></i></div>';
        }
        if (cols.sender) {
            html += '<div class="mc-col-hdr mc-col-hdr-sender">Von</div>';
        }
        if (cols.recipient) {
            html += '<div class="mc-col-hdr mc-col-hdr-recipient">An</div>';
        }
        if (cols.subject) {
            html += '<div class="mc-col-hdr mc-col-hdr-subject">Betreff</div>';
        }
        if (cols.date) {
            html += '<div class="mc-col-hdr mc-col-hdr-date">Datum</div>';
        }
        if (cols.size) {
            html += '<div class="mc-col-hdr mc-col-hdr-size">Größe</div>';
        }
        hdr.innerHTML = html;
    }

    function initColumnToggles() {
        const cols = getVisibleColumns();
        document.querySelectorAll('.mc-col-toggle').forEach(input => {
            const col = input.dataset.col;
            if (col && col in cols) {
                input.checked = !!cols[col];
            }
            input.addEventListener('change', () => {
                cols[col] = input.checked;
                saveVisibleColumns(cols);
                renderColsHeader();
                if (state.messages && state.messages.length > 0) {
                    renderMessages(state.messages);
                }
            });
        });
        renderColsHeader();
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Einstellungen (Settings): Layout, Listenmodus, Empfänger, Kalender
    // ═════════════════════════════════════════════════════════════════════════
    const SETTINGS_KEY = 'mc_settings';
    const defaultSettings = {
        layout: '3col',              // '3col' (Thunderbird 3 Spalten) | 'split' (Klassisch geteilt)
        listMode: 'cards',           // 'cards' (Zweizeilig kompakt) | 'table' (Mehrspaltige Tabelle)
        showRecipient: true,         // Empfänger (An: ...) in der zweizeiligen Liste anzeigen
        showCalendar: false,         // Kalender-Leiste rechts standardmäßig anzeigen
    };

    function loadSettings() {
        try {
            const raw = localStorage.getItem(SETTINGS_KEY);
            if (raw) {
                return Object.assign({}, defaultSettings, JSON.parse(raw));
            }
        } catch (_) {}
        return Object.assign({}, defaultSettings);
    }

    function saveSettings(s) {
        try {
            localStorage.setItem(SETTINGS_KEY, JSON.stringify(s));
        } catch (_) {}
    }

    let settings = loadSettings();

    function applySettings(newSettings) {
        if (newSettings) {
            settings = Object.assign(settings, newSettings);
            saveSettings(settings);
        }

        if (!$.app) return;

        // 1. Layout-Klassen
        if (settings.layout === '3col') {
            $.app.classList.add('mc-layout-3col');
            $.app.classList.remove('mc-layout-split');
            if ($.listPane) $.listPane.style.height = '';
        } else {
            $.app.classList.add('mc-layout-split');
            $.app.classList.remove('mc-layout-3col');
            if ($.listPane) $.listPane.style.width = '';
        }

        // 2. Listen-Modus Klassen
        if (settings.listMode === 'cards') {
            $.app.classList.add('mc-listmode-cards');
            $.app.classList.remove('mc-listmode-table');
        } else {
            $.app.classList.add('mc-listmode-table');
            $.app.classList.remove('mc-listmode-cards');
        }

        // 3. Kalender-Sidebar Visibility
        toggleCalendarSidebar(Boolean(settings.showCalendar), false);

        // 4. Modal UI synchronisieren
        syncSettingsModalUI();

        // 5. Resizers aktualisieren
        initResizers();

        // 6. Wenn Nachrichten geladen sind, sofort neu zeichnen
        if (state.messages && state.messages.length > 0) {
            renderMessages(state.messages);
        }

        // 7. Preview neu ausrichten / Iframe anpassen
        window.dispatchEvent(new Event('resize'));
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Kalender-Sidebar (Termine rechts)
    // ═════════════════════════════════════════════════════════════════════════
    let calendarLoaded = false;

    function toggleCalendarSidebar(show, save = true) {
        if (!$.calendarSidebar) return;
        const isVisible = show !== undefined ? show : ($.calendarSidebar.style.display === 'none');
        $.calendarSidebar.style.display = isVisible ? 'flex' : 'none';
        if ($.calendarResizer) {
            $.calendarResizer.style.display = isVisible ? 'block' : 'none';
        }
        if ($.btnCalendar) {
            $.btnCalendar.classList.toggle('active', isVisible);
            $.btnCalendar.classList.toggle('btn-primary', isVisible);
            $.btnCalendar.classList.toggle('btn-outline-secondary', !isVisible);
        }
        if (save) {
            settings.showCalendar = isVisible;
            saveSettings(settings);
            const check = document.getElementById('mcSettingShowCalendar');
            if (check) check.checked = isVisible;
        }
        if (isVisible) {
            loadCalendarUpcoming(true);
        }
    }

    async function loadCalendarUpcoming(force = false) {
        const list = document.getElementById('mc-cal-events-list');
        if (!list) return;
        if (calendarLoaded && !force) return;

        try {
            list.innerHTML = '<div class="text-center text-secondary small py-4"><span class="spinner-border spinner-border-sm me-2"></span>Lade Termine…</div>';
            const data = await apiFetch('/mail-client/api/calendar/upcoming');

            // Header Daten updaten
            if (data.today) {
                const elNum = document.getElementById('mc-cal-today-num');
                const elWd  = document.getElementById('mc-cal-today-weekday');
                const elSub = document.getElementById('mc-cal-today-sub');
                if (elNum) elNum.textContent = data.today.day;
                if (elWd)  elWd.textContent  = data.today.weekday;
                if (elSub) elSub.textContent = `${data.today.month_short} ${data.today.year} KW ${data.today.kw}`;
            }

            if (!data.appointments || data.appointments.length === 0) {
                list.innerHTML = `
                    <div class="text-center text-secondary py-4 px-2">
                        <i class="bi bi-calendar2-check d-block mb-2" style="font-size:2rem;opacity:0.4;"></i>
                        <div class="small fw-semibold">Keine anstehenden Termine</div>
                        <div class="text-secondary small mt-1" style="font-size:0.75rem;">In den nächsten 30 Tagen stehen keine Termine an.</div>
                        <a href="/calendar" class="btn btn-sm btn-outline-primary mt-3" style="font-size:0.78rem;">
                            <i class="bi bi-calendar-plus me-1"></i>Zum Kalender
                        </a>
                    </div>
                `;
                calendarLoaded = true;
                return;
            }

            // Schneller Lookup für Klick-Event
            const aptMap = new Map();
            data.appointments.forEach(apt => {
                aptMap.set(String(apt.id) + '_' + apt.start_at, apt);
            });

            // Gruppieren nach group_title
            const groups = {};
            data.appointments.forEach(apt => {
                const g = apt.group_title || 'Demnächst';
                if (!groups[g]) groups[g] = [];
                groups[g].push(apt);
            });

            let html = '';
            for (const [groupName, items] of Object.entries(groups)) {
                html += `<div class="mc-cal-group-title">${escHtml(groupName)}</div>`;
                items.forEach(apt => {
                    const color = apt.color || '#0d6efd';
                    const key = `${apt.id}_${apt.start_at}`;
                    html += `
                        <div class="mc-cal-item" role="button" tabindex="0" data-apt-key="${escHtml(key)}" title="${escHtml(apt.title)}${apt.location ? ' (' + escHtml(apt.location) + ')' : ''}">
                            <span class="mc-cal-dot" style="background-color: ${escHtml(color)};"></span>
                            <div class="mc-cal-content">
                                <div class="mc-cal-row">
                                    <span class="mc-cal-time">${escHtml(apt.time_str)}</span>
                                    <span class="mc-cal-title text-truncate">${escHtml(apt.title)}</span>
                                </div>
                                ${apt.location ? `<div class="mc-cal-location text-truncate"><i class="bi bi-geo-alt me-1"></i>${escHtml(apt.location)}</div>` : ''}
                            </div>
                        </div>
                    `;
                });
            }
            list.innerHTML = html;
            calendarLoaded = true;

            // Klick & Tastatur auf Termin-Eintrag öffnet Details-Overlay
            list.querySelectorAll('.mc-cal-item').forEach(itemEl => {
                const key = itemEl.getAttribute('data-apt-key');
                const apt = aptMap.get(key);
                if (!apt) return;

                const openHandler = (e) => {
                    e.preventDefault();
                    showAppointmentDetailModal(apt);
                };
                itemEl.addEventListener('click', openHandler);
                itemEl.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        openHandler(e);
                    }
                });
            });
        } catch (err) {
            list.innerHTML = `<div class="alert alert-warning small m-2 p-2">Termine konnten nicht geladen werden: ${escHtml(err.message)}</div>`;
        }
    }

    function showAppointmentDetailModal(apt) {
        const modalEl = document.getElementById('mcAppointmentModal');
        if (!modalEl) return;

        const elTitle    = document.getElementById('mcAptModalTitle');
        const elDot      = document.getElementById('mcAptModalDot');
        const elCal      = document.getElementById('mcAptModalCalendar');
        const elDate     = document.getElementById('mcAptModalDate');
        const elTime     = document.getElementById('mcAptModalTime');
        const elRec      = document.getElementById('mcAptModalRecurrence');
        const elLocWrap  = document.getElementById('mcAptModalLocationWrap');
        const elLoc      = document.getElementById('mcAptModalLocation');
        const elDescWrap = document.getElementById('mcAptModalDescWrap');
        const elDesc     = document.getElementById('mcAptModalDesc');
        const elEditBtn  = document.getElementById('mcAptModalEditBtn');

        const color = apt.color || '#0d6efd';

        if (elDot) elDot.style.backgroundColor = color;
        if (elTitle) elTitle.textContent = apt.title || '(Kein Titel)';
        if (elCal) elCal.textContent = apt.calendar_name || 'Kalender';
        if (elDate) elDate.textContent = apt.group_title || '';
        if (elTime) elTime.textContent = apt.all_day ? 'Ganztägig' : (apt.time_str || '');

        if (elRec) {
            const span = elRec.querySelector('span');
            if (apt.recurrence_text && span) {
                span.textContent = apt.recurrence_text;
                elRec.style.display = 'inline-flex';
            } else {
                elRec.style.display = 'none';
            }
        }

        if (elLocWrap && elLoc) {
            if (apt.location && apt.location.trim() !== '') {
                elLoc.textContent = apt.location;
                elLocWrap.style.display = 'flex';
            } else {
                elLocWrap.style.display = 'none';
            }
        }

        if (elDescWrap && elDesc) {
            if (apt.description && apt.description.trim() !== '') {
                elDesc.textContent = apt.description;
                elDescWrap.style.display = 'block';
            } else {
                elDescWrap.style.display = 'none';
            }
        }

        if (elEditBtn) {
            elEditBtn.href = apt.id ? `/calendar/appointment/${apt.id}/edit` : '/calendar';
        }

        if (window.bootstrap && window.bootstrap.Modal) {
            const modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.show();
        }
    }

    function initResizers() {
        setupResizer($.sidebarResizer, $.sidebar, 'mc-sidebar-width', 160, 420, 'width', false);

        if (settings.layout === '3col') {
            setupResizer($.listResizer, $.listPane, 'mc-list-width-3col', 250, 650, 'width', false);
        } else {
            setupResizer($.listResizer, $.listPane, 'mc-list-height', 130, 700, 'height', true);
        }

        if ($.calendarResizer && $.calendarSidebar) {
            setupResizer($.calendarResizer, $.calendarSidebar, 'mc-cal-width', 220, 450, 'width', false, true);
        }
    }

    function setupResizer(resizer, pane, storageKey, min, max, prop, isVertical, isInverted = false) {
        if (!resizer || !pane) return;

        // Gespeicherte Größe wiederherstellen
        const saved = localStorage.getItem(storageKey);
        if (saved) {
            pane.style[prop] = saved + 'px';
        }

        if (resizer._cleanup) {
            resizer._cleanup();
        }

        let startPos = 0;
        let startSize = 0;
        let dragging = false;

        const onMove = (e) => {
            if (!dragging) return;
            const currentPos = isVertical
                ? (e.touches ? e.touches[0].clientY : e.clientY)
                : (e.touches ? e.touches[0].clientX : e.clientX);
            const delta = currentPos - startPos;
            const newSize = Math.min(max, Math.max(min, isInverted ? (startSize - delta) : (startSize + delta)));
            pane.style[prop] = newSize + 'px';
        };

        const onEnd = () => {
            if (!dragging) return;
            dragging = false;
            resizer.classList.remove('dragging');
            document.body.style.cursor = '';
            document.body.style.userSelect = '';
            localStorage.setItem(storageKey, parseInt(pane.style[prop], 10));
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onEnd);
            document.removeEventListener('touchmove', onMove);
            document.removeEventListener('touchend', onEnd);
        };

        const onStartMouse = (e) => {
            dragging = true;
            startPos = isVertical ? e.clientY : e.clientX;
            startSize = parseInt(pane.style[prop] || getComputedStyle(pane)[prop], 10);
            resizer.classList.add('dragging');
            document.body.style.cursor = isVertical ? 'row-resize' : 'col-resize';
            document.body.style.userSelect = 'none';
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onEnd);
            e.preventDefault();
        };

        const onStartTouch = (e) => {
            dragging = true;
            startPos = isVertical ? e.touches[0].clientY : e.touches[0].clientX;
            startSize = parseInt(pane.style[prop] || getComputedStyle(pane)[prop], 10);
            document.addEventListener('touchmove', onMove, { passive: false });
            document.addEventListener('touchend', onEnd);
        };

        resizer.addEventListener('mousedown', onStartMouse);
        resizer.addEventListener('touchstart', onStartTouch, { passive: true });

        resizer._cleanup = () => {
            resizer.removeEventListener('mousedown', onStartMouse);
            resizer.removeEventListener('touchstart', onStartTouch);
        };
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // API-Helfer
    // ═══════════════════════════════════════════════════════════════════════════
    async function apiFetch(url, opts = {}) {
        const defaults = {
            headers: {
                'Accept': 'application/json',
                'X-CSRF-Token': state.csrfToken,
            },
            credentials: 'same-origin',
        };
        if (opts.body && typeof opts.body === 'object') {
            defaults.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(opts.body);
        }
        const response = await fetch(url, { ...defaults, ...opts, headers: { ...defaults.headers, ...(opts.headers || {}) } });
        if (!response.ok) {
            const data = await response.json().catch(() => ({ error: 'Unbekannter Fehler' }));
            throw new Error(data.error || 'HTTP ' + response.status);
        }
        return response.json();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Ordner-Navigation
    // ═══════════════════════════════════════════════════════════════════════════
    function bindFolderClicks() {
        document.querySelectorAll('.mc-folder-item[data-folder]:not([data-folder-load])').forEach(el => {
            el.addEventListener('click', async (e) => {
                e.preventDefault();
                const accountId = parseInt(el.dataset.account, 10);
                const folder    = el.dataset.folder;
                if (!folder) return;
                if (accountId === state.accountId && folder === state.folder) return;

                // Aktive Markierung setzen
                document.querySelectorAll('.mc-folder-item').forEach(f => f.classList.remove('active'));
                el.classList.add('active');

                state.accountId = accountId;
                state.folder    = folder;
                state.folderDisplayName = el.querySelector('.mc-folder-name')?.textContent || getFolderDisplayName(folder);
                state.page      = 1;
                state.uid       = 0;

                clearPreview();
                if (typeof window.mcTriggerSync === 'function') {
                    window.mcTriggerSync(accountId, folder);
                }
                await loadMessages(true);
            });
        });

        // Account-Gruppe collapse/expand
        document.querySelectorAll('.mc-account-header[data-account]').forEach(el => {
            el.addEventListener('click', () => {
                const accountId = el.dataset.account;
                const list = document.querySelector(`.mc-folder-list[data-account="${accountId}"]`);
                if (list) {
                    const collapsed = list.classList.toggle('d-none');
                    el.closest('.mc-account-group')?.classList.toggle('mc-account-collapsed', collapsed);
                }
            });
        });
    }

    // Event von dynamisch nachgeladenen Ordnern
    document.addEventListener('mc-folder-select', async (e) => {
        const { accountId, folder } = e.detail || {};
        if (!folder) return;
        state.accountId = parseInt(accountId, 10);
        state.folder    = folder;
        const activeEl  = document.querySelector(`.mc-folder-item[data-account="${accountId}"][data-folder="${folder}"]`);
        state.folderDisplayName = activeEl?.querySelector('.mc-folder-name')?.textContent || getFolderDisplayName(folder);
        state.page      = 1;
        state.uid       = 0;
        clearPreview();
        await loadMessages(true);
    });

    async function loadMessages(reset = false) {
        if (!$.messageList || state.loading) return;
        if (state.accountId === 0 || state.folder === '') return;

        if (reset) {
            state.page = 1;
        }

        state.loading = true;
        showListLoading(true);

        try {
            const offset = (state.page - 1) * state.limit;
            const url = `/mail-client/api/messages?account=${state.accountId}&folder=${encodeURIComponent(state.folder)}&limit=${state.limit}&offset=${offset}`;
            const data = await apiFetch(url);

            $.messageList.innerHTML = '';

            // Folder-Header aktualisieren
            if ($.listHeader) {
                $.listHeader.textContent = state.folderDisplayName || getFolderDisplayName(state.folder);
            }
            if ($.listUnseenCount && data.unseen > 0) {
                $.listUnseenCount.textContent = `${mcFormatNumber(data.unseen)} ungelesen`;
                $.listUnseenCount.classList.remove('d-none');
            } else if ($.listUnseenCount) {
                $.listUnseenCount.classList.add('d-none');
            }

            // Ordner-Badge in der Sidebar aktualisieren
            const activeFolderItem = document.querySelector(`.mc-folder-item[data-account="${state.accountId}"][data-folder="${state.folder}"]`);
            if (activeFolderItem) {
                let badge = activeFolderItem.querySelector('.mc-folder-unseen');
                if (data.unseen > 0) {
                    if (!badge) {
                        badge = document.createElement('span');
                        badge.className = 'mc-folder-unseen';
                        activeFolderItem.appendChild(badge);
                    }
                    badge.textContent = mcFormatNumber(data.unseen);
                } else if (badge) {
                    badge.remove();
                }
            }

            state.total = data.total || 0;
            state.totalPages = Math.max(1, Math.ceil(state.total / state.limit));
            state.messages = data.messages || [];

            // Nachrichten rendern
            renderMessages(state.messages);

            updatePagination();
        } catch (err) {
            showListError(err.message);
        } finally {
            state.loading = false;
            showListLoading(false);
        }
    }

    function renderMessages(messages) {
        $.messageList.innerHTML = '';
        if (messages.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'mc-preview-empty py-5';
            empty.innerHTML = `
                <i class="bi bi-inbox mc-preview-empty-icon"></i>
                <div class="text-secondary small">Keine Nachrichten in diesem Ordner</div>
            `;
            $.messageList.appendChild(empty);
            return;
        }

        messages.forEach(msg => {
            const row = createMessageRow(msg);
            $.messageList.appendChild(row);
        });

        // Klick / Doppelklick Binding
        $.messageList.querySelectorAll('.mc-message-row:not([data-bound])').forEach(row => {
            row.dataset.bound = '1';
            row.addEventListener('click', (e) => {
                const starBtn = e.target.closest('[data-action="toggle-star"]');
                if (starBtn) {
                    e.stopPropagation();
                    const uid = parseInt(row.dataset.uid, 10);
                    const accountId = parseInt(row.dataset.account, 10);
                    const folder = row.dataset.folder;
                    const msg = state.messages.find(m => m.uid === uid);
                    const nextFlag = !(msg ? msg.is_flagged : false);
                    toggleMessageFlag(accountId, folder, uid, 'flagged', nextFlag);
                    return;
                }

                const seenBtn = e.target.closest('[data-action="toggle-seen"]');
                if (seenBtn) {
                    e.stopPropagation();
                    const uid = parseInt(row.dataset.uid, 10);
                    const accountId = parseInt(row.dataset.account, 10);
                    const folder = row.dataset.folder;
                    const msg = state.messages.find(m => m.uid === uid);
                    const nextSeen = !(msg ? msg.is_seen : false);
                    toggleMessageFlag(accountId, folder, uid, 'seen', nextSeen);
                    return;
                }

                const uid       = parseInt(row.dataset.uid, 10);
                const accountId = parseInt(row.dataset.account, 10);
                const folder    = row.dataset.folder;
                selectMessage(row, accountId, folder, uid);
            });
            row.addEventListener('dblclick', (e) => {
                if (e.target.closest('button')) return;
                const uid       = parseInt(row.dataset.uid, 10);
                const accountId = parseInt(row.dataset.account, 10);
                const folder    = row.dataset.folder;
                openMessageNewTab(accountId, folder, uid);
            });
        });
    }

    function createMessageCardRow(msg) {
        const card = document.createElement('div');
        card.className = 'mc-message-row mc-message-card' + (msg.is_seen ? '' : ' unread');
        card.dataset.uid     = msg.uid;
        card.dataset.account = state.accountId;
        card.dataset.folder  = state.folder;

        const starClass = msg.is_flagged ? 'bi-star-fill text-warning active' : 'bi-star';
        const showRecipient = Boolean(settings.showRecipient);
        const recipientText = msg.recipient || msg.recipient_name || msg.recipient_address || '';

        card.innerHTML = `
            <div class="mc-card-line-1">
                <div class="d-flex align-items-center gap-1 overflow-hidden" style="min-width:0;flex:1;">
                    <button type="button" class="mc-star-toggle" title="${msg.is_flagged ? 'Markierung aufheben' : 'Mit Stern markieren'}" data-action="toggle-star">
                        <i class="bi ${starClass}"></i>
                    </button>
                    ${msg.is_seen ? '' : '<span class="mc-unread-dot" title="Ungelesen"></span>'}
                    <span class="mc-card-sender text-truncate" title="${escHtml(msg.sender_address || '')}">
                        ${escHtml(msg.sender_name || msg.sender_address || '(Unbekannt)')}
                    </span>
                    ${msg.has_attachments ? '<i class="bi bi-paperclip text-secondary ms-1" title="Hat Anhänge"></i>' : ''}
                </div>
                <div class="mc-card-date text-nowrap ms-2">
                    ${escHtml(msg.date_formatted || '')}
                </div>
            </div>
            <div class="mc-card-line-2 mt-1">
                <div class="mc-card-subject text-truncate" title="${escHtml(msg.subject || '(kein Betreff)')}">
                    ${escHtml(msg.subject || '(kein Betreff)')}
                </div>
                <div class="mc-card-line-2-right d-flex align-items-center gap-2 flex-shrink-0 ms-2">
                    ${showRecipient && recipientText && recipientText !== '-' ? `
                        <span class="mc-card-recipient-badge" title="Empfangen an: ${escHtml(recipientText)}">
                            <i class="bi bi-arrow-return-right"></i> An: ${escHtml(recipientText)}
                        </span>` : ''}
                    <button type="button" class="btn btn-link btn-sm p-0 text-secondary" title="${msg.is_seen ? 'Als ungelesen markieren' : 'Als gelesen markieren'}" data-action="toggle-seen">
                        <i class="bi ${msg.is_seen ? 'bi-envelope-open' : 'bi-envelope'}"></i>
                    </button>
                </div>
            </div>
        `;
        return card;
    }

    function createMessageTableRow(msg) {
        const row = document.createElement('div');
        row.className = 'mc-message-row' + (msg.is_seen ? '' : ' unread');
        row.dataset.uid     = msg.uid;
        row.dataset.account = state.accountId;
        row.dataset.folder  = state.folder;

        const cols = getVisibleColumns();
        const starClass = msg.is_flagged ? 'bi-star-fill text-warning active' : 'bi-star';

        let colsHtml = '';

        if (cols.status) {
            colsHtml += `
                <div class="mc-msg-col-status">
                    <button type="button" class="mc-star-toggle me-1" title="${msg.is_flagged ? 'Markierung aufheben' : 'Mit Stern markieren'}" data-action="toggle-star">
                        <i class="bi ${starClass}"></i>
                    </button>
                    ${msg.is_seen ? '' : '<span class="mc-unread-dot" title="Ungelesen"></span>'}
                    <span class="mc-msg-icons">
                        ${msg.is_answered ? '<i class="bi bi-reply-fill text-primary" title="Beantwortet"></i>' : ''}
                        ${msg.has_attachments ? '<i class="bi bi-paperclip" title="Hat Anhänge"></i>' : ''}
                    </span>
                </div>
            `;
        }
        if (cols.sender) {
            colsHtml += `
                <div class="mc-msg-col-sender" title="${escHtml(msg.sender_address || '')}">
                    ${escHtml(msg.sender_name || msg.sender_address || '(Unbekannt)')}
                </div>
            `;
        }
        if (cols.recipient) {
            const recipientText = msg.recipient || msg.recipient_name || msg.recipient_address || '-';
            colsHtml += `
                <div class="mc-msg-col-recipient" title="${escHtml(msg.recipient_address || recipientText)}">
                    ${escHtml(recipientText)}
                </div>
            `;
        }
        if (cols.subject) {
            colsHtml += `
                <div class="mc-msg-col-subject" title="${escHtml(msg.subject || '(kein Betreff)')}">
                    ${escHtml(msg.subject || '(kein Betreff)')}
                </div>
            `;
        }
        if (cols.date) {
            colsHtml += `
                <div class="mc-msg-col-date">
                    ${escHtml(msg.date_formatted || '')}
                </div>
            `;
        }
        if (cols.size) {
            colsHtml += `
                <div class="mc-msg-col-size">
                    ${escHtml(formatBytes(msg.size_bytes))}
                </div>
            `;
        }

        row.innerHTML = colsHtml;
        return row;
    }

    function createMessageRow(msg) {
        if (settings.listMode === 'cards') {
            return createMessageCardRow(msg);
        }
        return createMessageTableRow(msg);
    }

    function adjustFolderUnseen(accountId, folder, delta) {
        const folderItem = document.querySelector(`.mc-folder-item[data-account="${accountId}"][data-folder="${folder}"]`);
        let badge = folderItem?.querySelector('.mc-folder-unseen');
        let current = badge ? (parseInt(badge.textContent.replace(/\./g, ''), 10) || 0) : 0;
        const next = Math.max(0, current + delta);
        if (next > 0) {
            if (!badge && folderItem) {
                badge = document.createElement('span');
                badge.className = 'mc-folder-unseen';
                folderItem.appendChild(badge);
            }
            if (badge) badge.textContent = mcFormatNumber(next);
        } else if (badge) {
            badge.remove();
        }
        if (state.folder === folder && parseInt(state.accountId, 10) === parseInt(accountId, 10)) {
            state.unseen = next;
            if ($.listUnseenCount) {
                if (next > 0) {
                    $.listUnseenCount.textContent = `${mcFormatNumber(next)} ungelesen`;
                    $.listUnseenCount.classList.remove('d-none');
                } else {
                    $.listUnseenCount.classList.add('d-none');
                }
            }
        }
    }

    async function toggleMessageFlag(accountId, folder, uid, flag, setFlag) {
        const msg = state.messages.find(m => m.uid === uid);
        if (msg) {
            if (flag === 'flagged') msg.is_flagged = setFlag;
            if (flag === 'seen') msg.is_seen = setFlag;
        }

        updateMessageFlagUI(uid, flag, setFlag);
        if (flag === 'seen') {
            adjustFolderUnseen(accountId, folder, setFlag ? -1 : 1);
        }

        try {
            await apiFetch('/mail-client/api/messages/flag', {
                method: 'POST',
                body: { account: accountId, folder: decodeURIComponent(folder), uid, flag, set: setFlag },
            });
        } catch (err) {
            console.error('Flag konnte nicht synchronisiert werden:', err);
            // Rollback
            if (msg) {
                if (flag === 'flagged') msg.is_flagged = !setFlag;
                if (flag === 'seen') msg.is_seen = !setFlag;
            }
            updateMessageFlagUI(uid, flag, !setFlag);
            if (flag === 'seen') {
                adjustFolderUnseen(accountId, folder, setFlag ? 1 : -1);
            }
        }
    }

    function updateMessageFlagUI(uid, flag, setFlag) {
        const rows = document.querySelectorAll(`.mc-message-row[data-uid="${uid}"]`);
        rows.forEach(row => {
            if (flag === 'flagged') {
                const star = row.querySelector('.mc-star-toggle');
                if (star) {
                    star.innerHTML = `<i class="bi ${setFlag ? 'bi-star-fill text-warning active' : 'bi-star'}"></i>`;
                    star.title = setFlag ? 'Markierung aufheben' : 'Mit Stern markieren';
                }
            } else if (flag === 'seen') {
                row.classList.toggle('unread', !setFlag);
                const dot = row.querySelector('.mc-unread-dot');
                if (setFlag && dot) dot.remove();
                if (!setFlag && !dot) {
                    const target = row.querySelector('.mc-card-line-1 .d-flex') || row.querySelector('.mc-msg-col-status');
                    if (target) {
                        const newDot = document.createElement('span');
                        newDot.className = 'mc-unread-dot';
                        newDot.title = 'Ungelesen';
                        target.appendChild(newDot);
                    }
                }
                const seenBtn = row.querySelector('[data-action="toggle-seen"]');
                if (seenBtn) {
                    const icon = seenBtn.querySelector('i');
                    if (icon) {
                        icon.className = 'bi ' + (setFlag ? 'bi-envelope-open' : 'bi-envelope');
                    }
                    seenBtn.title = setFlag ? 'Als ungelesen markieren' : 'Als gelesen markieren';
                }
            }
        });

        // Vorschau-Button synchronisieren falls diese Mail gerade geöffnet ist
        if (state.uid === uid) {
            const previewFlagBtn = $.previewPane?.querySelector('.mc-preview-actions [title="Markieren"], .mc-preview-actions [title="Markierung aufheben"]');
            if (previewFlagBtn) {
                const icon = previewFlagBtn.querySelector('i');
                if (setFlag) {
                    previewFlagBtn.className = 'btn btn-sm btn-outline-warning';
                    if (icon) icon.className = 'bi bi-flag-fill';
                    previewFlagBtn.title = 'Markierung aufheben';
                } else {
                    previewFlagBtn.className = 'btn btn-sm btn-outline-secondary';
                    if (icon) icon.className = 'bi bi-flag';
                    previewFlagBtn.title = 'Markieren';
                }
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    function updatePagination() {
        if (!$.paginationBar) return;
        if (state.total <= 0) {
            $.paginationBar.classList.add('d-none');
            return;
        }
        $.paginationBar.classList.remove('d-none');

        const start = (state.page - 1) * state.limit + 1;
        const end   = Math.min(state.page * state.limit, state.total);

        if ($.pageRange) $.pageRange.textContent = `${mcFormatNumber(start)}–${mcFormatNumber(end)}`;
        if ($.pageTotal) $.pageTotal.textContent = mcFormatNumber(state.total);
        if ($.pageInput) {
            $.pageInput.value = state.page;
            $.pageInput.max   = state.totalPages;
        }
        if ($.pageMax) $.pageMax.textContent = mcFormatNumber(state.totalPages);

        if ($.btnPageFirst) $.btnPageFirst.disabled = (state.page <= 1);
        if ($.btnPagePrev)  $.btnPagePrev.disabled  = (state.page <= 1);
        if ($.btnPageNext)  $.btnPageNext.disabled  = (state.page >= state.totalPages);
        if ($.btnPageLast)  $.btnPageLast.disabled  = (state.page >= state.totalPages);
    }

    function initPaginationEvents() {
        if ($.btnPageFirst) {
            $.btnPageFirst.addEventListener('click', () => goToPage(1));
        }
        if ($.btnPagePrev) {
            $.btnPagePrev.addEventListener('click', () => goToPage(state.page - 1));
        }
        if ($.btnPageNext) {
            $.btnPageNext.addEventListener('click', () => goToPage(state.page + 1));
        }
        if ($.btnPageLast) {
            $.btnPageLast.addEventListener('click', () => goToPage(state.totalPages));
        }
        if ($.pageInput) {
            $.pageInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    let val = parseInt($.pageInput.value, 10);
                    if (isNaN(val)) val = 1;
                    val = Math.max(1, Math.min(state.totalPages, val));
                    goToPage(val);
                }
            });
            $.pageInput.addEventListener('change', () => {
                let val = parseInt($.pageInput.value, 10);
                if (isNaN(val)) val = 1;
                val = Math.max(1, Math.min(state.totalPages, val));
                goToPage(val);
            });
        }
    }

    async function goToPage(page) {
        if (page < 1 || page > state.totalPages) return;
        state.page = page;
        clearPreview();
        await loadMessages(false);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Mail-Vorschau (UNTER der Liste)
    // ═══════════════════════════════════════════════════════════════════════════
    function selectMessage(row, accountId, folder, uid) {
        // Selektierung setzen
        document.querySelectorAll('.mc-message-row.selected').forEach(r => r.classList.remove('selected'));
        row.classList.add('selected');

        if (row.classList.contains('unread')) {
            row.classList.remove('unread');
            row.querySelector('.mc-unread-dot')?.remove();
            const seenBtn = row.querySelector('[data-action="toggle-seen"]');
            if (seenBtn) {
                const icon = seenBtn.querySelector('i');
                if (icon) icon.className = 'bi bi-envelope-open';
                seenBtn.title = 'Als ungelesen markieren';
            }
            adjustFolderUnseen(accountId, folder, -1);
        }

        state.uid = uid;
        loadPreview(accountId, folder, uid);

        // Mobile / Tablet: Vorschau als Overlay anzeigen
        if (window.innerWidth < 992 && $.previewPane) {
            $.previewPane.classList.add('mc-preview-active');
        }
    }

    function openMessageNewTab(accountId, folder, uid) {
        window.open(
            `/mail-client/message?account=${accountId}&folder=${encodeURIComponent(folder)}&uid=${uid}`,
            '_blank',
            'noopener,noreferrer'
        );
    }

    async function loadPreview(accountId, folder, uid) {
        if (!$.previewPane) return;
        showPreviewLoading(true);

        try {
            const url = `/mail-client/api/message-body?account=${accountId}&folder=${encodeURIComponent(folder)}&uid=${uid}`;
            const data = await apiFetch(url);

            renderPreview(data);

            // Ungelesen-Badge in der Liste updaten
            const row = $.messageList?.querySelector(`.mc-message-row[data-uid="${uid}"]`);
            if (row) {
                row.classList.remove('unread');
                row.querySelector('.mc-unread-dot')?.remove();
            }
        } catch (err) {
            renderPreviewError(err.message);
        } finally {
            showPreviewLoading(false);
        }
    }

    function renderPreview(data) {
        if (!$.previewPane) return;

        const scrollWrapper = document.getElementById('mc-preview-scroll-wrapper');
        if (scrollWrapper) {
            scrollWrapper.scrollTop = 0;
        }

        // Pin-State wiederherstellen (Standard: fixiert)
        const isPinned = localStorage.getItem('mc_header_pinned') !== '0';
        if ($.previewPane) {
            $.previewPane.classList.toggle('mc-header-pinned', isPinned);
        }

        // Header
        const header = $.previewPane.querySelector('.mc-preview-header');
        if (header) {
            header.innerHTML = `
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div class="mc-preview-subject flex-grow-1">${escHtml(data.subject || '(kein Betreff)')}</div>
                    <button type="button" class="btn btn-sm btn-link p-1 mc-btn-pin-header" id="mc-btn-pin-header" title="${isPinned ? 'Header lösen (scrollt mit)' : 'Header fixieren (bleibt oben stehen)'}">
                        <i class="bi ${isPinned ? 'bi-pin-angle-fill text-primary' : 'bi-pin-angle'}"></i>
                    </button>
                </div>
                <div class="mc-preview-meta">
                    <div class="mc-preview-meta-row">
                        <span class="mc-preview-meta-label">Von:</span>
                        <span>${escHtml(data.from_name ? `${data.from_name} <${data.from}>` : data.from)}</span>
                    </div>
                    <div class="mc-preview-meta-row">
                        <span class="mc-preview-meta-label">An:</span>
                        <span>${escHtml(data.to || '(Kein Empfänger)')}</span>
                    </div>
                    ${data.cc ? `<div class="mc-preview-meta-row"><span class="mc-preview-meta-label">CC:</span><span>${escHtml(data.cc)}</span></div>` : ''}
                    <div class="mc-preview-meta-row">
                        <span class="mc-preview-meta-label">Datum:</span>
                        <span>${escHtml(data.date)}</span>
                    </div>
                </div>
            `;
            header.classList.remove('d-none');

            const pinBtn = header.querySelector('#mc-btn-pin-header');
            if (pinBtn) {
                pinBtn.onclick = function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const curPinned = $.previewPane.classList.contains('mc-header-pinned');
                    const nextPinned = !curPinned;
                    $.previewPane.classList.toggle('mc-header-pinned', nextPinned);
                    localStorage.setItem('mc_header_pinned', nextPinned ? '1' : '0');
                    pinBtn.innerHTML = `<i class="bi ${nextPinned ? 'bi-pin-angle-fill text-primary' : 'bi-pin-angle'}"></i>`;
                    pinBtn.title = nextPinned ? 'Header lösen (scrollt mit)' : 'Header fixieren (bleibt oben stehen)';
                    if (window._mcApplyPreviewScrollMode) {
                        window._mcApplyPreviewScrollMode();
                    }
                };
            }
        }

        // Aktionen
        const actions = $.previewPane.querySelector('.mc-preview-actions');
        if (actions) {
            actions.innerHTML = `
                <a href="/mail-client/compose?reply_to=${encodeURIComponent(data.from)}&subject=${encodeURIComponent('Re: ' + (data.subject || ''))}&account=${data.account_id}" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-reply"></i> Antworten
                </a>
                <button class="btn btn-sm btn-outline-secondary" onclick="mailClientApp.openFull(${data.account_id}, '${encodeURIComponent(data.folder)}', ${data.uid})">
                    <i class="bi bi-arrows-fullscreen"></i> Vollbild
                </button>
                <button class="btn btn-sm btn-outline-${data.is_flagged ? 'warning' : 'secondary'}" 
                        onclick="mailClientApp.toggleFlag(${data.account_id}, '${encodeURIComponent(data.folder)}', ${data.uid}, 'flagged', this)"
                        title="Markieren">
                    <i class="bi bi-flag${data.is_flagged ? '-fill' : ''}"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger ms-auto" 
                        onclick="mailClientApp.deleteMail(${data.account_id}, '${encodeURIComponent(data.folder)}', ${data.uid})"
                        title="Löschen">
                    <i class="bi bi-trash"></i>
                </button>
            `;
            actions.classList.remove('d-none');
        }

        // Blockierte Bilder Banner
        let blockedBanner = $.previewPane.querySelector('.mc-blocked-images-banner');
        if (data.blocked_images > 0 && !data.allow_external) {
            if (!blockedBanner) {
                blockedBanner = document.createElement('div');
                blockedBanner.className = 'mc-blocked-images-banner';
                $.previewPane.querySelector('.mc-preview-body')?.before(blockedBanner);
            }
            blockedBanner.innerHTML = `
                <i class="bi bi-shield-exclamation text-warning"></i>
                <span>${data.blocked_images} externe Bild(er) blockiert.</span>
                <button class="btn btn-link btn-sm p-0 ms-1" 
                        onclick="mailClientApp.allowExternal('${encodeURIComponent(data.from)}', this)">
                    Absender vertrauen
                </button>
            `;
        } else if (blockedBanner) {
            blockedBanner.remove();
        }

        // Body in sandboxed iframe
        const previewBody = $.previewPane.querySelector('.mc-preview-body');
        if (previewBody) {
            previewBody.innerHTML = '';
            previewBody.scrollTop = 0;
            const iframe = document.createElement('iframe');
            iframe.className = 'mc-preview-iframe';
            iframe.sandbox   = 'allow-same-origin allow-popups';
            iframe.setAttribute('loading', 'lazy');
            previewBody.appendChild(iframe);

            // HTML in iframe schreiben
            const iframeDoc = iframe.contentDocument || iframe.contentWindow?.document;
            if (iframeDoc) {
                iframeDoc.open();
                iframeDoc.write(`<!DOCTYPE html><html><head>
                    <meta charset="UTF-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1">
                    <style>
                        html, body {
                            margin: 0;
                            padding: 0.85rem 1.25rem;
                            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
                            font-size: 14px;
                            line-height: 1.6;
                            color: #212529;
                            word-break: break-word;
                            background: #fff;
                            box-sizing: border-box;
                        }
                        a { color: #0d6efd; }
                        img { max-width: 100%; height: auto; }
                        table { max-width: 100%; }
                        pre { white-space: pre-wrap; word-break: break-all; }

                        /* Elegante, dezente Scrollbar im Iframe passend zum Design */
                        ::-webkit-scrollbar {
                            width: 8px;
                            height: 8px;
                        }
                        ::-webkit-scrollbar-track {
                            background: #f1f5f9;
                        }
                        ::-webkit-scrollbar-thumb {
                            background: #cbd5e1;
                            border-radius: 4px;
                        }
                        ::-webkit-scrollbar-thumb:hover {
                            background: #94a3b8;
                        }
                        * {
                            scrollbar-width: thin;
                            scrollbar-color: #cbd5e1 #f1f5f9;
                        }
                    </style>
                </head><body>${data.html || '<em style="color:#666">(Keine Vorschau verfügbar)</em>'}</body></html>`);
                iframeDoc.close();

                let lastH = 0;
                const updateUnpinnedHeight = () => {
                    const pinned = $.previewPane && $.previewPane.classList.contains('mc-header-pinned');
                    if (pinned) return;
                    try {
                        const curDoc = iframe.contentDocument || iframe.contentWindow?.document;
                        if (!curDoc || !curDoc.body) return;
                        const b = curDoc.body;
                        const d = curDoc.documentElement;

                        b.style.setProperty('height', 'auto', 'important');
                        if (d) d.style.setProperty('height', 'auto', 'important');

                        let maxBottom = 0;
                        if (b.children) {
                            for (let i = 0; i < b.children.length; i++) {
                                const rect = b.children[i].getBoundingClientRect();
                                if (rect.bottom > maxBottom) maxBottom = rect.bottom;
                            }
                        }
                        const needed = Math.max(
                            b.scrollHeight || 0,
                            d?.scrollHeight || 0,
                            b.offsetHeight || 0,
                            Math.ceil(maxBottom),
                            250
                        );
                        if (needed > 0 && Math.abs(needed - lastH) > 4) {
                            lastH = needed;
                            iframe.style.height = (needed + 25) + 'px';
                        }
                    } catch (_) {}
                };

                const applyPreviewScrollMode = () => {
                    const pinned = $.previewPane && $.previewPane.classList.contains('mc-header-pinned');
                    const curDoc = iframe.contentDocument || iframe.contentWindow?.document;
                    if (pinned) {
                        // Fixiert: Iframe nimmt 100% der Resthöhe ein und scrollt selbst -> genau 1 schöner nativer Scrollbalken
                        iframe.style.height = '100%';
                        if (curDoc?.documentElement && curDoc?.body) {
                            curDoc.documentElement.style.setProperty('height', '100%', 'important');
                            curDoc.body.style.setProperty('height', '100%', 'important');
                            curDoc.documentElement.style.overflowY = 'auto';
                            curDoc.body.style.overflowY = 'auto';
                        }
                    } else {
                        // Gelöst: Wrapper scrollt, Iframe hat keinen eigenen Scrollbalken
                        if (curDoc?.documentElement && curDoc?.body) {
                            curDoc.documentElement.style.setProperty('height', 'auto', 'important');
                            curDoc.body.style.setProperty('height', 'auto', 'important');
                            curDoc.documentElement.style.overflowY = 'hidden';
                            curDoc.body.style.overflowY = 'hidden';
                        }
                        lastH = 0;
                        iframe.style.height = '50px';
                        updateUnpinnedHeight();
                        [50, 150, 300, 600].forEach(ms => setTimeout(updateUnpinnedHeight, ms));
                    }
                };

                window._mcApplyPreviewScrollMode = applyPreviewScrollMode;

                // Initiale Ausrichtung
                applyPreviewScrollMode();

                // Für ungelösten Modus: Bild- und Resize-Listener
                try {
                    iframeDoc.querySelectorAll('img').forEach(img => {
                        if (!img.complete) {
                            img.addEventListener('load', () => {
                                if (!$.previewPane?.classList.contains('mc-header-pinned')) {
                                    updateUnpinnedHeight();
                                }
                            }, { once: true });
                        }
                    });
                } catch (_) {}

                window.addEventListener('resize', applyPreviewScrollMode, { passive: true });

                // Wheel-Event nur bei ungelöstem Modus an den Wrapper weiterleiten
                try {
                    const iframeWin = iframe.contentWindow;
                    if (iframeWin) {
                        iframeWin.addEventListener('wheel', (e) => {
                            if (!$.previewPane?.classList.contains('mc-header-pinned')) {
                                const sc = document.getElementById('mc-preview-scroll-wrapper');
                                if (sc) {
                                    sc.scrollTop += e.deltaY;
                                    if (e.deltaX) sc.scrollLeft += e.deltaX;
                                }
                            }
                        }, { passive: true });
                    }
                } catch (_) {}
            }
        }

        // Anhänge
        let attBar = $.previewPane.querySelector('.mc-attachments-bar');
        if (data.attachments && data.attachments.length > 0) {
            if (!attBar) {
                attBar = document.createElement('div');
                attBar.className = 'mc-attachments-bar';
                const headerWrap = document.getElementById('mc-preview-header-wrap') || $.previewPane;
                headerWrap.appendChild(attBar);
            }
            attBar.innerHTML = data.attachments.map(att => `
                <a href="/mail-client/api/attachment?account=${data.account_id}&folder=${encodeURIComponent(data.folder)}&uid=${data.uid}&part=${encodeURIComponent(att.part_id)}" 
                   class="mc-attachment-chip" download="${escHtml(att.filename)}">
                    <i class="bi bi-paperclip"></i>
                    ${escHtml(att.filename)}
                    <span class="text-muted">(${formatBytes(att.size)})</span>
                </a>
            `).join('');
        } else if (attBar) {
            attBar.remove();
        }
    }

    function renderPreviewError(msg) {
        if (!$.previewPane) return;
        const previewBody = $.previewPane.querySelector('.mc-preview-body');
        if (previewBody) {
            previewBody.innerHTML = `
                <div class="mc-preview-empty">
                    <i class="bi bi-exclamation-triangle mc-preview-empty-icon text-danger"></i>
                    <div>Fehler beim Laden: ${escHtml(msg)}</div>
                    <button class="btn btn-sm btn-outline-secondary" onclick="location.reload()">Seite neu laden</button>
                </div>
            `;
        }
    }

    function clearPreview() {
        if (!$.previewPane) return;
        const sc = document.getElementById('mc-preview-scroll-wrapper');
        if (sc) { sc.scrollTop = 0; }
        const header = $.previewPane.querySelector('.mc-preview-header');
        const actions = $.previewPane.querySelector('.mc-preview-actions');
        const previewBody = $.previewPane.querySelector('.mc-preview-body');
        if (header) { header.innerHTML = ''; header.classList.add('d-none'); }
        if (actions) { actions.innerHTML = ''; actions.classList.add('d-none'); }
        if (previewBody) {
            previewBody.innerHTML = `
                <div class="mc-preview-empty">
                    <i class="bi bi-envelope mc-preview-empty-icon"></i>
                    <div>Nachricht auswählen um sie hier anzuzeigen</div>
                    <div class="text-secondary small">Doppelklick öffnet im Vollbild (neuer Tab)</div>
                </div>
            `;
        }
        $.previewPane.querySelector('.mc-blocked-images-banner')?.remove();
        $.previewPane.querySelector('.mc-attachments-bar')?.remove();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Öffentliche Aktionen (von HTML-Inline-Handlern aufgerufen)
    // ═══════════════════════════════════════════════════════════════════════════
    window.mailClientApp = {
        openFull(accountId, folder, uid) {
            window.open(
                `/mail-client/message?account=${accountId}&folder=${folder}&uid=${uid}`,
                '_blank',
                'noopener,noreferrer'
            );
        },

        toggleFlag(accountId, folder, uid, flag, btn) {
            const msg = state.messages.find(m => m.uid === uid);
            const isCurrentlySet = flag === 'flagged' ? (msg ? msg.is_flagged : (btn?.classList.contains('btn-outline-warning') || btn?.querySelector('.bi-flag-fill, .bi-star-fill'))) : (msg ? msg.is_seen : false);
            toggleMessageFlag(accountId, folder, uid, flag, !isCurrentlySet);
        },

        async deleteMail(accountId, folder, uid) {
            if (!confirm('Diese Nachricht wirklich löschen?')) return;
            try {
                await apiFetch('/mail-client/api/messages/delete', {
                    method: 'POST',
                    body: { account: accountId, folder: decodeURIComponent(folder), uid },
                });
                // Zeile aus Liste entfernen
                const row = $.messageList?.querySelector(`.mc-message-row[data-uid="${uid}"]`);
                row?.remove();
                clearPreview();
            } catch (err) {
                alert('Löschen fehlgeschlagen: ' + err.message);
            }
        },

        async allowExternal(senderEncoded, btn) {
            const sender = decodeURIComponent(senderEncoded);
            try {
                await apiFetch('/mail-client/api/whitelist', {
                    method: 'POST',
                    body: { scope_type: 'sender', scope_value: sender },
                });
                // Vorschau neu laden mit erlaubten externen Bildern
                if (state.uid && state.accountId && state.folder) {
                    await loadPreview(state.accountId, state.folder, state.uid);
                }
            } catch (err) {
                alert('Whitelist konnte nicht aktualisiert werden: ' + err.message);
            }
        },

        openSidebar() {
            $.sidebar?.classList.add('mc-sidebar-open');
        },

        closeSidebar() {
            $.sidebar?.classList.remove('mc-sidebar-open');
        },

        closePreview() {
            $.previewPane?.classList.remove('mc-preview-active');
        },
    };

    // ═══════════════════════════════════════════════════════════════════════════
    // Hilfsfunktionen
    // ═══════════════════════════════════════════════════════════════════════════
    function showListLoading(show) {
        if (!$.messageList) return;
        const existing = $.messageList.querySelector('.mc-list-spinner');
        if (show && !existing) {
            const spinner = document.createElement('div');
            spinner.className = 'mc-list-spinner text-center py-3 text-secondary';
            spinner.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Wird geladen…';
            $.messageList.appendChild(spinner);
        } else if (!show && existing) {
            existing.remove();
        }
    }

    function showListError(msg) {
        if (!$.messageList) return;
        const err = document.createElement('div');
        err.className = 'alert alert-danger m-2';
        err.textContent = 'Fehler: ' + msg;
        $.messageList.appendChild(err);
    }

    function showPreviewLoading(show) {
        if (!$.previewPane) return;
        const existing = $.previewPane.querySelector('.mc-loading-overlay');
        if (show && !existing) {
            const overlay = document.createElement('div');
            overlay.className = 'mc-loading-overlay';
            overlay.innerHTML = '<span class="spinner-border text-primary"></span>';
            $.previewPane.querySelector('.mc-preview-body')?.appendChild(overlay);
        } else if (!show && existing) {
            existing.remove();
        }
    }

    function escHtml(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str || '')));
        return div.innerHTML;
    }

    function formatBytes(bytes) {
        if (!bytes || bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Initialisierung
    // ═══════════════════════════════════════════════════════════════════════════
    // ═════════════════════════════════════════════════════════════════════════════
    // Maximieren & Frameless App-Fenster
    // ═════════════════════════════════════════════════════════════════════════════
    function initMaximizeAndPopup() {
        const btnMaximize = document.getElementById('mc-btn-maximize');
        const textMaximize = document.getElementById('mc-btn-maximize-text');
        const btnPopup = document.getElementById('mc-btn-popup');

        // Prüfen, ob als Standalone PWA oder frameless Popout geöffnet
        const params = new URLSearchParams(window.location.search);
        const isFrameless = params.get('frameless') === '1'
            || (window.opener && window.name === 'ModulonMailClientPopout')
            || window.matchMedia('(display-mode: standalone)').matches
            || (window.navigator && window.navigator.standalone === true);

        if (isFrameless) {
            document.body.classList.add('mc-frameless');
            if (btnMaximize) btnMaximize.style.display = 'none';
            if (btnPopup) {
                if (window.matchMedia('(display-mode: standalone)').matches || (window.navigator && window.navigator.standalone)) {
                    btnPopup.style.display = 'none';
                } else {
                    btnPopup.innerHTML = '<i class="bi bi-x-circle"></i><span class="d-none d-xl-inline ms-1">Schließen</span>';
                    btnPopup.title = 'Fenster schließen';
                    btnPopup.onclick = () => window.close();
                }
            }
        } else {
            // Gespeicherten Maximierungs-Zustand prüfen
            const wasMaximized = localStorage.getItem('mc_is_maximized') === '1';
            if (wasMaximized) {
                applyMaximized(true);
            }

            if (btnMaximize) {
                btnMaximize.addEventListener('click', () => {
                    const isMax = document.body.classList.contains('mc-maximized');
                    applyMaximized(!isMax);
                });
            }

            // Wenn vorab schon ein Install-Prompt abgefangen wurde, Button anpassen
            if (deferredInstallPrompt && btnPopup) {
                btnPopup.innerHTML = '<i class="bi bi-box-arrow-in-down"></i><span class="d-none d-xl-inline ms-1">App installieren</span>';
                btnPopup.title = 'Als eigenständige Desktop-App installieren (ohne Browser-Rahmen)';
            }

            if (btnPopup) {
                btnPopup.addEventListener('click', async () => {
                    if (deferredInstallPrompt) {
                        deferredInstallPrompt.prompt();
                        const choice = await deferredInstallPrompt.userChoice;
                        if (choice && choice.outcome === 'accepted') {
                            deferredInstallPrompt = null;
                        }
                        return;
                    }

                    // Fallback (z. B. unverschlüsseltes LAN): Frameless Popout öffnen
                    const url = new URL(window.location.href);
                    url.searchParams.set('frameless', '1');
                    const w = Math.min(1600, (window.screen && window.screen.availWidth) || 1400);
                    const h = Math.min(1000, (window.screen && window.screen.availHeight) || 900);
                    const left = Math.max(0, Math.floor((((window.screen && window.screen.width) || 1400) - w) / 2));
                    const top = Math.max(0, Math.floor((((window.screen && window.screen.height) || 900) - h) / 2));
                    const features = `popup=yes,width=${w},height=${h},top=${top},left=${left},toolbar=no,menubar=no,location=no,status=no,directories=no,scrollbars=yes,resizable=yes`;
                    const popup = window.open(url.toString(), 'ModulonMailClientPopout', features);
                    if (popup) {
                        popup.focus();
                    }
                });
            }

            // ESC-Taste beendet Maximierung
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && document.body.classList.contains('mc-maximized')) {
                    applyMaximized(false);
                }
            });
        }

        function applyMaximized(enable) {
            if (enable) {
                document.body.classList.add('mc-maximized');
                if (btnMaximize) {
                    const icon = btnMaximize.querySelector('i');
                    if (icon) icon.className = 'bi bi-fullscreen-exit';
                    if (textMaximize) textMaximize.textContent = 'Verkleinern';
                    btnMaximize.title = 'Maximierung beenden (Esc)';
                }
                try { localStorage.setItem('mc_is_maximized', '1'); } catch (_) {}
            } else {
                document.body.classList.remove('mc-maximized');
                if (btnMaximize) {
                    const icon = btnMaximize.querySelector('i');
                    if (icon) icon.className = 'bi bi-arrows-fullscreen';
                    if (textMaximize) textMaximize.textContent = 'Maximieren';
                    btnMaximize.title = 'Im Fenster maximieren (ohne Modulon Header/Footer)';
                }
                try { localStorage.removeItem('mc_is_maximized'); } catch (_) {}
            }
        }
    }

    function initSettingsModalEvents() {
        const layout3Col = document.getElementById('mcLayout3Col');
        const layoutSplit = document.getElementById('mcLayoutSplit');
        if (layout3Col) {
            layout3Col.addEventListener('change', () => {
                if (layout3Col.checked) applySettings({ layout: '3col' });
            });
        }
        if (layoutSplit) {
            layoutSplit.addEventListener('change', () => {
                if (layoutSplit.checked) applySettings({ layout: 'split' });
            });
        }

        const listCards = document.getElementById('mcListModeCards');
        const listTable = document.getElementById('mcListModeTable');
        if (listCards) {
            listCards.addEventListener('change', () => {
                if (listCards.checked) applySettings({ listMode: 'cards' });
            });
        }
        if (listTable) {
            listTable.addEventListener('change', () => {
                if (listTable.checked) applySettings({ listMode: 'table' });
            });
        }

        const showRecipient = document.getElementById('mcSettingShowRecipient');
        if (showRecipient) {
            showRecipient.addEventListener('change', () => {
                applySettings({ showRecipient: showRecipient.checked });
            });
        }

        const showCalendar = document.getElementById('mcSettingShowCalendar');
        if (showCalendar) {
            showCalendar.addEventListener('change', () => {
                applySettings({ showCalendar: showCalendar.checked });
            });
        }

        const btnCalendar = document.getElementById('mc-btn-calendar');
        if (btnCalendar) {
            btnCalendar.addEventListener('click', () => {
                toggleCalendarSidebar();
            });
        }

        const calClose = document.getElementById('mc-cal-close');
        if (calClose) {
            calClose.addEventListener('click', () => {
                toggleCalendarSidebar(false);
            });
        }

        const calRefresh = document.getElementById('mc-cal-refresh');
        if (calRefresh) {
            calRefresh.addEventListener('click', () => {
                loadCalendarUpcoming(true);
            });
        }

        window.addEventListener('focus', () => {
            if ($.calendarSidebar && $.calendarSidebar.style.display !== 'none') {
                loadCalendarUpcoming(true);
            }
        });
    }

    function syncSettingsModalUI() {
        const layout3Col = document.getElementById('mcLayout3Col');
        const layoutSplit = document.getElementById('mcLayoutSplit');
        if (layout3Col && layoutSplit) {
            layout3Col.checked = settings.layout === '3col';
            layoutSplit.checked = settings.layout === 'split';
        }

        const listCards = document.getElementById('mcListModeCards');
        const listTable = document.getElementById('mcListModeTable');
        if (listCards && listTable) {
            listCards.checked = settings.listMode === 'cards';
            listTable.checked = settings.listMode === 'table';
        }

        const showRecipient = document.getElementById('mcSettingShowRecipient');
        if (showRecipient) {
            showRecipient.checked = Boolean(settings.showRecipient);
        }

        const showCalendar = document.getElementById('mcSettingShowCalendar');
        if (showCalendar) {
            showCalendar.checked = Boolean(settings.showCalendar);
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        initDom();
        initColumnToggles();
        initMaximizeAndPopup();
        initSettingsModalEvents();
        applySettings();
        bindFolderClicks();
        initPaginationEvents();

        // Auto-Load ersten Ordner wenn vorhanden
        const firstFolder = document.querySelector('.mc-folder-item[data-folder]:not([data-folder-load])');
        if (firstFolder && !state.folder) {
            firstFolder.click();
        }

        // Header Pin-State initialisieren
        const isPinned = localStorage.getItem('mc_header_pinned') === '1';
        if ($.previewPane) {
            $.previewPane.classList.toggle('mc-header-pinned', isPinned);
        }

        // Alle Ordner synchronisieren Button binden
        const btnSyncAll = document.getElementById('mc-btn-sync-all');
        if (btnSyncAll) {
            btnSyncAll.addEventListener('click', syncAllFolders);
        }
    });

    /**
     * Alle Ordner des aktuellen Kontos nacheinander vollständig synchronisieren.
     */
    let _mcSyncAllRunning = false;
    async function syncAllFolders() {
        if (_mcSyncAllRunning) return;
        let accountId = state.accountId;
        if (!accountId) {
            const activeItem = document.querySelector('.mc-folder-item.active') 
                            || document.querySelector('.mc-folder-item[data-account]');
            if (activeItem && activeItem.dataset.account) {
                accountId = parseInt(activeItem.dataset.account, 10);
            }
        }
        if (!accountId) {
            alert('Bitte zuerst ein Konto auswählen.');
            return;
        }

        const btn = document.getElementById('mc-btn-sync-all');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span><span class="d-none d-md-inline ms-1">Synchronisiere…</span>';
        }

        _mcSyncAllRunning = true;
        _mcSyncUpdateBar('syncing', 0, 0);

        try {
            const csrf = document.querySelector('[data-csrf]')?.dataset?.csrf || '';
            const resp = await fetch(`/mail-client/api/folders?account=${accountId}`, {
                headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf },
                credentials: 'same-origin',
            });
            const data = await resp.json();
            const folders = data.folders || [];

            for (let i = 0; i < folders.length; i++) {
                const f = folders[i];
                const fName = f.name;
                const fDisplay = f.display_name || fName;

                let isComplete = false;
                let retries = 0;

                while (!isComplete && retries < 5) {
                    try {
                        const syncParams = new URLSearchParams({ account: accountId, folder: fName });
                        const syncResp = await fetch('/mail-client/api/sync/continue?' + syncParams, {
                            method: 'POST',
                            headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf },
                            credentials: 'same-origin',
                        });
                        if (!syncResp.ok) throw new Error('HTTP ' + syncResp.status);
                        const syncData = await syncResp.json();

                        const bar = document.getElementById('mc-sync-status-bar');
                        if (bar) {
                            bar.classList.remove('mc-sync-hidden', 'mc-sync-done', 'mc-sync-error');
                            bar.innerHTML = `<span class="mc-sync-spinner"></span> <span>Synchronisiere Ordner ${i + 1}/${folders.length}: <strong>${escHtml(fDisplay)}</strong> (${mcFormatNumber(syncData.synced_uids || 0)} von ${mcFormatNumber(syncData.total_uids || 0)})</span>`;
                        }

                        if (syncData.status === 'complete' || !syncData.status) {
                            isComplete = true;
                        } else {
                            await new Promise(r => setTimeout(r, 200));
                        }
                    } catch (e) {
                        retries++;
                        await new Promise(r => setTimeout(r, 1000));
                    }
                }
            }

            _mcSyncUpdateBar('complete', 0, 0);

            // Sidebar-Ordner aktualisieren
            const loadBtn = document.querySelector(`.mc-folder-item[data-account="${accountId}"][data-folder-load="true"]`);
            if (loadBtn && typeof mailClientApp.loadFolders === 'function') {
                mailClientApp.loadFolders(parseInt(accountId, 10), loadBtn);
            }
            // Aktuellen Ordner neu laden falls offen
            if (state.folder) {
                loadMessages(true);
            }
        } catch (err) {
            _mcSyncUpdateBar('error', 0, 0);
        } finally {
            _mcSyncAllRunning = false;
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> <span class="d-none d-md-inline ms-1">Alle synchronisieren</span>';
            }
        }
    }

    window.mailClientApp.syncAllFolders = syncAllFolders;
    window.mcSyncAllFolders = syncAllFolders;

})();

/* ═══════════════════════════════════════════════════════════════════════════
   Rich-Text-Editor (Compose)
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
    'use strict';

    function initRichEditor() {
        const editor  = document.querySelector('.mc-compose-body');
        const hidden  = document.querySelector('#mc-compose-body-hidden');
        const toolbar = document.querySelector('.mc-compose-editor-toolbar');
        if (!editor || !toolbar) return;

        // contenteditable aktivieren
        editor.contentEditable = 'true';

        // Toolbar-Buttons
        const buttons = [
            { cmd: 'bold',          icon: 'bi-type-bold',          title: 'Fett (Strg+B)' },
            { cmd: 'italic',        icon: 'bi-type-italic',        title: 'Kursiv (Strg+I)' },
            { cmd: 'underline',     icon: 'bi-type-underline',     title: 'Unterstrichen (Strg+U)' },
            { sep: true },
            { cmd: 'insertUnorderedList', icon: 'bi-list-ul',     title: 'Aufzählung' },
            { cmd: 'insertOrderedList',   icon: 'bi-list-ol',     title: 'Nummerierte Liste' },
            { sep: true },
            { cmd: 'justifyLeft',   icon: 'bi-text-left',          title: 'Linksbündig' },
            { cmd: 'justifyCenter', icon: 'bi-text-center',        title: 'Zentriert' },
            { cmd: 'justifyRight',  icon: 'bi-text-right',         title: 'Rechtsbündig' },
            { sep: true },
            { cmd: 'createLink',    icon: 'bi-link-45deg',         title: 'Link einfügen', special: 'link' },
            { cmd: 'unlink',        icon: 'bi-link',               title: 'Link entfernen' },
            { sep: true },
            { cmd: 'removeFormat',  icon: 'bi-eraser',             title: 'Formatierung entfernen' },
        ];

        toolbar.innerHTML = '';
        buttons.forEach(btn => {
            if (btn.sep) {
                const sep = document.createElement('span');
                sep.className = 'mc-toolbar-sep';
                toolbar.appendChild(sep);
                return;
            }
            const b = document.createElement('button');
            b.type  = 'button';
            b.title = btn.title;
            b.innerHTML = `<i class=\"bi ${btn.icon}\"></i>`;
            b.addEventListener('mousedown', (e) => {
                e.preventDefault();
                if (btn.special === 'link') {
                    const url = prompt('URL eingeben:', 'https://');
                    if (url && (url.startsWith('https://') || url.startsWith('http://'))) {
                        document.execCommand('createLink', false, url);
                    }
                } else {
                    document.execCommand(btn.cmd, false, null);
                }
                editor.focus();
                updateToolbarState();
            });
            toolbar.appendChild(b);
        });

        function updateToolbarState() {
            toolbar.querySelectorAll('button').forEach(b => {
                try { b.classList.toggle('active', document.queryCommandState(b.dataset.cmd || '')); } catch (_) {}
            });
        }

        // Vor dem Absenden: HTML aus contenteditable in hidden field kopieren
        editor.closest('form')?.addEventListener('submit', () => {
            if (hidden) {
                hidden.value = editor.innerHTML;
            }
        });

        // Änderungen verfolgen
        editor.addEventListener('keyup', updateToolbarState);
        editor.addEventListener('mouseup', updateToolbarState);
        editor.addEventListener('selectionchange', updateToolbarState);
    }

    document.addEventListener('DOMContentLoaded', initRichEditor);
})();

// ═══════════════════════════════════════════════════════════════════════════
// Zahlenformatierung (deutsch: 1.234 / 50.000)
// ═══════════════════════════════════════════════════════════════════════════

function mcFormatNumber(n) {
    return new Intl.NumberFormat('de-DE').format(n);
}
window.mcFormatNumber = mcFormatNumber;

// ═══════════════════════════════════════════════════════════════════════════
// Hintergrund-Sync-Polling mit Statusleiste
// ═══════════════════════════════════════════════════════════════════════════

let _mcSyncTimer           = null;
let _mcSyncAccountId       = null;
let _mcSyncFolder          = null;
let _mcSyncRetryCount      = 0;
let _mcSyncAbortController = null;
const MAX_SYNC_RETRIES     = 5;

/**
 * Startet den Hintergrund-Sync für einen Ordner.
 * Bricht einen laufenden Sync automatisch ab wenn ein anderer Ordner geöffnet wird.
 */
function mcStartSyncPolling(accountId, folderName) {
    if (_mcSyncTimer !== null) {
        clearTimeout(_mcSyncTimer);
        _mcSyncTimer = null;
    }
    if (_mcSyncAbortController !== null) {
        try { _mcSyncAbortController.abort(); } catch (_) {}
        _mcSyncAbortController = null;
    }
    _mcSyncAccountId  = accountId;
    _mcSyncFolder     = folderName;
    _mcSyncRetryCount = 0;

    _mcSyncPollStep();
}

window.mcRetrySync = function() {
    if (_mcSyncAccountId && _mcSyncFolder) {
        _mcSyncRetryCount = 0;
        _mcSyncUpdateBar('syncing', 0, 0);
        _mcSyncPollStep();
    }
};

/** Führt einen einzelnen Polling-Schritt aus (eine HTTP-Anfrage an continueSync). */
async function _mcSyncPollStep() {
    const accountId  = _mcSyncAccountId;
    const folderName = _mcSyncFolder;
    if (!accountId || !folderName) { return; }

    const csrf   = document.querySelector('[data-csrf]')?.dataset?.csrf || '';
    const params = new URLSearchParams({ account: accountId, folder: folderName });

    _mcSyncAbortController = new AbortController();

    try {
        const resp = await fetch('/mail-client/api/sync/continue?' + params, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf },
            credentials: 'same-origin',
            signal: _mcSyncAbortController.signal,
        });
        if (!resp.ok) { throw new Error('HTTP ' + resp.status); }
        const data = await resp.json();

        // Abbrechen wenn User inzwischen Ordner gewechselt hat
        if (_mcSyncAccountId !== accountId || _mcSyncFolder !== folderName) { return; }

        if (data.status === 'error') {
            throw new Error(data.error || 'Synchronisationsfehler');
        }

        _mcSyncRetryCount = 0; // Erfolg -> Retry-Zähler zurücksetzen

        if (data.status === 'syncing') {
            _mcSyncUpdateBar(data.status, data.synced_uids ?? 0, data.total_uids ?? 0);
            _mcSyncTimer = setTimeout(_mcSyncPollStep, 800);
        } else if (data.status === 'complete') {
            const bar = document.getElementById('mc-sync-status-bar');
            if (bar && !bar.classList.contains('mc-sync-hidden') && !bar.classList.contains('mc-sync-done')) {
                _mcSyncUpdateBar('complete', data.synced_uids ?? 0, data.total_uids ?? 0);
                setTimeout(() => {
                    if (bar && _mcSyncAccountId === accountId && _mcSyncFolder === folderName) {
                        bar.classList.add('mc-sync-hidden');
                    }
                }, 4000);
            } else if (bar) {
                bar.classList.add('mc-sync-hidden');
            }
        }
    } catch (_e) {
        if (_e.name === 'AbortError') { return; }
        if (_mcSyncAccountId !== accountId || _mcSyncFolder !== folderName) { return; }
        if (_mcSyncRetryCount < MAX_SYNC_RETRIES) {
            _mcSyncRetryCount++;
            _mcSyncTimer = setTimeout(_mcSyncPollStep, 2500);
        } else {
            _mcSyncUpdateBar('error', 0, 0);
        }
    }
}

/**
 * Erstellt oder aktualisiert die Statusleiste am unteren Rand des Mail-Clients (unter der Mail-Vorschau).
 * @param {'syncing'|'complete'|'error'|'idle'} status
 */
function _mcSyncUpdateBar(status, synced, total) {
    let bar = document.getElementById('mc-sync-status-bar');
    if (!bar) {
        const mainArea = document.getElementById('mc-main-area') || document.querySelector('.mc-main-area');
        if (!mainArea) { return; }
        bar = document.createElement('div');
        bar.id        = 'mc-sync-status-bar';
        bar.className = 'mc-sync-bar';
        mainArea.appendChild(bar);
    }

    bar.classList.remove('mc-sync-hidden', 'mc-sync-done', 'mc-sync-error');

    if (status === 'syncing') {
        const knownTotal = total > 0 ? total : 0;
        const pct = knownTotal > 0 ? Math.min(100, Math.round((synced / knownTotal) * 100)) : 0;
        bar.innerHTML = '<span class="mc-sync-spinner"></span>'
            + (knownTotal > 0
                ? '<span>Synchronisiere Header&hellip; <strong>' + mcFormatNumber(synced) + '</strong> von <strong>' + mcFormatNumber(knownTotal) + '</strong></span>'
                  + '<span class="ms-auto text-body-secondary">' + pct + '&nbsp;%</span>'
                : '<span>Synchronisiere Header&hellip;</span>');
    } else if (status === 'complete') {
        bar.classList.add('mc-sync-done');
        const count = total > 0 ? total : (synced > 0 ? synced : 0);
        bar.innerHTML = '<span>&#10003;</span>'
            + '<span>Synchronisierung abgeschlossen &ndash; '
            + mcFormatNumber(count)
            + '&nbsp;Nachrichten</span>';
    } else if (status === 'error') {
        bar.classList.add('mc-sync-error');
        bar.innerHTML = '<span>&#9888;</span>'
            + '<span>Synchronisierungsfehler</span>'
            + '<button type="button" class="btn btn-sm btn-link p-0 ms-2 text-danger text-decoration-underline" onclick="window.mcRetrySync && window.mcRetrySync()">Erneut versuchen</button>';
    } else {
        bar.classList.add('mc-sync-hidden');
    }
}

// Integration: Wird ausgelöst wenn ein Ordner geöffnet wird.
document.addEventListener('mc:folder-opened', function (e) {
    const { accountId, folderName } = e.detail || {};
    if (accountId && folderName) {
        mcStartSyncPolling(accountId, folderName);
    }
});

window.mcTriggerSync = function (accountId, folderName) {
    mcStartSyncPolling(accountId, folderName);
};
