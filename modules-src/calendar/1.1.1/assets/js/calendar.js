/**
 * Calendar Module JS - modulnest.calendar 1.1.0
 * Multi-Kalender Support, Drag/Resize, Rolling Window Prefetch & Google Auto-Sync.
 */
(function () {
    'use strict';

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.getAttribute('content') || '';
        const input = document.querySelector('input[name="_csrf"], input[name="csrf_token"]');
        if (input) return input.value;
        return '';
    }

    async function moveAppointment(id, start, end) {
        const res = await fetch('/calendar/api/appointment/' + id + '/move', {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': csrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({ start: start, end: end }),
        });
        return res.json();
    }

    function formatDateTime(date) {
        const pad = (n) => String(n).padStart(2, '0');
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) +
            'T' + pad(date.getHours()) + ':' + pad(date.getMinutes()) + ':00';
    }

    function bindAppointmentDrag(element) {
        let startPx = 0;
        let startTime = 0;
        let dragType = null;

        element.addEventListener('mousedown', (e) => {
            const rect = element.getBoundingClientRect();
            const offsetY = e.clientY - rect.top;
            dragType = (offsetY > rect.height - 8) ? 'resize' : 'move';
            startPx = e.clientY;
            startTime = element.offsetTop;
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onEnd);
            element.classList.add('dragging');
            e.preventDefault();
        });

        function onMove(e) {
            const delta = e.clientY - startPx;
            if (dragType === 'resize') {
                const newHeight = Math.max(30, element.offsetHeight + delta);
                element.style.height = newHeight + 'px';
            } else {
                element.style.top = Math.max(0, startTime + delta) + 'px';
            }
        }

        function onEnd() {
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onEnd);
            element.classList.remove('dragging');

            const container = element.closest('.calendar-events, .calendar-day-events');
            if (!container) return;
            const dateStr = element.closest('[data-date]').getAttribute('data-date');
            if (!dateStr) return;

            const topPx = parseInt(element.style.top || '0', 10);
            const heightPx = parseInt(element.style.height || '60', 10);

            const startDate = new Date(dateStr + 'T00:00:00');
            const startMinutes = Math.round((topPx / 60) * 60);
            const endMinutes = startMinutes + Math.round((heightPx / 60) * 60);
            startDate.setMinutes(startMinutes);
            const endDate = new Date(dateStr + 'T00:00:00');
            endDate.setMinutes(endMinutes);

            if (endDate <= startDate) {
                element.style.top = startTime + 'px';
                return;
            }

            const id = element.getAttribute('data-id');
            moveAppointment(id, formatDateTime(startDate), formatDateTime(endDate)).then((result) => {
                if (!result || result.success !== true) {
                    element.style.top = startTime + 'px';
                }
            }).catch(() => {
                element.style.top = startTime + 'px';
            });
        }
    }

    function initVisibilityToggles() {
        document.querySelectorAll('.calendar-visibility-toggle').forEach((chk) => {
            chk.addEventListener('change', async () => {
                const calId = chk.dataset.calId;
                const toggleUrl = chk.dataset.toggleUrl || '/calendar/toggle-calendar';
                const token = chk.dataset.csrf || csrfToken();

                const formData = new FormData();
                formData.append('id', calId);
                formData.append('_csrf', token);

                try {
                    chk.disabled = true;
                    await fetch(toggleUrl, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-Token': token,
                        },
                    });
                    window.location.reload();
                } catch (e) {
                    chk.disabled = false;
                }
            });
        });
    }

    function initRollingMonthPrefetch() {
        const prevBtn = document.querySelector('a[title="Vorheriger Monat"]');
        const nextBtn = document.querySelector('a[title="Nächster Monat"]');

        const prefetch = (btn) => {
            if (!btn || !btn.href || btn.dataset.prefetched) return;
            btn.dataset.prefetched = 'true';
            fetch(btn.href, { credentials: 'same-origin' }).catch(() => {});
        };

        [prevBtn, nextBtn].forEach((btn) => {
            if (!btn) return;
            btn.addEventListener('mouseenter', () => prefetch(btn), { once: true });
            btn.addEventListener('touchstart', () => prefetch(btn), { once: true, passive: true });
        });

        if (window.requestIdleCallback) {
            window.requestIdleCallback(() => {
                prefetch(prevBtn);
                prefetch(nextBtn);
            });
        }
    }

    function initGoogleAutoSync() {
        // Non-blocking background sync 2.5s after load
        setTimeout(async () => {
            try {
                const res = await fetch('/calendar/google/auto-sync', {
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin',
                });
                if (!res.ok) return;
                const data = await res.json();
                if (data.synced && data.events_synced > 0) {
                    console.log('[Calendar] Google Auto-Sync synced ' + data.events_synced + ' events.');
                    window.location.reload();
                }
            } catch (e) {
                // Background sync fails silently
            }
        }, 2500);
    }

    function init() {
        initVisibilityToggles();
        initRollingMonthPrefetch();
        initGoogleAutoSync();

        document.querySelectorAll('.appointment-block').forEach((el) => {
            if (!el.classList.contains('all-day')) {
                bindAppointmentDrag(el);
            }
        });

        document.querySelectorAll('.calendar-events, .calendar-day-events').forEach((container) => {
            container.addEventListener('dblclick', (e) => {
                if (e.target !== container) return;
                const dateStr = container.closest('[data-date]').getAttribute('data-date');
                if (!dateStr) return;
                window.location.href = '/calendar/appointment/create?date=' + dateStr;
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
