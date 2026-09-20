/**
 * Mail-Client – Frontend-Logik
 *
 * Funktionen:
 * - Resizable Panes (Sidebar + Liste) mit Drag-and-Drop, gespeichert in localStorage
 * - AJAX-basierte Ordner-Navigation (kein Seitenneuladen)
 * - AJAX-basiertes Nachrichten-Laden mit Pagination
 * - Mail-Vorschau per AJAX in sandboxed iframe
 * - Doppelklick → neuer Tab (message-full.php)
 * - Flag-Aktionen (gelesen, markiert, löschen) per AJAX
 * - Absender-Whitelist (externe Bilder freigeben)
 * - Fehlerbehandlung: bei kritischen Fehlern → Seitenneuladen (aber User bleibt an gleicher Stelle)
 * - Responsive: Sidebar / Vorschau auf Mobile als Overlay
 */

(function () {
    'use strict';

    // ─────────────────────────────────────────────────────────────────────────
    // State
    // ─────────────────────────────────────────────────────────────────────────
    const state = {
        accountId: 0,
        folder: '',
        uid: 0,
        offset: 0,
        limit: 50,
        loading: false,
        hasMore: false,
        csrfToken: '',
    };

    // ─────────────────────────────────────────────────────────────────────────
    // DOM-Referenzen (lazy, nach DOMContentLoaded)
    // ─────────────────────────────────────────────────────────────────────────
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
            loadMoreBtn:    document.querySelector('#mc-load-more'),
            csrfInput:      document.querySelector('[name="_csrf"]') || document.querySelector('[data-csrf]'),
        };

        // CSRF-Token aus Seite lesen
        if ($.csrfInput) {
            state.csrfToken = $.csrfInput.value || $.csrfInput.dataset.csrf || '';
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Resize-Logik
    // ─────────────────────────────────────────────────────────────────────────
    function initResizers() {
        setupResizer($.sidebarResizer, $.sidebar, 'mc-sidebar-width', 140, 380, 'width');
        setupResizer($.listResizer, $.listPane, 'mc-list-width', 200, 600, 'width');
    }

    function setupResizer(resizer, pane, storageKey, min, max, prop) {
        if (!resizer || !pane) return;

        // Gespeicherte Größe wiederherstellen
        const saved = localStorage.getItem(storageKey);
        if (saved) {
            pane.style[prop] = Math.min(max, Math.max(min, parseInt(saved, 10))) + 'px';
        }

        let startPos = 0;
        let startSize = 0;
        let dragging = false;

        const onMove = (e) => {
            if (!dragging) return;
            const clientX = e.touches ? e.touches[0].clientX : e.clientX;
            const delta = clientX - startPos;
            const newSize = Math.min(max, Math.max(min, startSize + delta));
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

        resizer.addEventListener('mousedown', (e) => {
            dragging = true;
            startPos = e.clientX;
            startSize = parseInt(pane.style[prop] || getComputedStyle(pane)[prop], 10);
            resizer.classList.add('dragging');
            document.body.style.cursor = 'col-resize';
            document.body.style.userSelect = 'none';
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onEnd);
            e.preventDefault();
        });

        resizer.addEventListener('touchstart', (e) => {
            dragging = true;
            startPos = e.touches[0].clientX;
            startSize = parseInt(pane.style[prop] || getComputedStyle(pane)[prop], 10);
            document.addEventListener('touchmove', onMove, { passive: false });
            document.addEventListener('touchend', onEnd);
        }, { passive: true });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // API-Helfer
    // ─────────────────────────────────────────────────────────────────────────
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

    // ─────────────────────────────────────────────────────────────────────────
    // Ordner-Navigation
    // ─────────────────────────────────────────────────────────────────────────
    function bindFolderClicks() {
        document.querySelectorAll('.mc-folder-item[data-folder]').forEach(el => {
            el.addEventListener('click', async (e) => {
                e.preventDefault();
                const accountId = parseInt(el.dataset.account, 10);
                const folder    = el.dataset.folder;
                if (accountId === state.accountId && folder === state.folder) return;

                // Aktive Markierung setzen
                document.querySelectorAll('.mc-folder-item').forEach(f => f.classList.remove('active'));
                el.classList.add('active');

                state.accountId = accountId;
                state.folder    = folder;
                state.offset    = 0;
                state.uid       = 0;

                clearPreview();
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

    async function loadMessages(reset = false) {
        if (!$.messageList || state.loading) return;
        if (state.accountId === 0 || state.folder === '') return;

        state.loading = true;
        showListLoading(true);

        try {
            const url = `/mail-client/api/messages?account=${state.accountId}&folder=${encodeURIComponent(state.folder)}&limit=${state.limit}&offset=${reset ? 0 : state.offset}`;
            const data = await apiFetch(url);

            if (reset) {
                $.messageList.innerHTML = '';
                state.offset = 0;
            }

            // Folder-Header aktualisieren
            if ($.listHeader) {
                $.listHeader.textContent = state.folder;
            }
            if ($.listUnseenCount && data.unseen > 0) {
                $.listUnseenCount.textContent = `${data.unseen} ungelesen`;
                $.listUnseenCount.classList.remove('d-none');
            } else if ($.listUnseenCount) {
                $.listUnseenCount.classList.add('d-none');
            }

            // Nachrichten rendern
            renderMessages(data.messages || []);
            state.offset += (data.messages || []).length;
            state.hasMore = state.offset < (data.total || 0);

            updateLoadMoreBtn();
        } catch (err) {
            showListError(err.message);
        } finally {
            state.loading = false;
            showListLoading(false);
        }
    }

    function renderMessages(messages) {
        messages.forEach(msg => {
            const row = createMessageRow(msg);
            $.messageList.appendChild(row);
        });

        // Klick / Doppelklick Binding
        $.messageList.querySelectorAll('.mc-message-row:not([data-bound])').forEach(row => {
            row.dataset.bound = '1';
            row.addEventListener('click', () => {
                const uid       = parseInt(row.dataset.uid, 10);
                const accountId = parseInt(row.dataset.account, 10);
                const folder    = row.dataset.folder;
                selectMessage(row, accountId, folder, uid);
            });
            row.addEventListener('dblclick', () => {
                const uid       = parseInt(row.dataset.uid, 10);
                const accountId = parseInt(row.dataset.account, 10);
                const folder    = row.dataset.folder;
                openMessageNewTab(accountId, folder, uid);
            });
        });
    }

    function createMessageRow(msg) {
        const row = document.createElement('div');
        row.className = 'mc-message-row' + (msg.is_seen ? '' : ' unread');
        row.dataset.uid     = msg.uid;
        row.dataset.account = state.accountId;
        row.dataset.folder  = state.folder;

        const icons = [
            msg.is_answered ? '<i class="bi bi-reply-fill text-primary" title="Beantwortet"></i>' : '',
            msg.is_flagged  ? '<i class="bi bi-flag-fill text-warning" title="Markiert"></i>' : '',
            msg.has_attachments ? '<i class="bi bi-paperclip" title="Hat Anhänge"></i>' : '',
        ].filter(Boolean).join('');

        row.innerHTML = `
            <div class="mc-msg-meta">
                <span class="mc-msg-sender">${escHtml(msg.sender_name || msg.sender_address || '(Unbekannt)')}</span>
                <span class="mc-msg-date">${escHtml(msg.date_formatted || '')}</span>
            </div>
            <div class="mc-msg-subject">${escHtml(msg.subject || '(kein Betreff)')}</div>
            ${icons ? `<div class="mc-msg-icons">${icons}</div>` : ''}
        `;
        return row;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Mail-Vorschau
    // ─────────────────────────────────────────────────────────────────────────
    function selectMessage(row, accountId, folder, uid) {
        // Selektierung setzen
        document.querySelectorAll('.mc-message-row.selected').forEach(r => r.classList.remove('selected'));
        row.classList.add('selected');
        row.classList.remove('unread');

        state.uid = uid;
        loadPreview(accountId, folder, uid);

        // Mobile: Vorschau als Overlay anzeigen
        if (window.innerWidth < 992 && $.previewPane) {
            $.previewPane.classList.add('mc-preview-active');
        }
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
                row.querySelector('.mc-message-row::before')?.remove();
            }
        } catch (err) {
            renderPreviewError(err.message);
        } finally {
            showPreviewLoading(false);
        }
    }

    function renderPreview(data) {
        if (!$.previewPane) return;

        // Header
        const header = $.previewPane.querySelector('.mc-preview-header');
        if (header) {
            header.innerHTML = `
                <div class="mc-preview-subject">${escHtml(data.subject || '(kein Betreff)')}</div>
                <div class="mc-preview-meta">
                    <div class="mc-preview-meta-row">
                        <span class="mc-preview-meta-label">Von:</span>
                        <span>${escHtml(data.from_name ? `${data.from_name} <${data.from}>` : data.from)}</span>
                    </div>
                    <div class="mc-preview-meta-row">
                        <span class="mc-preview-meta-label">An:</span>
                        <span>${escHtml(data.to)}</span>
                    </div>
                    ${data.cc ? `<div class="mc-preview-meta-row"><span class="mc-preview-meta-label">CC:</span><span>${escHtml(data.cc)}</span></div>` : ''}
                    <div class="mc-preview-meta-row">
                        <span class="mc-preview-meta-label">Datum:</span>
                        <span>${escHtml(data.date)}</span>
                    </div>
                </div>
            `;
            header.classList.remove('d-none');
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
                        body { margin: 0.75rem; font-family: system-ui, sans-serif; font-size: 14px; 
                               line-height: 1.6; color: #212529; word-break: break-word; }
                        a { color: #0d6efd; }
                        img { max-width: 100%; height: auto; }
                        table { max-width: 100%; overflow-x: auto; display: block; }
                        pre { white-space: pre-wrap; word-break: break-all; }
                    </style>
                </head><body>${data.html || '<em style="color:#666">(Keine Vorschau verfügbar)</em>'}</body></html>`);
                iframeDoc.close();

                // Iframe-Höhe anpassen
                iframe.addEventListener('load', () => {
                    try {
                        const body = iframe.contentDocument?.body;
                        if (body) {
                            iframe.style.height = Math.max(200, body.scrollHeight + 30) + 'px';
                        }
                    } catch (_) {}
                });
            }
        }

        // Anhänge
        let attBar = $.previewPane.querySelector('.mc-attachments-bar');
        if (data.attachments && data.attachments.length > 0) {
            if (!attBar) {
                attBar = document.createElement('div');
                attBar.className = 'mc-attachments-bar';
                $.previewPane.appendChild(attBar);
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
                </div>
            `;
        }
        $.previewPane.querySelector('.mc-blocked-images-banner')?.remove();
        $.previewPane.querySelector('.mc-attachments-bar')?.remove();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Öffentliche Aktionen (von HTML-Inline-Handlern aufgerufen)
    // ─────────────────────────────────────────────────────────────────────────
    window.mailClientApp = {
        openFull(accountId, folder, uid) {
            window.open(
                `/mail-client/message?account=${accountId}&folder=${folder}&uid=${uid}`,
                '_blank',
                'noopener,noreferrer'
            );
        },

        async toggleFlag(accountId, folder, uid, flag, btn) {
            const isFlagged = btn.classList.contains('btn-warning') || btn.querySelector('.bi-flag-fill');
            try {
                await apiFetch('/mail-client/api/messages/flag', {
                    method: 'POST',
                    body: { account: accountId, folder: decodeURIComponent(folder), uid, flag, set: !isFlagged },
                });
                // Icon updaten
                const icon = btn.querySelector('i');
                if (flag === 'flagged') {
                    if (!isFlagged) {
                        btn.classList.replace('btn-outline-secondary', 'btn-outline-warning');
                        icon?.classList.replace('bi-flag', 'bi-flag-fill');
                    } else {
                        btn.classList.replace('btn-outline-warning', 'btn-outline-secondary');
                        icon?.classList.replace('bi-flag-fill', 'bi-flag');
                    }
                }
            } catch (err) {
                alert('Flag konnte nicht gesetzt werden: ' + err.message);
            }
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

    // ─────────────────────────────────────────────────────────────────────────
    // Lade-Mehr-Button
    // ─────────────────────────────────────────────────────────────────────────
    function updateLoadMoreBtn() {
        if (!$.loadMoreBtn) return;
        if (state.hasMore) {
            $.loadMoreBtn.classList.remove('d-none');
            $.loadMoreBtn.textContent = 'Ältere Nachrichten laden';
        } else {
            $.loadMoreBtn.classList.add('d-none');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Hilfsfunktionen
    // ─────────────────────────────────────────────────────────────────────────
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

    // ─────────────────────────────────────────────────────────────────────────
    // Initialisierung
    // ─────────────────────────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', () => {
        initDom();
        initResizers();
        bindFolderClicks();

        // Lade-Mehr-Button
        if ($.loadMoreBtn) {
            $.loadMoreBtn.addEventListener('click', async () => {
                await loadMessages(false);
            });
        }

        // Auto-Load ersten Ordner wenn nur ein Account vorhanden
        const firstFolder = document.querySelector('.mc-folder-item[data-folder]');
        if (firstFolder && !state.folder) {
            firstFolder.click();
        }
    });

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
            b.innerHTML = `<i class="bi ${btn.icon}"></i>`;
            b.addEventListener('mousedown', (e) => {
                e.preventDefault(); // Verhindert Blur auf Editor
                if (btn.special === 'link') {
                    const url = prompt('URL eingeben:', 'https://');
                    if (url && url.startsWith('https://') || url?.startsWith('http://')) {
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
                const cmd = b.title;
                // Aktiven Zustand für Fett/Kursiv/Unterstrichen
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

// ═══════════════════════════════════════════════════════════════════════
// Zahlenformatierung (deutsch: 1.234 / 50.000)
// ═══════════════════════════════════════════════════════════════════════

function mcFormatNumber(n) {
    return new Intl.NumberFormat('de-DE').format(n);
}

// ═══════════════════════════════════════════════════════════════════════
// Hintergrund-Sync-Polling mit Statusleiste
// ═══════════════════════════════════════════════════════════════════════

let _mcSyncTimer     = null;
let _mcSyncAccountId = null;
let _mcSyncFolder    = null;

/**
 * Startet den Hintergrund-Sync für einen Ordner.
 * Bricht einen laufenden Sync automatisch ab wenn ein anderer Ordner geöffnet wird.
 */
function mcStartSyncPolling(accountId, folderName) {
    // Laufenden Poll abbrechen
    if (_mcSyncTimer !== null) {
        clearTimeout(_mcSyncTimer);
        _mcSyncTimer = null;
    }
    _mcSyncAccountId = accountId;
    _mcSyncFolder    = folderName;

    _mcSyncUpdateBar('syncing', 0, 0);
    _mcSyncPollStep();
}

/** Führt einen einzelnen Polling-Schritt aus (eine HTTP-Anfrage an continueSync). */
async function _mcSyncPollStep() {
    const accountId  = _mcSyncAccountId;
    const folderName = _mcSyncFolder;
    if (!accountId || !folderName) { return; }

    const csrf   = document.querySelector('[data-csrf]')?.dataset?.csrf || '';
    const params = new URLSearchParams({ account: accountId, folder: folderName });

    try {
        const resp = await fetch('/mail-client/api/sync/continue?' + params, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf },
            credentials: 'same-origin',
        });
        if (!resp.ok) { throw new Error('HTTP ' + resp.status); }
        const data = await resp.json();

        // Abbrechen wenn User inzwischen Ordner gewechselt hat
        if (_mcSyncAccountId !== accountId || _mcSyncFolder !== folderName) { return; }

        _mcSyncUpdateBar(data.status, data.synced_uids ?? 0, data.total_uids ?? 0);

        if (data.status === 'syncing') {
            // Kurze Pause, dann nächsten Batch
            _mcSyncTimer = setTimeout(_mcSyncPollStep, 400);
        } else if (data.status === 'complete') {
            // Statusleiste nach 4 Sekunden sanft ausblenden
            setTimeout(() => {
                const bar = document.getElementById('mc-sync-status-bar');
                if (bar) { bar.classList.add('mc-sync-hidden'); }
            }, 4000);
        }
    } catch (_e) {
        if (_mcSyncAccountId === accountId && _mcSyncFolder === folderName) {
            _mcSyncUpdateBar('error', 0, 0);
        }
    }
}

/**
 * Erstellt oder aktualisiert die Statusleiste am unteren Rand der Nachrichtenliste.
 * @param {'syncing'|'complete'|'error'|'idle'} status
 */
function _mcSyncUpdateBar(status, synced, total) {
    let bar = document.getElementById('mc-sync-status-bar');
    if (!bar) {
        // Statusleiste an die Nachrichtenliste anhängen
        const pane = document.querySelector('.mc-message-list-pane')
                  || document.querySelector('#mc-message-list')
                  || document.querySelector('.mc-list');
        if (!pane) { return; }
        bar = document.createElement('div');
        bar.id        = 'mc-sync-status-bar';
        bar.className = 'mc-sync-bar';
        pane.appendChild(bar);
    }

    bar.classList.remove('mc-sync-hidden', 'mc-sync-done', 'mc-sync-error');

    if (status === 'syncing') {
        const pct = total > 0 ? Math.round((synced / total) * 100) : 0;
        bar.innerHTML = '<span class="mc-sync-spinner"></span>'
            + (total > 0
                ? '<span>Synchronisiere&hellip; <strong>' + mcFormatNumber(synced) + '</strong> von <strong>' + mcFormatNumber(total) + '</strong></span>'
                  + '<span class="ms-auto text-body-secondary">' + pct + '\u202f%</span>'
                : '<span>Synchronisiere&hellip;</span>');
    } else if (status === 'complete') {
        bar.classList.add('mc-sync-done');
        bar.innerHTML = '<span>&#10003;</span>'
            + '<span>Synchronisierung abgeschlossen &ndash; '
            + mcFormatNumber(total > 0 ? total : synced)
            + '\u202fNachrichten</span>';
    } else if (status === 'error') {
        bar.classList.add('mc-sync-error');
        bar.innerHTML = '<span>&#9888;</span>'
            + '<span>Synchronisierungsfehler &ndash; bitte Seite neu laden</span>';
    } else {
        bar.classList.add('mc-sync-hidden');
    }
}

// Integration: Wird ausgelöst wenn ein Ordner geöffnet wird.
// Der bestehende Ordner-Klick-Handler soll dieses Event dispatchen.
document.addEventListener('mc:folder-opened', function (e) {
    const { accountId, folderName } = e.detail || {};
    if (accountId && folderName) {
        mcStartSyncPolling(accountId, folderName);
    }
});

// Globale Hilfsfunktion für bestehende Klick-Handler (z.B. in client.php inline-script)
window.mcTriggerSync = function (accountId, folderName) {
    mcStartSyncPolling(accountId, folderName);
};

