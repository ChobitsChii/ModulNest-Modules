(function () {
    'use strict';

    const stage = document.getElementById('fantasycards-opening-stage');
    const grid = document.getElementById('fantasycards-reveal-grid');
    const title = document.getElementById('fantasycards-opening-title');

    function cardMarkup(card, rarity) {
        const fullImage = card.image_path || '';
        const image = card.thumbnail_path || fullImage;
        const label = rarity && rarity.label ? rarity.label : card.rarity;
        const cssClass = rarity && rarity.class ? rarity.class : 'fantasycards-rarity-common';
        const badge = rarity && rarity.badge ? rarity.badge : 'text-bg-secondary';
        const imageMarkup = image
            ? fullImage
                ? `<button type="button" class="fantasycards-image-button" data-fantasycards-lightbox data-full-image="${escapeAttr(fullImage)}" data-title="${escapeAttr(card.name || '')}" data-caption="${escapeAttr([card.card_number || '', label || ''].filter(Boolean).join(' · '))}"><img src="${escapeAttr(image)}" alt="${escapeAttr(card.name || '')}" loading="lazy"></button>`
                : `<img src="${escapeAttr(image)}" alt="${escapeAttr(card.name || '')}" loading="lazy">`
            : '<div class="fantasycards-image-placeholder">Karte</div>';

        return `
            <article class="fantasycards-reveal-card fantasycards-card ${escapeAttr(cssClass)}" data-rarity="${escapeAttr(card.rarity || 'common')}">
                <div class="fantasycards-card-image">${imageMarkup}</div>
                <div class="p-3">
                    <div class="d-flex justify-content-between gap-2 align-items-start mb-2">
                        <h3 class="h6 mb-0">${escapeHtml(card.name || '')}</h3>
                        <span class="badge ${escapeAttr(badge)}">${escapeHtml(label || '')}</span>
                    </div>
                    <div class="small text-body-secondary mb-2">${escapeHtml(card.card_number || '')}</div>
                    <p class="small mb-0">${escapeHtml(card.description || '')}</p>
                </div>
            </article>
        `;
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (char) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char];
        });
    }

    function escapeAttr(value) {
        return escapeHtml(value).replace(/`/g, '&#096;');
    }

    async function openBooster(button) {
        const body = new URLSearchParams();
        body.set('booster_type_id', button.dataset.boosterTypeId || '');
        button.disabled = true;
        button.textContent = 'Öffnet...';

        try {
            const response = await fetch(button.dataset.openUrl || '/fantasy-cards/boosters/open', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-Token': button.dataset.csrfToken || '',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body
            });
            const payload = await response.json();
            if (!payload.ok) {
                throw new Error(payload.message || 'Booster konnte nicht geöffnet werden.');
            }
            reveal(payload);
        } catch (error) {
            alert(error.message || 'Booster konnte nicht geöffnet werden.');
        } finally {
            button.disabled = false;
            button.textContent = 'Booster öffnen';
        }
    }

    function reveal(payload) {
        if (!stage || !grid) {
            window.location.reload();
            return;
        }

        stage.hidden = false;
        grid.innerHTML = '';
        if (title) {
            title.textContent = `${payload.booster && payload.booster.booster_name ? payload.booster.booster_name : 'Booster'} geöffnet`;
        }
        stage.scrollIntoView({behavior: 'smooth', block: 'start'});

        const cards = Array.isArray(payload.cards) ? payload.cards : [];
        const rarities = payload.rarities || {};
        cards.forEach((card, index) => {
            const wrapper = document.createElement('div');
            wrapper.className = 'fantasycards-reveal-slot';
            wrapper.style.setProperty('--fantasycards-reveal-delay', `${index * 180}ms`);
            wrapper.innerHTML = cardMarkup(card, rarities[card.rarity] || {});
            grid.appendChild(wrapper);
        });

        const inventoryCard = document.querySelector(`[data-booster-type-id="${CSS.escape(String(payload.booster.booster_type_id || ''))}"] .badge`);
        if (inventoryCard) {
            const current = Number((inventoryCard.textContent || '').replace(/[^0-9]/g, '')) || 1;
            inventoryCard.textContent = `x${Math.max(0, current - 1)}`;
        }
    }

    document.addEventListener('click', function (event) {
        const button = event.target instanceof Element ? event.target.closest('.fantasycards-open-booster') : null;
        if (!button) {
            return;
        }
        event.preventDefault();
        openBooster(button);
    });
})();
