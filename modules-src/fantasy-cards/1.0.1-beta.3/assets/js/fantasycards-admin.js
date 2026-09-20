(function () {
    const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    })[char]);
    const escapeAttr = (value) => escapeHtml(value).replace(/`/g, '&#096;');
    const feedback = document.getElementById('fantasycards-admin-feedback');
    const setFeedback = (text, isError = false) => {
        if (!feedback) return;
        feedback.textContent = text;
        feedback.classList.toggle('text-danger', isError);
        feedback.classList.toggle('text-success', !isError && text !== '');
    };

    const postForm = async (url, body, csrfToken) => {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-Token': csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body
        });
        const data = await response.json();
        if (!response.ok || !data.ok) {
            throw new Error(data.message || 'Aktion fehlgeschlagen.');
        }
        return data;
    };

    const initInlineEditor = () => {
        const list = document.querySelector('.fantasycards-admin-card-list');
        if (!list) return;
        const csrfToken = String(list.dataset.csrfToken || '');
        const inlineUrl = String(list.dataset.inlineUrl || '');
        const reorderUrl = String(list.dataset.reorderUrl || '');
        let saveTimer = 0;

        const saveField = async (input) => {
            const row = input.closest('.fantasycards-card-row');
            if (!(row instanceof HTMLElement)) return;
            const state = row.querySelector('.fantasycards-save-state');
            const body = new URLSearchParams();
            body.set('card_id', String(row.dataset.cardId || '0'));
            body.set('field', String(input.dataset.field || ''));
            body.set('value', input.type === 'checkbox' ? (input.checked ? '1' : '0') : String(input.value || ''));
            if (state) {
                state.textContent = 'Speichert...';
                state.classList.remove('text-danger', 'text-success');
            }
            try {
                await postForm(inlineUrl, body, csrfToken);
                if (state) {
                    state.textContent = 'Gespeichert';
                    state.classList.add('text-success');
                }
            } catch (error) {
                if (state) {
                    state.textContent = error instanceof Error ? error.message : 'Fehler';
                    state.classList.add('text-danger');
                }
            }
        };

        list.querySelectorAll('.fantasycards-inline-input').forEach((input) => {
            const eventName = input.tagName === 'SELECT' || input.type === 'checkbox' ? 'change' : 'input';
            input.addEventListener(eventName, () => {
                window.clearTimeout(saveTimer);
                saveTimer = window.setTimeout(() => saveField(input), eventName === 'input' ? 650 : 0);
            });
            if (eventName === 'input') {
                input.addEventListener('blur', () => saveField(input));
            }
        });

        const tbody = document.getElementById('fantasycards-card-tbody');
        if (!tbody) return;
        let dragged = null;
        let startX = 0;
        let startY = 0;
        let started = false;

        const finish = async () => {
            if (!dragged) return;
            dragged.classList.remove('is-dragging');
            const shouldPersist = started;
            const setId = dragged.dataset.setId || '0';
            dragged = null;
            started = false;
            if (!shouldPersist || Number(setId) <= 0) return;

            const ids = Array.from(tbody.querySelectorAll(`.fantasycards-card-row[data-set-id="${setId}"]`))
                .map((row) => Number(row.dataset.cardId || 0))
                .filter((id) => id > 0);
            const body = new URLSearchParams();
            body.set('set_id', setId);
            ids.forEach((id) => body.append('card_ids[]', String(id)));
            try {
                await postForm(reorderUrl, body, csrfToken);
                setFeedback('Sortierung gespeichert.');
            } catch (error) {
                setFeedback(error instanceof Error ? error.message : 'Sortierung konnte nicht gespeichert werden.', true);
            }
        };

        tbody.querySelectorAll('.fantasycards-drag-handle').forEach((handle) => {
            handle.addEventListener('mousedown', (event) => {
                if (event.button !== 0) return;
                const row = handle.closest('.fantasycards-card-row');
                if (!(row instanceof HTMLElement)) return;
                dragged = row;
                startX = event.clientX;
                startY = event.clientY;
                started = false;
                event.preventDefault();
            });
        });

        document.addEventListener('mousemove', (event) => {
            if (!dragged) return;
            if (Math.hypot(event.clientX - startX, event.clientY - startY) < 6) return;
            event.preventDefault();
            if (!started) {
                started = true;
                dragged.classList.add('is-dragging');
            }
            const pointed = document.elementFromPoint(event.clientX, event.clientY);
            const target = pointed instanceof Element ? pointed.closest('.fantasycards-card-row') : null;
            if (!(target instanceof HTMLElement) || target === dragged || target.dataset.setId !== dragged.dataset.setId) return;
            const rect = target.getBoundingClientRect();
            tbody.insertBefore(dragged, event.clientY < rect.top + rect.height / 2 ? target : target.nextSibling);
        });

        document.addEventListener('mouseup', () => {
            void finish();
        });
    };

    const initCardFilter = () => {
        const form = document.querySelector('.js-fantasycards-card-filter');
        if (!(form instanceof HTMLFormElement)) return;

        const search = form.querySelector('input[name="q"]');
        let timer = 0;
        const currentFilter = () => new URLSearchParams(new FormData(form)).toString();
        let lastSubmitted = currentFilter();

        const submitFilter = () => {
            const current = currentFilter();
            if (current === lastSubmitted) return;
            lastSubmitted = current;
            form.requestSubmit();
        };

        form.querySelectorAll('select[name="set"], select[name="status"]').forEach((select) => {
            select.addEventListener('change', () => {
                window.clearTimeout(timer);
                submitFilter();
            });
        });

        if (search instanceof HTMLInputElement) {
            search.addEventListener('input', () => {
                window.clearTimeout(timer);
                timer = window.setTimeout(submitFilter, 450);
            });

            search.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter') return;
                event.preventDefault();
                window.clearTimeout(timer);
                submitFilter();
            });
        }
    };

    const initBulkActions = () => {
        const list = document.querySelector('.fantasycards-admin-card-list');
        const toolbars = Array.from(document.querySelectorAll('.js-fantasycards-bulk-actions'))
            .filter((toolbar) => toolbar instanceof HTMLElement);
        if (!(list instanceof HTMLElement) || toolbars.length === 0) return;

        const csrfToken = String(list.dataset.csrfToken || '');
        const bulkUrl = String(list.dataset.bulkUrl || '');
        const selectAll = list.querySelector('.js-fantasycards-select-all');

        if (!(selectAll instanceof HTMLInputElement)) {
            return;
        }

        const controls = toolbars.map((toolbar) => ({
            actionSelect: toolbar.querySelector('.fantasycards-bulk-action-select'),
            applyButton: toolbar.querySelector('.js-fantasycards-bulk-apply'),
            countLabel: toolbar.querySelector('.js-fantasycards-selected-count')
        })).filter((control) => control.actionSelect instanceof HTMLSelectElement && control.applyButton instanceof HTMLButtonElement);

        const checkboxes = () => Array.from(list.querySelectorAll('.js-fantasycards-card-checkbox'))
            .filter((checkbox) => checkbox instanceof HTMLInputElement);

        const selectedCheckboxes = () => checkboxes().filter((checkbox) => checkbox.checked);

        const updateBulkState = () => {
            const all = checkboxes();
            const selected = selectedCheckboxes();
            selectAll.checked = all.length > 0 && selected.length === all.length;
            selectAll.indeterminate = selected.length > 0 && selected.length < all.length;
            controls.forEach(({actionSelect, applyButton, countLabel}) => {
                applyButton.disabled = selected.length === 0 || actionSelect.value === '';
                if (countLabel) {
                    countLabel.textContent = selected.length === 1 ? '1 ausgewählt' : `${selected.length} ausgewählt`;
                }
            });
        };

        selectAll.addEventListener('change', () => {
            checkboxes().forEach((checkbox) => {
                checkbox.checked = selectAll.checked;
            });
            updateBulkState();
        });

        list.addEventListener('change', (event) => {
            if (event.target instanceof HTMLInputElement && event.target.classList.contains('js-fantasycards-card-checkbox')) {
                updateBulkState();
            }
        });

        controls.forEach(({actionSelect}) => {
            actionSelect.addEventListener('change', updateBulkState);
        });

        controls.forEach(({actionSelect, applyButton}) => {
            applyButton.addEventListener('click', async () => {
                const selected = selectedCheckboxes();
                const value = actionSelect.value;
                if (selected.length === 0 || value === '') {
                    setFeedback('Bitte Karten und eine Bulk-Aktion auswählen.', true);
                    return;
                }

                if (value === 'delete' && !window.confirm(`${selected.length} ausgewählte Karte(n) wirklich löschen? Bereits gezogene Karten werden übersprungen.`)) {
                    return;
                }

                const body = new URLSearchParams();
                selected.forEach((checkbox) => body.append('card_ids[]', checkbox.value));

                const [action, actionValue = ''] = value.split(':', 2);
                body.set('bulk_action', action);
                if (action === 'status') {
                    body.set('bulk_status', actionValue);
                } else if (action === 'active' || action === 'booster') {
                    body.set('bulk_enabled', actionValue);
                }

                applyButton.disabled = true;
                try {
                    const data = await postForm(bulkUrl, body, csrfToken);
                    setFeedback(data.message || 'Bulk-Aktion ausgeführt.');
                    window.setTimeout(() => window.location.reload(), 650);
                } catch (error) {
                    setFeedback(error instanceof Error ? error.message : 'Bulk-Aktion fehlgeschlagen.', true);
                    updateBulkState();
                }
            });
        });

        updateBulkState();
    };

    const initUpload = () => {
        const form = document.getElementById('fantasycards-upload-form');
        if (!(form instanceof HTMLFormElement)) return;
        const input = document.getElementById('fantasycards-upload-files');
        const dropzone = document.getElementById('fantasycards-dropzone');
        const summary = document.getElementById('fantasycards-upload-summary');
        const results = document.getElementById('fantasycards-upload-results');
        const bar = document.getElementById('fantasycards-upload-bar');
        let droppedFiles = null;

        const setFiles = (files) => {
            droppedFiles = files;
            if (summary) {
                summary.textContent = files && files.length > 0 ? `${files.length} Datei(en) bereit.` : '';
            }
        };

        if (dropzone) {
            ['dragenter', 'dragover'].forEach((name) => dropzone.addEventListener(name, (event) => {
                event.preventDefault();
                dropzone.classList.add('is-dragover');
            }));
            ['dragleave', 'drop'].forEach((name) => dropzone.addEventListener(name, (event) => {
                event.preventDefault();
                dropzone.classList.remove('is-dragover');
            }));
            dropzone.addEventListener('drop', (event) => {
                setFiles(event.dataTransfer ? event.dataTransfer.files : null);
            });
        }

        if (input instanceof HTMLInputElement) {
            input.addEventListener('change', () => setFiles(input.files));
        }

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const files = droppedFiles || (input instanceof HTMLInputElement ? input.files : null);
            if (!files || files.length === 0) {
                if (results) results.textContent = 'Bitte mindestens eine Datei auswählen.';
                return;
            }

            const data = new FormData(form);
            const csrfToken = String(data.get('_csrf') || '');
            data.delete('cards[]');
            Array.from(files).forEach((file) => data.append('cards[]', file));
            const xhr = new XMLHttpRequest();
            xhr.open('POST', String(form.dataset.uploadUrl || '/admin/fantasy-cards/upload'));
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-CSRF-Token', csrfToken);
            xhr.upload.addEventListener('progress', (progress) => {
                if (!bar || !progress.lengthComputable) return;
                const percent = Math.round((progress.loaded / progress.total) * 100);
                bar.style.width = `${percent}%`;
                bar.textContent = `${percent}%`;
            });
            xhr.addEventListener('load', () => {
                let payload = null;
                try {
                    payload = JSON.parse(xhr.responseText || '{}');
                } catch (error) {
                    payload = {ok: false, message: 'Antwort konnte nicht gelesen werden.'};
                }
                if (bar) {
                    bar.style.width = '100%';
                    bar.textContent = '100%';
                }
                if (results) {
                    const errors = Array.isArray(payload.errors) && payload.errors.length > 0
                        ? `<div class="alert alert-warning mt-3">${payload.errors.map((item) => `<div>${String(item)}</div>`).join('')}</div>`
                        : '';
                    const cards = Array.isArray(payload.cards)
                        ? payload.cards.map((card) => {
                            const fullImage = String(card.image_path || '');
                            const image = String(card.thumbnail_path || fullImage);
                            const imageMarkup = image
                                ? `<span class="fantasycards-admin-thumb">${fullImage ? `<button type="button" class="fantasycards-image-button" data-fantasycards-lightbox data-full-image="${escapeAttr(fullImage)}" data-title="${escapeAttr(card.name || '')}" data-caption="Upload-Ergebnis"><img src="${escapeAttr(image)}" alt="${escapeAttr(card.name || '')}" loading="lazy"></button>` : `<img src="${escapeAttr(image)}" alt="${escapeAttr(card.name || '')}" loading="lazy">`}</span>`
                                : '';
                            return `<li class="fantasycards-upload-result-item">${imageMarkup}<span>${escapeHtml(card.name || 'Karte')}</span> <span class="badge text-bg-secondary">${escapeHtml(card.status || 'draft')}</span></li>`;
                        }).join('')
                        : '';
                    results.innerHTML = `<div class="alert ${payload.ok ? 'alert-success' : 'alert-danger'}">${String(payload.message || 'Upload beendet.')}</div>${errors}<ul class="mb-0">${cards}</ul>`;
                }
            });
            xhr.addEventListener('error', () => {
                if (results) results.innerHTML = '<div class="alert alert-danger">Upload fehlgeschlagen.</div>';
            });
            xhr.send(data);
        });
    };

    initCardFilter();
    initInlineEditor();
    initBulkActions();
    initUpload();
})();
