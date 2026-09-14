(function () {
    'use strict';

    function measureFrame(frame) {
        try {
            const doc = frame.contentDocument;
            if (!doc) {
                return false;
            }

            const body = doc.body;
            const html = doc.documentElement;
            if (!body || !html) {
                return false;
            }

            const height = Math.max(
                body.scrollHeight,
                body.offsetHeight,
                html.clientHeight,
                html.scrollHeight,
                html.offsetHeight
            );

            if (!Number.isFinite(height) || height <= 0) {
                return false;
            }

            frame.style.height = height + 'px';
            return true;
        } catch (_error) {
            return false;
        }
    }

    function bindFrame(frame) {
        if (!(frame instanceof HTMLIFrameElement)) {
            return;
        }
        if (frame.dataset.mailAutosizeBound === '1') {
            return;
        }
        frame.dataset.mailAutosizeBound = '1';
        frame.setAttribute('scrolling', 'no');

        const remeasure = function () {
            measureFrame(frame);
        };

        const onLoad = function () {
            const measured = measureFrame(frame);
            if (!measured) {
                return;
            }

            try {
                const doc = frame.contentDocument;
                if (!doc) {
                    return;
                }

                if (typeof ResizeObserver !== 'undefined') {
                    const observer = new ResizeObserver(remeasure);
                    observer.observe(doc.documentElement);
                    frame._mailAutosizeObserver = observer;
                }

                Array.from(doc.images || []).forEach(function (img) {
                    if (!(img instanceof HTMLImageElement)) {
                        return;
                    }
                    if (img.complete) {
                        return;
                    }
                    img.addEventListener('load', remeasure, {once: true});
                    img.addEventListener('error', remeasure, {once: true});
                });

                if (frame.contentWindow) {
                    frame.contentWindow.addEventListener('resize', remeasure);
                }
            } catch (_error) {
                // Sandbox/CSP können Zugriff verhindern; dann bleibt Fallback-Höhe aktiv.
            }

            window.setTimeout(remeasure, 60);
            window.setTimeout(remeasure, 250);
            window.setTimeout(remeasure, 900);
        };

        frame.addEventListener('load', onLoad);
        window.requestAnimationFrame(onLoad);
    }

    function init(root) {
        const scope = root instanceof Element || root instanceof Document ? root : document;
        scope.querySelectorAll('.js-mail-html-frame').forEach(bindFrame);
    }

    window.ModulonMailFrameAutosize = {
        init: init,
    };
})();

