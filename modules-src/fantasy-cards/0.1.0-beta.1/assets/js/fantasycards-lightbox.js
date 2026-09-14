(function () {
    'use strict';

    if (window.__fantasycardsLightboxInitialized) {
        return;
    }
    window.__fantasycardsLightboxInitialized = true;

    let overlay = null;
    let image = null;
    let title = null;
    let caption = null;
    let counter = null;
    let prevButton = null;
    let nextButton = null;
    let closeButton = null;
    let gallery = [];
    let currentIndex = 0;
    let previousFocus = null;
    let touchStartX = 0;

    function createOverlay() {
        overlay = document.createElement('div');
        overlay.className = 'fantasycards-lightbox';
        overlay.hidden = true;
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'Kartenbild anzeigen');
        overlay.innerHTML = `
            <button type="button" class="fantasycards-lightbox-close" aria-label="Lightbox schließen">×</button>
            <button type="button" class="fantasycards-lightbox-nav fantasycards-lightbox-prev" aria-label="Vorherige Karte">‹</button>
            <figure class="fantasycards-lightbox-frame">
                <img class="fantasycards-lightbox-image" alt="">
                <figcaption class="fantasycards-lightbox-caption">
                    <strong class="fantasycards-lightbox-title"></strong>
                    <span class="fantasycards-lightbox-text"></span>
                    <span class="fantasycards-lightbox-counter"></span>
                </figcaption>
            </figure>
            <button type="button" class="fantasycards-lightbox-nav fantasycards-lightbox-next" aria-label="Nächste Karte">›</button>
        `;

        document.body.appendChild(overlay);
        image = overlay.querySelector('.fantasycards-lightbox-image');
        title = overlay.querySelector('.fantasycards-lightbox-title');
        caption = overlay.querySelector('.fantasycards-lightbox-text');
        counter = overlay.querySelector('.fantasycards-lightbox-counter');
        prevButton = overlay.querySelector('.fantasycards-lightbox-prev');
        nextButton = overlay.querySelector('.fantasycards-lightbox-next');
        closeButton = overlay.querySelector('.fantasycards-lightbox-close');

        closeButton.addEventListener('click', close);
        prevButton.addEventListener('click', () => show(currentIndex - 1));
        nextButton.addEventListener('click', () => show(currentIndex + 1));
        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) {
                close();
            }
        });
        overlay.addEventListener('touchstart', (event) => {
            touchStartX = event.changedTouches && event.changedTouches[0] ? event.changedTouches[0].clientX : 0;
        }, {passive: true});
        overlay.addEventListener('touchend', (event) => {
            if (gallery.length <= 1 || !event.changedTouches || !event.changedTouches[0]) {
                return;
            }
            const delta = event.changedTouches[0].clientX - touchStartX;
            if (Math.abs(delta) > 46) {
                show(currentIndex + (delta < 0 ? 1 : -1));
            }
        }, {passive: true});
    }

    function visibleTriggers() {
        return Array.from(document.querySelectorAll('[data-fantasycards-lightbox]'))
            .filter((item) => item instanceof HTMLElement)
            .filter((item) => {
                if (!item.dataset.fullImage) {
                    return false;
                }
                const rect = item.getBoundingClientRect();
                const style = window.getComputedStyle(item);
                return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
            });
    }

    function open(trigger) {
        if (!overlay) {
            createOverlay();
        }

        gallery = visibleTriggers();
        currentIndex = Math.max(0, gallery.indexOf(trigger));
        previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        document.body.classList.add('fantasycards-lightbox-open');
        overlay.hidden = false;
        show(currentIndex);
        closeButton.focus({preventScroll: true});
    }

    function close() {
        if (!overlay || overlay.hidden) {
            return;
        }
        overlay.hidden = true;
        document.body.classList.remove('fantasycards-lightbox-open');
        image.removeAttribute('src');
        if (previousFocus && document.contains(previousFocus)) {
            previousFocus.focus({preventScroll: true});
        }
    }

    function show(index) {
        if (gallery.length === 0) {
            close();
            return;
        }

        currentIndex = (index + gallery.length) % gallery.length;
        const item = gallery[currentIndex];
        const fullImage = item.dataset.fullImage || '';
        const itemTitle = item.dataset.title || item.getAttribute('aria-label') || '';
        const itemCaption = item.dataset.caption || '';

        image.src = fullImage;
        image.alt = itemTitle;
        title.textContent = itemTitle;
        caption.textContent = itemCaption;
        counter.textContent = gallery.length > 1 ? `${currentIndex + 1} / ${gallery.length}` : '';
        prevButton.hidden = gallery.length <= 1;
        nextButton.hidden = gallery.length <= 1;

        preload(currentIndex - 1);
        preload(currentIndex + 1);
    }

    function preload(index) {
        if (gallery.length <= 1) {
            return;
        }
        const item = gallery[(index + gallery.length) % gallery.length];
        const src = item ? item.dataset.fullImage : '';
        if (src) {
            const preloadImage = new Image();
            preloadImage.src = src;
        }
    }

    document.addEventListener('click', (event) => {
        const trigger = event.target instanceof Element ? event.target.closest('[data-fantasycards-lightbox]') : null;
        if (!(trigger instanceof HTMLElement)) {
            return;
        }
        event.preventDefault();
        open(trigger);
    });

    document.addEventListener('keydown', (event) => {
        if (!overlay || overlay.hidden) {
            return;
        }
        if (event.key === 'Escape') {
            event.preventDefault();
            close();
        } else if (event.key === 'ArrowLeft' && gallery.length > 1) {
            event.preventDefault();
            show(currentIndex - 1);
        } else if (event.key === 'ArrowRight' && gallery.length > 1) {
            event.preventDefault();
            show(currentIndex + 1);
        } else if (event.key === 'Tab') {
            keepFocusInside(event);
        }
    });

    function keepFocusInside(event) {
        const focusable = Array.from(overlay.querySelectorAll('button:not([hidden])'))
            .filter((item) => !item.disabled && item.offsetParent !== null);
        if (focusable.length === 0) {
            return;
        }
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }
})();
