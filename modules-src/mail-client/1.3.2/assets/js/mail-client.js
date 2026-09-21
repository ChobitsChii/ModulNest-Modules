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
        userCalendars: [],
        currentCalendarEvents: [],
    };

    // ═══════════════════════════════════════════════════════════════════════════
    // In-Memory Seiten-Cache & Prefetching
    // ═══════════════════════════════════════════════════════════════════════════
    function decodeMimeWords(str) {
        if (!str || typeof str !== "string" || !str.includes("=?")) return str;
        return str.replace(/=\?([^?]+)\?([BQbq])\?([^?]+)\?=/g, function(match, charset, encoding, text) {
            try {
                if (encoding.toUpperCase() === "B") {
                    const binary = atob(text.replace(/\s+/g, ""));
                    const bytes = Uint8Array.from(binary, c => c.charCodeAt(0));
                    return new TextDecoder(charset).decode(bytes);
                } else if (encoding.toUpperCase() === "Q") {
                    const hexDecoded = text.replace(/_/g, " ").replace(/=([A-Fa-f0-9]{2})/g, function(_, hex) {
                        return "%" + hex;
                    });
                    return decodeURIComponent(hexDecoded);
                }
            } catch (e) {
                return match;
            }
            return match;
        });
    }

    const pageCache = new Map();
    const MAX_PAGE_CACHE = 40;
    let _prefetchAbortController = null;

    function getCacheKey(accountId, folder, page) {
        return `${accountId}:${folder}:${page}`;
    }

    function setCachedPage(accountId, folder, page, data) {
        const key = getCacheKey(accountId, folder, page);
        if (pageCache.size >= MAX_PAGE_CACHE) {
            const oldestKey = pageCache.keys().next().value;
            pageCache.delete(oldestKey);
        }
        pageCache.set(key, {
            messages: data.messages || [],
            total: data.total || 0,
            unseen: data.unseen || 0,
            timestamp: Date.now(),
        });
    }

    function clearFolderPageCache(accountId = null, folder = null) {
        if (_prefetchAbortController) {
            _prefetchAbortController.abort();
            _prefetchAbortController = null;
        }
        if (!accountId && !folder) {
            pageCache.clear();
            return;
        }
        for (const key of pageCache.keys()) {
            if (accountId && folder) {
                if (key.startsWith(`${accountId}:${folder}:`)) {
                    pageCache.delete(key);
                }
            } else if (accountId) {
                if (key.startsWith(`${accountId}:`)) {
                    pageCache.delete(key);
                }
            }
        }
    }

    async function prefetchNeighborPages(targetAccountId, targetFolder, currentPage, totalPages) {
        if (!targetAccountId || !targetFolder || totalPages <= 1) return;

        if (_prefetchAbortController) {
            _prefetchAbortController.abort();
        }
        _prefetchAbortController = new AbortController();
        const signal = _prefetchAbortController.signal;

        // Priorisierte Reihenfolge: Nächste 3 Seiten und vorherige 3 Seiten
        const offsets = [1, -1, 2, 3, -2, -3];
        const pagesToPrefetch = [];
        for (const offset of offsets) {
            const p = currentPage + offset;
            if (p >= 1 && p <= totalPages && !pageCache.has(getCacheKey(targetAccountId, targetFolder, p))) {
                pagesToPrefetch.push(p);
            }
        }

        for (const p of pagesToPrefetch) {
            if (signal.aborted) break;
            if (state.accountId !== targetAccountId || state.folder !== targetFolder) break;

            try {
                // Kurze Pause zwischen Abrufen, um Netzwerk und UI nicht zu blockieren
                await new Promise(res => setTimeout(res, 120));
                if (signal.aborted) break;
                if (state.accountId !== targetAccountId || state.folder !== targetFolder) break;

                const offset = (p - 1) * state.limit;
                const url = `/mail-client/api/messages?account=${targetAccountId}&folder=${encodeURIComponent(targetFolder)}&limit=${state.limit}&offset=${offset}`;
                const resp = await fetch(url, {
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin',
                    signal,
                });
                if (resp.ok) {
                    const data = await resp.json();
                    if (data && Array.isArray(data.messages)) {
                        setCachedPage(targetAccountId, targetFolder, p, data);
                    }
                }
            } catch (err) {
                if (err.name === 'AbortError') break;
            }
        }
    }

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
    function decodeUtf7Imap(str) {
        if (!str || typeof str !== "string") return str || "";
        return str.replace(/&([A-Za-z0-9+,]*)-/g, function(match, b64) {
            if (b64 === "") return "&";
            let stdB64 = b64.replace(/,/g, "/");
            while (stdB64.length % 4 !== 0) stdB64 += "=";
            try {
                const binary = atob(stdB64);
                const bytes = new Uint8Array(binary.length);
                for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
                return new TextDecoder("utf-16be").decode(bytes);
            } catch (e) {
                return match;
            }
        });
    }

    function getFolderDisplayName(name, special) {
        if (!name) return "";
        const decoded = decodeUtf7Imap(name);
        if (special === "inbox" || name.toUpperCase() === "INBOX" || decoded.toUpperCase() === "INBOX") return "Posteingang";
        const lower = decoded.toLowerCase();
        const baseName = lower.split(/[./\\]/).pop();
        if (special === "sent" || ["sent", "sent items", "gesendet", "sent-mail", "gesendete objekte"].includes(baseName)) return "Gesendet";
        if (special === "drafts" || ["drafts", "draft", "entwürfe", "entwuerfe"].includes(baseName)) return "Entwürfe";
        if (special === "trash" || ["trash", "deleted", "papierkorb", "gelöschte elemente", "deleted items", "bin"].includes(baseName)) return "Papierkorb";
        if (special === "spam" || ["spam", "junk", "junk e-mail", "unerwünscht", "spamverdacht"].includes(baseName)) return "Spam";
        if (special === "archive" || ["archive", "archiv", "archives"].includes(baseName)) return "Archiv";
        return decoded;
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
        autoRefresh: 120,            // Automatischer Abruf-Intervall in Sekunden (0 = deaktiviert, default: 120s)
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

        // 3b. Auto-Refresh Intervall neu starten
        restartAutoRefreshTimer();

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
            if (data && Array.isArray(data.calendars)) {
                state.userCalendars = data.calendars;
            }

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
                state.folderDisplayName = el.dataset.displayName || el.querySelector('.mc-folder-name')?.textContent || getFolderDisplayName(folder);
                state.page      = 1;
                state.uid       = 0;

                const btnListRefresh = document.getElementById('mc-list-refresh');
                if (btnListRefresh) btnListRefresh.style.display = '';

                clearPreview();
                if (typeof window.mcTriggerSync === 'function') {
                    window.mcTriggerSync(accountId, folder);
                }
                await loadMessages(true);
            });
        });

        // Account-Gruppe collapse/expand
        document.querySelectorAll('.mc-account-header[data-account]').forEach(el => {
            el.addEventListener('click', (e) => {
                if (e.target.closest('.mc-account-actions')) return;
                const accountId = el.dataset.account;
                const list = document.querySelector(`.mc-folder-list[data-account="${accountId}"]`);
                if (list) {
                    const collapsed = list.classList.toggle('d-none');
                    el.closest('.mc-account-group')?.classList.toggle('mc-account-collapsed', collapsed);
                }
            });
        });

        // Account-Sortierung: Up/Down Buttons & Drag-and-Drop
        function updateAccountMoveButtons() {
            const groups = Array.from(document.querySelectorAll('.mc-account-group[data-account-group]'));
            groups.forEach((group, idx) => {
                const upBtn = group.querySelector('.mc-btn-move-account[data-dir="up"]');
                const downBtn = group.querySelector('.mc-btn-move-account[data-dir="down"]');
                if (upBtn) upBtn.disabled = (idx === 0);
                if (downBtn) downBtn.disabled = (idx === groups.length - 1);
            });
        }

        async function saveAccountOrder() {
            updateAccountMoveButtons();
            const groups = Array.from(document.querySelectorAll('.mc-account-group[data-account-group]'));
            const order = groups.map(g => parseInt(g.getAttribute('data-account-group'), 10)).filter(n => !isNaN(n) && n > 0);
            if (order.length === 0) return;
            try {
                const csrf = state.csrfToken || document.querySelector('[data-csrf]')?.dataset?.csrf || document.querySelector('input[name="_csrf"]')?.value || '';
                const resp = await fetch('/mail-client/api/accounts/reorder', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-Token': csrf,
                    },
                    body: JSON.stringify({ order }),
                });
                if (!resp.ok) {
                    const errData = await resp.json().catch(() => ({}));
                    console.error('Fehler beim Speichern der Konten-Sortierung:', resp.status, errData);
                }
            } catch (err) {
                console.error('Netzwerkfehler beim Speichern der Konten-Sortierung:', err);
            }
        }

        document.querySelectorAll('.mc-btn-move-account').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.preventDefault();
                e.stopPropagation();
                const group = btn.closest('.mc-account-group');
                if (!group) return;
                const dir = btn.dataset.dir;
                if (dir === 'up') {
                    const prev = group.previousElementSibling;
                    if (prev && prev.classList.contains('mc-account-group')) {
                        prev.before(group);
                        await saveAccountOrder();
                    }
                } else if (dir === 'down') {
                    const next = group.nextElementSibling;
                    if (next && next.classList.contains('mc-account-group')) {
                        next.after(group);
                        await saveAccountOrder();
                    }
                }
            });
        });

        let draggedGroup = null;
        document.querySelectorAll('.mc-account-header[draggable="true"]').forEach(header => {
            header.addEventListener('dragstart', (e) => {
                draggedGroup = header.closest('.mc-account-group');
                header.classList.add('mc-account-dragging');
                if (e.dataTransfer) {
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', header.dataset.account || '');
                }
            });
            header.addEventListener('dragend', () => {
                header.classList.remove('mc-account-dragging');
                document.querySelectorAll('.mc-account-group').forEach(g => g.classList.remove('mc-drag-over-top', 'mc-drag-over-bottom'));
                draggedGroup = null;
            });
        });

        document.querySelectorAll('.mc-account-group').forEach(group => {
            group.addEventListener('dragover', (e) => {
                if (!draggedGroup || draggedGroup === group) return;
                e.preventDefault();
                const rect = group.getBoundingClientRect();
                const relY = e.clientY - rect.top;
                const isTop = relY < rect.height / 2;
                group.classList.toggle('mc-drag-over-top', isTop);
                group.classList.toggle('mc-drag-over-bottom', !isTop);
            });
            group.addEventListener('dragleave', () => {
                group.classList.remove('mc-drag-over-top', 'mc-drag-over-bottom');
            });
            group.addEventListener('drop', async (e) => {
                if (!draggedGroup || draggedGroup === group) return;
                e.preventDefault();
                const isTop = group.classList.contains('mc-drag-over-top');
                group.classList.remove('mc-drag-over-top', 'mc-drag-over-bottom');
                if (isTop) {
                    group.before(draggedGroup);
                } else {
                    group.after(draggedGroup);
                }
                await saveAccountOrder();
            });
        });

        updateAccountMoveButtons();
    }

    // Event von dynamisch nachgeladenen Ordnern
    document.addEventListener('mc-folder-select', async (e) => {
        const { accountId, folder } = e.detail || {};
        if (!folder) return;
        state.accountId = parseInt(accountId, 10);
        state.folder    = folder;
        const activeEl  = document.querySelector(`.mc-folder-item[data-account="${accountId}"][data-folder="${folder}"]`);
        state.folderDisplayName = activeEl?.dataset.displayName || activeEl?.querySelector('.mc-folder-name')?.textContent || getFolderDisplayName(folder);
        state.page      = 1;
        state.uid       = 0;
        const btnListRefresh = document.getElementById('mc-list-refresh');
        if (btnListRefresh) btnListRefresh.style.display = '';
        clearPreview();
        await loadMessages(true);
    });

    function updateListHeaderCounts() {
        if ($.listHeader) {
            $.listHeader.textContent = state.folderDisplayName || getFolderDisplayName(state.folder);
        }
        if ($.listUnseenCount) {
            const totalText = `${mcFormatNumber(state.total)} ${state.total === 1 ? "Nachricht" : "Nachrichten"}`;
            const unseenText = state.unseen > 0 ? ` (${mcFormatNumber(state.unseen)} ungelesen)` : "";
            $.listUnseenCount.textContent = `${totalText}${unseenText}`;
            $.listUnseenCount.classList.remove("d-none");
        }
    }

    function updateFolderSidebarBadge(accountId, folder, unseen) {
        const activeFolderItem = document.querySelector(`.mc-folder-item[data-account="${accountId}"][data-folder="${folder}"]`);
        if (activeFolderItem) {
            let badge = activeFolderItem.querySelector('.mc-folder-unseen');
            if (unseen > 0) {
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'mc-folder-unseen';
                    activeFolderItem.appendChild(badge);
                }
                badge.textContent = mcFormatNumber(unseen);
            } else if (badge) {
                badge.remove();
            }
        }
    }

    async function loadMessages(reset = false, targetPage = null, bypassCache = false) {
        mcPrioritizeUserAction();
        if (!$.messageList) return;
        if (state.accountId === 0 || state.folder === '') return;

        if (reset) {
            state.page = 1;
            clearFolderPageCache(state.accountId, state.folder);
        } else if (targetPage !== null) {
            state.page = targetPage;
        }

        const requestedPage = state.page;
        const cacheKey = getCacheKey(state.accountId, state.folder, requestedPage);

        // Sofort aus Cache anzeigen, wenn vorhanden und kein expliziter Bypass gefordert ist
        if (!bypassCache && pageCache.has(cacheKey)) {
            const cached = pageCache.get(cacheKey);
            state.messages = cached.messages;
            state.total = cached.total;
            state.unseen = cached.unseen;
            state.totalPages = Math.max(1, Math.ceil(state.total / state.limit));

            updateListHeaderCounts();
            updateFolderSidebarBadge(state.accountId, state.folder, state.unseen);
            renderMessages(state.messages);
            updatePagination();

            prefetchNeighborPages(state.accountId, state.folder, state.page, state.totalPages);
            return;
        }

        if (state.loading) return;
        state.loading = true;
        showListLoading(true, requestedPage);

        try {
            const offset = (requestedPage - 1) * state.limit;
            const url = `/mail-client/api/messages?account=${state.accountId}&folder=${encodeURIComponent(state.folder)}&limit=${state.limit}&offset=${offset}`;
            const data = await apiFetch(url);

            // Prüfen, ob der Nutzer inzwischen woanders hin navigiert ist
            if (state.page !== requestedPage) {
                return;
            }

            state.total = data.total || 0;
            state.unseen = data.unseen || 0;
            state.totalPages = Math.max(1, Math.ceil(state.total / state.limit));
            state.messages = data.messages || [];

            // Seite im Cache ablegen
            setCachedPage(state.accountId, state.folder, requestedPage, data);

            // Header & Badge aktualisieren
            updateListHeaderCounts();
            updateFolderSidebarBadge(state.accountId, state.folder, state.unseen);

            // Nachrichten rendern & Paginierung aktualisieren
            renderMessages(state.messages);
            updatePagination();

            // Nachbarseiten (3 vor und zurück) im Hintergrund vorausladen
            prefetchNeighborPages(state.accountId, state.folder, state.page, state.totalPages);
        } catch (err) {
            showListError(err.message);
        } finally {
            state.loading = false;
            showListLoading(false);
            updatePagination();
        }
    }

    function renderMessages(messages) {
        $.messageList.innerHTML = '';
        $.messageList.scrollTop = 0;
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
                const totalText = `${mcFormatNumber(state.total || 0)} ${(state.total || 0) === 1 ? "Nachricht" : "Nachrichten"}`;
                const unseenText = next > 0 ? ` (${mcFormatNumber(next)} ungelesen)` : "";
                $.listUnseenCount.textContent = `${totalText}${unseenText}`;
                $.listUnseenCount.classList.remove("d-none");
            }
        }
    }

    async function toggleMessageFlag(accountId, folder, uid, flag, setFlag) {
        const msg = state.messages.find(m => m.uid === uid);
        if (msg) {
            if (flag === 'flagged') msg.is_flagged = setFlag;
            if (flag === 'seen') msg.is_seen = setFlag;
        }

        const cacheKey = getCacheKey(accountId, folder, state.page);
        if (pageCache.has(cacheKey)) {
            const cached = pageCache.get(cacheKey);
            const cachedMsg = (cached.messages || []).find(m => m.uid === uid);
            if (cachedMsg) {
                if (flag === 'flagged') cachedMsg.is_flagged = setFlag;
                if (flag === 'seen') cachedMsg.is_seen = setFlag;
            }
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
            if (pageCache.has(cacheKey)) {
                const cached = pageCache.get(cacheKey);
                const cachedMsg = (cached.messages || []).find(m => m.uid === uid);
                if (cachedMsg) {
                    if (flag === 'flagged') cachedMsg.is_flagged = !setFlag;
                    if (flag === 'seen') cachedMsg.is_seen = !setFlag;
                }
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
        // Spam-Klick-Schutz: Verhindert versehentliches Überspringen während eines Ladevorgangs
        if (state.loading) return;
        if (page < 1 || (state.totalPages && page > state.totalPages) || page === state.page) return;

        clearPreview();

        const cacheKey = getCacheKey(state.accountId, state.folder, page);
        if (pageCache.has(cacheKey)) {
            const cached = pageCache.get(cacheKey);
            state.page = page;
            state.messages = cached.messages;
            state.total = cached.total;
            state.unseen = cached.unseen;
            state.totalPages = Math.max(1, Math.ceil(state.total / state.limit));

            updateListHeaderCounts();
            renderMessages(state.messages);
            updatePagination();

            // Nachbarseiten weiter im Hintergrund vorausladen
            prefetchNeighborPages(state.accountId, state.folder, state.page, state.totalPages);
            return;
        }

        // Seite noch nicht gecacht: Sofortiges visuelles Feedback & Laden
        state.page = page;
        if ($.pageInput) $.pageInput.value = page;
        await loadMessages(false, page);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Mail-Vorschau (UNTER der Liste)
    // ═══════════════════════════════════════════════════════════════════════════
    function selectMessage(row, accountId, folder, uid) {
        mcPrioritizeUserAction();
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
        const cleanSubject = decodeMimeWords(data.subject || '(kein Betreff)');
        let fromName = decodeMimeWords(data.from_name || '');
        let fromEmail = decodeMimeWords(data.from_email || data.from || '');
        if (/^(.*?)\s*<([^>]+)>?$/.test(fromEmail)) {
            const m = fromEmail.match(/^(.*?)\s*<([^>]+)>?$/);
            if (!fromName) fromName = m[1].replace(/^[\"\'\s]+|[\"\'\s]+$/g, '');
            fromEmail = m[2];
        }
        if (/^(.*?)\s*<([^>]+)>?$/.test(fromName)) {
            const m = fromName.match(/^(.*?)\s*<([^>]+)>?$/);
            fromName = m[1].replace(/^[\"\'\s]+|[\"\'\s]+$/g, '');
            if (!fromEmail) fromEmail = m[2];
        }
        const fromDisplay = fromName ? `${fromName} <${fromEmail}>` : fromEmail;
        const cleanTo = decodeMimeWords(data.to || '(Kein Empfänger)');
        const cleanCc = decodeMimeWords(data.cc || '');

        const header = $.previewPane.querySelector('.mc-preview-header');
        if (header) {
            header.innerHTML = `
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div class="mc-preview-subject flex-grow-1">${escHtml(cleanSubject)}</div>
                    <button type="button" class="btn btn-sm btn-link p-1 mc-btn-pin-header" id="mc-btn-pin-header" title="${isPinned ? 'Header lösen (scrollt mit)' : 'Header fixieren (bleibt oben stehen)'}">
                        <i class="bi ${isPinned ? 'bi-pin-angle-fill text-primary' : 'bi-pin-angle'}"></i>
                    </button>
                </div>
                <div class="mc-preview-meta">
                    <div class="mc-preview-meta-row">
                        <span class="mc-preview-meta-label">Von:</span>
                        <span>${escHtml(fromDisplay)}</span>
                    </div>
                    <div class="mc-preview-meta-row">
                        <span class="mc-preview-meta-label">An:</span>
                        <span>${escHtml(cleanTo)}</span>
                    </div>
                    ${cleanCc ? `<div class="mc-preview-meta-row"><span class="mc-preview-meta-label">CC:</span><span>${escHtml(cleanCc)}</span></div>` : ''}
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
                <a href="/mail-client/compose?reply_to=${encodeURIComponent(fromEmail)}&subject=${encodeURIComponent('Re: ' + (cleanSubject || ''))}&account=${data.account_id}&to_addr=${encodeURIComponent(cleanTo)}" class="btn btn-sm btn-outline-primary">
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
            const senderToTrust = data.from_email || data.from;
            blockedBanner.innerHTML = `
                <i class="bi bi-shield-exclamation text-warning"></i>
                <span>${data.blocked_images} externe Bild(er) blockiert.</span>
                <button class="btn btn-link btn-sm p-0 ms-2 text-decoration-none fw-semibold" 
                        onclick="mailClientApp.allowExternal('${encodeURIComponent(senderToTrust)}', this)">
                    <i class="bi bi-shield-check"></i> Absender vertrauen
                </button>
                <button class="btn btn-link btn-sm p-0 ms-2 text-secondary text-decoration-none" 
                        onclick="mailClientApp.openSettings('whitelist')">
                    <i class="bi bi-gear"></i> Verwalten
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

        // Kalender-Events (.ics / VCALENDAR)
        let calBox = $.previewPane.querySelector('.mc-calendar-events-container');
        if (data.calendar_events && data.calendar_events.length > 0) {
            state.currentCalendarEvents = data.calendar_events;
            if (!calBox) {
                calBox = document.createElement('div');
                calBox.className = 'mc-calendar-events-container px-3 pt-2';
                const headerWrap = document.getElementById('mc-preview-header-wrap') || $.previewPane;
                headerWrap.appendChild(calBox);
            }
            const count = data.calendar_events.length;
            const multiHeader = count > 1 ? `
                <div class="alert alert-primary py-2 px-3 mb-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <span class="small fw-semibold">
                        <i class="bi bi-calendar-week me-1"></i> Diese E-Mail enthält ${count} Termine:
                    </span>
                    <button type="button" class="btn btn-xs btn-primary" onclick="mailClientApp.openCalendarModal('all')">
                        <i class="bi bi-check-all me-1"></i> Alle Termine eintragen
                    </button>
                </div>
            ` : '';

            calBox.innerHTML = multiHeader + data.calendar_events.map((ev, idx) => `
                <div class="mc-calendar-invite-card mb-2" id="mc-cal-card-${idx}">
                    <div class="mc-cal-invite-header">
                        <div class="d-flex align-items-center gap-3">
                            <div class="mc-cal-invite-icon">
                                <i class="bi bi-calendar-event"></i>
                            </div>
                            <div class="mc-cal-invite-meta">
                                <div class="mc-cal-invite-title">${escHtml(ev.title || 'Termin')}</div>
                                <div class="mc-cal-invite-details">
                                    <span><i class="bi bi-clock"></i> ${escHtml(ev.formatted_date || ev.start_at)}</span>
                                    ${ev.location ? `<span><i class="bi bi-geo-alt"></i> ${escHtml(ev.location)}</span>` : ''}
                                    ${ev.recurrence_rule ? `<span><i class="bi bi-repeat"></i> Wiederkehrend</span>` : ''}
                                </div>
                            </div>
                        </div>
                        <div class="mc-cal-invite-actions" id="mc-cal-actions-${idx}">
                            ${ev.already_in_calendar ? `
                                <span class="badge bg-success-subtle text-success border border-success-subtle py-2 px-3 d-inline-flex align-items-center gap-1">
                                    <i class="bi bi-check-circle-fill"></i> Im Kalender eingetragen
                                </span>
                                <a href="/calendar" target="_blank" class="btn btn-sm btn-link text-decoration-none py-0">Ansehen</a>
                            ` : `
                                <button type="button" class="btn btn-sm btn-primary" onclick="mailClientApp.openCalendarModal(${idx})">
                                    <i class="bi bi-calendar-plus me-1"></i> In Kalender eintragen...
                                </button>
                            `}
                        </div>
                    </div>
                    ${ev.description ? `<div class="small text-secondary mt-1 border-top pt-2" style="white-space:pre-wrap; max-height:80px; overflow-y:auto;">${escHtml(ev.description)}</div>` : ''}
                </div>
            `).join('');
        } else if (calBox) {
            calBox.remove();
            state.currentCalendarEvents = [];
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
            attBar.innerHTML = data.attachments.map(att => {
                const isCal = att.is_calendar;
                const calIcon = isCal ? 'bi-calendar-event text-primary' : 'bi-paperclip';
                let calActionHtml = '';
                if (isCal && data.calendar_events && data.calendar_events.length > 0) {
                    const matchIdx = data.calendar_events.findIndex(e => e.part_id === att.part_id || e.filename === att.filename);
                    const targetIdx = matchIdx >= 0 ? matchIdx : 0;
                    const ev = data.calendar_events[targetIdx];
                    if (ev && !ev.already_in_calendar) {
                        calActionHtml = `
                            <button type="button" class="btn btn-xs btn-primary mc-att-import-cal-btn ms-1" 
                                    onclick="event.preventDefault(); event.stopPropagation(); mailClientApp.openCalendarModal(${targetIdx});" 
                                    title="In Kalender eintragen">
                                <i class="bi bi-calendar-plus"></i> In Kalender
                            </button>
                        `;
                    }
                }
                return `
                    <div class="d-inline-flex align-items-center me-2 mb-1">
                        <a href="/mail-client/api/attachment?account=${data.account_id}&folder=${encodeURIComponent(data.folder)}&uid=${data.uid}&part=${encodeURIComponent(att.part_id)}" 
                           class="mc-attachment-chip" download="${escHtml(att.filename)}">
                            <i class="bi ${calIcon}"></i>
                            ${escHtml(att.filename)}
                            <span class="text-muted">(${formatBytes(att.size)})</span>
                        </a>
                        ${calActionHtml}
                    </div>
                `;
            }).join('');
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
        $.previewPane.querySelector('.mc-calendar-events-container')?.remove();
        state.currentCalendarEvents = [];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Öffentliche Aktionen (von HTML-Inline-Handlern aufgerufen)
    // ═══════════════════════════════════════════════════════════════════════════
    function populateCalendarSelect(selectEl, selectedId = 0) {
        if (!selectEl) return;
        const cals = state.userCalendars || [];
        if (cals.length === 0) {
            selectEl.innerHTML = '<option value="0">Standard-Kalender</option>';
            return;
        }
        selectEl.innerHTML = cals.map(c => {
            const isSel = selectedId ? c.id === selectedId : c.is_default;
            const srcLabel = c.source === 'google' ? ' (Google Calendar)' : ' (Lokal)';
            return `<option value="${c.id}" ${isSel ? 'selected' : ''}>${escHtml(c.name)}${srcLabel}</option>`;
        }).join('');
    }

    window.mailClientApp = {
        async openCalendarModal(target) {
            if (!state.userCalendars || state.userCalendars.length === 0) {
                try {
                    const res = await apiFetch('/mail-client/api/calendar/calendars');
                    if (res && Array.isArray(res.calendars)) {
                        state.userCalendars = res.calendars;
                    }
                } catch (_) {}
            }

            const modalEl = document.getElementById('mcCalendarImportModal');
            if (!modalEl) return;

            const selectEl  = document.getElementById('mc-import-cal-select');
            const titleEl   = document.getElementById('mc-import-cal-title');
            const allDayEl  = document.getElementById('mc-import-cal-allday');
            const startEl   = document.getElementById('mc-import-cal-start');
            const endEl     = document.getElementById('mc-import-cal-end');
            const locEl     = document.getElementById('mc-import-cal-location');
            const descEl    = document.getElementById('mc-import-cal-description');
            const rruleWrap = document.getElementById('mc-import-cal-rrule-wrap');
            const rruleTxt  = document.getElementById('mc-import-cal-rrule-text');
            const rruleHid  = document.getElementById('mc-import-cal-rrule');
            const idxHid    = document.getElementById('mc-import-cal-event-idx');
            const submitBtn = document.getElementById('mc-import-cal-btn-submit');

            populateCalendarSelect(selectEl);

            if (target === 'all') {
                idxHid.value = 'all';
                const count = state.currentCalendarEvents?.length || 0;
                titleEl.value = `Alle ${count} Termine aus dieser E-Mail`;
                titleEl.disabled = true;
                allDayEl.checked = false;
                allDayEl.disabled = true;
                startEl.closest('.row').classList.add('d-none');
                locEl.closest('.mb-3').classList.add('d-none');
                descEl.closest('.mb-3').classList.add('d-none');
                rruleWrap.classList.add('d-none');
                submitBtn.innerHTML = `<i class="bi bi-check-all me-1"></i> Alle ${count} Termine speichern`;
            } else {
                idxHid.value = target;
                const ev = state.currentCalendarEvents?.[target];
                if (!ev) return;

                titleEl.disabled = false;
                allDayEl.disabled = false;
                startEl.closest('.row').classList.remove('d-none');
                locEl.closest('.mb-3').classList.remove('d-none');
                descEl.closest('.mb-3').classList.remove('d-none');

                titleEl.value = ev.title || 'Termin';
                allDayEl.checked = !!ev.all_day;

                const formatForInput = (dtStr, isAllDay) => {
                    if (!dtStr) return '';
                    const d = new Date(dtStr.replace(' ', 'T'));
                    if (isNaN(d.getTime())) return dtStr.substring(0, 16);
                    const pad = n => String(n).padStart(2, '0');
                    if (isAllDay) {
                        return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
                    }
                    return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
                };

                const updateDateInputs = () => {
                    if (allDayEl.checked) {
                        startEl.type = 'date';
                        endEl.type   = 'date';
                        startEl.value = formatForInput(ev.start_at, true);
                        endEl.value   = formatForInput(ev.end_at || ev.start_at, true);
                    } else {
                        startEl.type = 'datetime-local';
                        endEl.type   = 'datetime-local';
                        startEl.value = formatForInput(ev.start_at, false);
                        endEl.value   = formatForInput(ev.end_at || ev.start_at, false);
                    }
                };

                allDayEl.onchange = updateDateInputs;
                updateDateInputs();

                locEl.value = ev.location || '';
                descEl.value = ev.description || '';

                if (ev.recurrence_rule) {
                    rruleHid.value = ev.recurrence_rule;
                    rruleTxt.textContent = ev.recurrence_rule;
                    rruleWrap.classList.remove('d-none');
                } else {
                    rruleHid.value = '';
                    rruleWrap.classList.add('d-none');
                }

                submitBtn.innerHTML = '<i class="bi bi-calendar-plus me-1"></i> In Kalender speichern';
            }

            if (window.bootstrap && window.bootstrap.Modal) {
                const modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
                modal.show();
            }
        },

        async submitCalendarImport() {
            const modalEl   = document.getElementById('mcCalendarImportModal');
            const selectEl  = document.getElementById('mc-import-cal-select');
            const titleEl   = document.getElementById('mc-import-cal-title');
            const allDayEl  = document.getElementById('mc-import-cal-allday');
            const startEl   = document.getElementById('mc-import-cal-start');
            const endEl     = document.getElementById('mc-import-cal-end');
            const locEl     = document.getElementById('mc-import-cal-location');
            const descEl    = document.getElementById('mc-import-cal-description');
            const rruleHid  = document.getElementById('mc-import-cal-rrule');
            const idxHid    = document.getElementById('mc-import-cal-event-idx');
            const submitBtn = document.getElementById('mc-import-cal-btn-submit');

            const calId = parseInt(selectEl.value, 10) || 0;
            const target = idxHid.value;

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Speichere...';

            const csrf = document.querySelector('[data-csrf]')?.dataset?.csrf || '';

            try {
                if (target === 'all') {
                    const events = state.currentCalendarEvents || [];
                    for (let i = 0; i < events.length; i++) {
                        const ev = events[i];
                        if (ev.already_in_calendar) continue;
                        await fetch('/mail-client/api/calendar/add-event', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf },
                            credentials: 'same-origin',
                            body: JSON.stringify({
                                calendar_id: calId,
                                title: ev.title,
                                description: ev.description,
                                location: ev.location,
                                start_at: ev.start_at,
                                end_at: ev.end_at,
                                all_day: ev.all_day,
                                recurrence_rule: ev.recurrence_rule,
                            })
                        });
                        ev.already_in_calendar = true;
                    }
                } else {
                    const idx = parseInt(target, 10);
                    const ev = state.currentCalendarEvents?.[idx];
                    const resp = await fetch('/mail-client/api/calendar/add-event', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf },
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            calendar_id: calId,
                            title: titleEl.value,
                            description: descEl.value,
                            location: locEl.value,
                            start_at: startEl.value,
                            end_at: endEl.value,
                            all_day: allDayEl.checked,
                            recurrence_rule: rruleHid.value,
                        })
                    });
                    const res = await resp.json();
                    if (!res.success) throw new Error(res.error || 'Fehler beim Speichern');
                    if (ev) {
                        ev.already_in_calendar = true;
                        ev.calendar_name = res.calendar_name;
                    }
                }

                if (window.bootstrap && window.bootstrap.Modal) {
                    const modal = window.bootstrap.Modal.getInstance(modalEl);
                    modal?.hide();
                }

                if (target === 'all') {
                    (state.currentCalendarEvents || []).forEach((ev, i) => {
                        const actionsDiv = document.getElementById(`mc-cal-actions-${i}`);
                        if (actionsDiv) {
                            actionsDiv.innerHTML = `
                                <span class="badge bg-success-subtle text-success border border-success-subtle py-2 px-3 d-inline-flex align-items-center gap-1">
                                    <i class="bi bi-check-circle-fill"></i> Im Kalender eingetragen
                                </span>
                                <a href="/calendar" target="_blank" class="btn btn-sm btn-link text-decoration-none py-0">Ansehen</a>
                            `;
                        }
                    });
                } else {
                    const actionsDiv = document.getElementById(`mc-cal-actions-${target}`);
                    if (actionsDiv) {
                        actionsDiv.innerHTML = `
                            <span class="badge bg-success-subtle text-success border border-success-subtle py-2 px-3 d-inline-flex align-items-center gap-1">
                                <i class="bi bi-check-circle-fill"></i> Im Kalender eingetragen
                            </span>
                            <a href="/calendar" target="_blank" class="btn btn-sm btn-link text-decoration-none py-0">Ansehen</a>
                        `;
                    }
                }

                document.querySelectorAll('.mc-att-import-cal-btn').forEach(b => b.remove());

                try {
                    loadCalendarUpcoming(true);
                } catch (_) {}
            } catch (err) {
                alert('Speichern fehlgeschlagen: ' + err.message);
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-calendar-plus me-1"></i> In Kalender speichern';
            }
        },

        addEventToCalendar(idx) {
            this.openCalendarModal(idx);
        },
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
                clearFolderPageCache(accountId, folder);
                // Zeile aus Liste entfernen
                const row = $.messageList?.querySelector(`.mc-message-row[data-uid="${uid}"]`);
                row?.remove();
                clearPreview();
                if (state.total > 0) {
                    state.total--;
                    state.totalPages = Math.max(1, Math.ceil(state.total / state.limit));
                    updateListHeaderCounts();
                    updatePagination();
                }
            } catch (err) {
                alert('Löschen fehlgeschlagen: ' + err.message);
            }
        },

        async allowExternal(senderEncoded, btn) {
            const sender = decodeURIComponent(senderEncoded);
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Speichern...';
            }
            try {
                const res = await apiFetch('/mail-client/api/whitelist', {
                    method: 'POST',
                    body: { scope_type: 'sender', scope_value: sender },
                });
                if (res && Array.isArray(res.whitelist)) {
                    this.renderWhitelist(res.whitelist);
                }
                // Vorschau neu laden mit erlaubten externen Bildern
                if (state.uid && state.accountId && state.folder) {
                    await loadPreview(state.accountId, state.folder, state.uid);
                }
            } catch (err) {
                alert('Whitelist konnte nicht aktualisiert werden: ' + err.message);
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-shield-check"></i> Absender vertrauen';
                }
            }
        },

        openSettings(activeTab = 'general') {
            const modalEl = document.getElementById('mcSettingsModal');
            if (!modalEl) return;
            if (window.bootstrap && bootstrap.Modal) {
                const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                modal.show();
            }
            if (activeTab === 'whitelist') {
                const triggerTab = document.getElementById('mc-settings-tab-whitelist');
                if (triggerTab && window.bootstrap && bootstrap.Tab) {
                    bootstrap.Tab.getOrCreateInstance(triggerTab).show();
                }
                this.loadWhitelist();
            }
        },

        async loadWhitelist() {
            const tbody = document.getElementById('mc-whitelist-tbody');
            const emptyEl = document.getElementById('mc-whitelist-empty');
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center text-secondary py-3"><span class="spinner-border spinner-border-sm me-1"></span> Lade vertraute Absender...</td></tr>';
            }
            try {
                const res = await apiFetch('/mail-client/api/whitelist');
                const list = res && Array.isArray(res.whitelist) ? res.whitelist : [];
                this.renderWhitelist(list);
            } catch (err) {
                if (tbody) {
                    tbody.innerHTML = `<tr><td colspan="4" class="text-center text-danger py-2">Fehler beim Laden: ${escHtml(err.message)}</td></tr>`;
                }
            }
        },

        renderWhitelist(list) {
            const tbody = document.getElementById('mc-whitelist-tbody');
            const emptyEl = document.getElementById('mc-whitelist-empty');
            const countBadge = document.getElementById('mc-whitelist-count');
            if (countBadge) countBadge.textContent = list.length;
            if (!tbody) return;

            if (list.length === 0) {
                tbody.innerHTML = '';
                if (emptyEl) emptyEl.classList.remove('d-none');
                return;
            }

            if (emptyEl) emptyEl.classList.add('d-none');
            tbody.innerHTML = list.map(item => {
                const isDomain = item.scope_type === 'domain';
                const badge = isDomain
                    ? '<span class="badge bg-info-subtle text-info border border-info-subtle"><i class="bi bi-globe me-1"></i> Domain</span>'
                    : '<span class="badge bg-primary-subtle text-primary border border-primary-subtle"><i class="bi bi-envelope me-1"></i> Absender</span>';
                const dateStr = item.created_at ? escHtml(item.created_at.substring(0, 16).replace('T', ' ')) : '';
                return `
                    <tr>
                        <td class="align-middle">${badge}</td>
                        <td class="align-middle font-monospace small"><strong>${escHtml(item.scope_value)}</strong></td>
                        <td class="align-middle text-secondary small">${dateStr}</td>
                        <td class="align-middle text-end">
                            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" 
                                    title="Aus Whitelist entfernen"
                                    onclick="mailClientApp.removeWhitelistEntry(${item.id})">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        },

        async addWhitelistEntry() {
            const valInput = document.getElementById('mc-whitelist-add-val');
            const typeSelect = document.getElementById('mc-whitelist-add-type');
            const addBtn = document.getElementById('mc-whitelist-add-btn');
            if (!valInput || !valInput.value.trim()) return;

            const val = valInput.value.trim();
            const type = typeSelect ? typeSelect.value : 'sender';
            if (addBtn) { addBtn.disabled = true; addBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>'; }

            try {
                const res = await apiFetch('/mail-client/api/whitelist', {
                    method: 'POST',
                    body: { scope_type: type, scope_value: val }
                });
                valInput.value = '';
                if (res && Array.isArray(res.whitelist)) {
                    this.renderWhitelist(res.whitelist);
                } else {
                    await this.loadWhitelist();
                }
                if (state.uid && state.accountId && state.folder) {
                    await loadPreview(state.accountId, state.folder, state.uid);
                }
            } catch (err) {
                alert('Eintrag konnte nicht hinzugefügt werden: ' + err.message);
            } finally {
                if (addBtn) { addBtn.disabled = false; addBtn.innerHTML = '<i class="bi bi-plus-lg me-1"></i> Hinzufügen'; }
            }
        },

        async removeWhitelistEntry(id) {
            if (!confirm('Diesen Eintrag wirklich aus den vertrauten Absendern entfernen?')) return;
            try {
                const res = await apiFetch('/mail-client/api/whitelist/delete', {
                    method: 'POST',
                    body: { id: id }
                });
                if (res && Array.isArray(res.whitelist)) {
                    this.renderWhitelist(res.whitelist);
                } else {
                    await this.loadWhitelist();
                }
                if (state.uid && state.accountId && state.folder) {
                    await loadPreview(state.accountId, state.folder, state.uid);
                }
            } catch (err) {
                alert('Löschen fehlgeschlagen: ' + err.message);
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
    function showListLoading(show, page = null) {
        if (!$.messageList) return;
        let overlay = document.getElementById('mc-list-page-loading');
        if (show) {
            $.messageList.classList.add('mc-list-loading');
            if (page !== null && page !== undefined) {
                if (!overlay && $.listPane) {
                    overlay = document.createElement('div');
                    overlay.id = 'mc-list-page-loading';
                    overlay.className = 'mc-list-loading-overlay';
                    $.listPane.appendChild(overlay);
                }
                if (overlay) {
                    overlay.innerHTML = `<span class="spinner-border spinner-border-sm text-primary me-2"></span><span>Lade Seite ${mcFormatNumber(page)}…</span>`;
                    overlay.style.display = 'inline-flex';
                }
            } else {
                if ($.messageList.children.length === 0) {
                    let spinner = $.messageList.querySelector('.mc-list-spinner');
                    if (!spinner) {
                        spinner = document.createElement('div');
                        spinner.className = 'mc-list-spinner text-center py-5 text-secondary';
                        spinner.innerHTML = '<span class="spinner-border text-primary mb-2"></span><div>Nachrichten werden geladen…</div>';
                        $.messageList.appendChild(spinner);
                    }
                }
            }

            // Paginierungs-Buttons während des Ladens sperren (verhindert Überspringen)
            if ($.btnPageFirst) $.btnPageFirst.disabled = true;
            if ($.btnPagePrev)  $.btnPagePrev.disabled  = true;
            if ($.btnPageNext)  $.btnPageNext.disabled  = true;
            if ($.btnPageLast)  $.btnPageLast.disabled  = true;
        } else {
            $.messageList.classList.remove('mc-list-loading');
            if (overlay) {
                overlay.style.display = 'none';
            }
            const spinner = $.messageList.querySelector('.mc-list-spinner');
            if (spinner) spinner.remove();
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
            document.body.classList.add('mc-frameless', 'mc-app-mode');
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
                document.body.classList.add('mc-maximized', 'mc-app-mode');
                if (btnMaximize) {
                    const icon = btnMaximize.querySelector('i');
                    if (icon) icon.className = 'bi bi-fullscreen-exit';
                    if (textMaximize) textMaximize.textContent = 'Verkleinern';
                    btnMaximize.title = 'Maximierung beenden (Esc)';
                }
                try { localStorage.setItem('mc_is_maximized', '1'); } catch (_) {}
            } else {
                document.body.classList.remove('mc-maximized');
                if (!window.matchMedia('(display-mode: standalone)').matches && !(window.navigator && window.navigator.standalone)) {
                    document.body.classList.remove('mc-app-mode');
                }
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

        const autoRefreshSelect = document.getElementById('mcSettingAutoRefresh');
        if (autoRefreshSelect) {
            autoRefreshSelect.addEventListener('change', () => {
                applySettings({ autoRefresh: parseInt(autoRefreshSelect.value, 10) });
            });
        }

        const settingsModalEl = document.getElementById('mcSettingsModal');
        if (settingsModalEl) {
            settingsModalEl.addEventListener('show.bs.modal', () => {
                syncSettingsModalUI();
                mailClientApp.loadWhitelist();
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

        const autoRefreshSelect = document.getElementById('mcSettingAutoRefresh');
        if (autoRefreshSelect) {
            autoRefreshSelect.value = String(settings.autoRefresh !== undefined ? settings.autoRefresh : 120);
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Ordner-Aktualisierung (Manuell / Auto-Refresh)
    // ══════════════════════════════════════════════════════════════════════════
    let _mcRefreshing = false;
    let _mcAutoRefreshTimer = null;
    let _mcLastRefreshTime = Date.now();

    async function refreshCurrentFolder(isManual = false) {
        if (_mcRefreshing) return;
        if (!state.accountId || !state.folder) {
            if (isManual) {
                alert('Bitte zuerst einen Ordner auswählen.');
            }
            return;
        }

        _mcRefreshing = true;
        _mcLastRefreshTime = Date.now();

        const btnRefresh = document.getElementById('mc-btn-refresh');
        const btnListRefresh = document.getElementById('mc-list-refresh');
        const icon1 = btnRefresh?.querySelector('i');
        const icon2 = btnListRefresh?.querySelector('i');

        if (icon1) icon1.classList.add('mc-spin');
        if (icon2) icon2.classList.add('mc-spin');
        if (btnRefresh) btnRefresh.disabled = true;
        if (btnListRefresh) btnListRefresh.disabled = true;

        try {
            const csrf = state.csrfToken || document.querySelector('[data-csrf]')?.dataset?.csrf || '';
            const params = new URLSearchParams({
                account: state.accountId,
                folder: state.folder,
            });
            const resp = await fetch('/mail-client/api/sync/refresh?' + params, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf },
                credentials: 'same-origin',
            });
            if (!resp.ok) {
                console.warn('[MailClient] Refresh endpoint error:', resp.status);
            }
            // Cache für diesen Ordner verwerfen, damit frische Daten vom Server geholt werden
            clearFolderPageCache(state.accountId, state.folder);
            // Nachrichtenliste still aktualisieren (kein Reset von Seite/Preview, aber Cache umgehen)
            await loadMessages(false, null, true);
        } catch (e) {
            console.error('[MailClient] Refresh failed:', e);
        } finally {
            _mcRefreshing = false;
            if (icon1) icon1.classList.remove('mc-spin');
            if (icon2) icon2.classList.remove('mc-spin');
            if (btnRefresh) btnRefresh.disabled = false;
            if (btnListRefresh) btnListRefresh.disabled = false;
        }
    }

    function startAutoRefreshTimer() {
        if (_mcAutoRefreshTimer) {
            clearInterval(_mcAutoRefreshTimer);
            _mcAutoRefreshTimer = null;
        }
        const intervalSec = parseInt(settings.autoRefresh, 10);
        if (!intervalSec || intervalSec <= 0) return;

        _mcAutoRefreshTimer = setInterval(() => {
            if (document.visibilityState === 'visible' && state.accountId && state.folder && !_mcRefreshing && !state.loading && !_mcSyncAllRunning) {
                refreshCurrentFolder(false);
            }
        }, intervalSec * 1000);
    }

    function restartAutoRefreshTimer() {
        startAutoRefreshTimer();
    }

    // Beim Tab-Wechsel zurück in den Vordergrund prüfen, ob Intervall abgelaufen ist
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            const intervalSec = parseInt(settings.autoRefresh, 10);
            if (intervalSec > 0 && state.accountId && state.folder && !_mcRefreshing && !state.loading && !_mcSyncAllRunning) {
                const elapsedSec = (Date.now() - _mcLastRefreshTime) / 1000;
                if (elapsedSec >= intervalSec) {
                    refreshCurrentFolder(false);
                }
            }
        }
    });

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

        // Ordner abrufen (Toolbar)
        const btnRefresh = document.getElementById('mc-btn-refresh');
        if (btnRefresh) {
            btnRefresh.addEventListener('click', () => refreshCurrentFolder(true));
        }

        // Ordner abrufen (Listen-Kopfzeile)
        const btnListRefresh = document.getElementById('mc-list-refresh');
        if (btnListRefresh) {
            btnListRefresh.addEventListener('click', () => refreshCurrentFolder(true));
        }

        // Alle Ordner synchronisieren Button binden
        const btnSyncAll = document.getElementById('mc-btn-sync-all');
        if (btnSyncAll) {
            btnSyncAll.addEventListener('click', syncAllFolders);
        }

        // Auto-Refresh starten
        startAutoRefreshTimer();
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
    window.mailClientApp.refreshCurrentFolder = refreshCurrentFolder;
    window.mcRefreshCurrentFolder = refreshCurrentFolder;

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
let _mcLastUserActionTime  = 0;
const MAX_SYNC_RETRIES     = 5;

/**
 * Unterbricht laufenden Hintergrund-Sync sofort, wenn der Nutzer interagiert (Mail anklicken, Blättern, etc.),
 * damit die Anfrage des Nutzers volle Priorität hat. Der Sync wird nach 1.5s Nutzer-Inaktivität fortgesetzt.
 */
function mcPrioritizeUserAction() {
    _mcLastUserActionTime = Date.now();
    if (_mcSyncAbortController !== null) {
        try { _mcSyncAbortController.abort(); } catch (_) {}
        _mcSyncAbortController = null;
    }
    if (_mcSyncTimer !== null) {
        clearTimeout(_mcSyncTimer);
        _mcSyncTimer = null;
    }
    if (_mcSyncAccountId && _mcSyncFolder) {
        _mcSyncTimer = setTimeout(_mcSyncPollStep, 1500);
    }
}
window.mcPrioritizeUserAction = mcPrioritizeUserAction;

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

    if (Date.now() - _mcLastUserActionTime < 1500) {
        _mcSyncTimer = setTimeout(_mcSyncPollStep, 1500);
        return;
    }

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
