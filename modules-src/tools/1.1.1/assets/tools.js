(function () {
    'use strict';

    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

    function setText(selector, value) {
        const node = $(selector);
        if (node) {
            node.textContent = value;
        }
    }

    function setOutput(selector, value) {
        const node = $(selector);
        if (!node) {
            return;
        }
        if ('value' in node) {
            node.value = value;
            return;
        }
        node.textContent = value;
    }

    function escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function escapeHtmlWithBreaks(value) {
        return escapeHtml(value).replace(/\n/g, '<br>');
    }

    function initFilters() {
        const input = $('#tools-search');
        const cards = $$('[data-tool-card]');
        const tabs = $$('[data-tools-category-tab]');
        if (!input && tabs.length === 0) {
            return;
        }

        const knownCategories = new Set(tabs.map((tab) => String(tab.dataset.toolsCategoryTab || '')).filter(Boolean));
        let activeCategory = 'overview';

        function categoryFromHash() {
            const rawHash = decodeURIComponent(window.location.hash || '').replace(/^#/, '');
            if (rawHash === '' || rawHash === 'tools-uebersicht' || rawHash === 'tools-overview') {
                return 'overview';
            }
            if (!rawHash.startsWith('tools-')) {
                return 'overview';
            }
            const category = rawHash.slice('tools-'.length);
            return knownCategories.has(category) ? category : 'overview';
        }

        function setActiveTab() {
            tabs.forEach((candidate) => {
                const isActive = String(candidate.dataset.toolsCategoryTab || '') === activeCategory;
                candidate.classList.toggle('active', isActive);
                candidate.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });
        }

        function apply() {
            const query = input ? input.value.trim().toLowerCase() : '';
            let visibleCards = 0;
            cards.forEach((card) => {
                const haystack = String(card.dataset.toolSearch || '');
                const category = String(card.dataset.toolCategorySlug || '');
                const categoryMatches = activeCategory === 'overview' || category === activeCategory;
                const queryMatches = query === '' || haystack.includes(query);
                const visible = categoryMatches && queryMatches;
                card.hidden = !visible;
                if (visible) {
                    visibleCards += 1;
                }
            });

            $$('[data-tools-category]').forEach((section) => {
                const sectionCards = $$('[data-tool-card]', section);
                section.hidden = sectionCards.length > 0 && sectionCards.every((card) => card.hidden);
            });

            const empty = $('[data-tools-empty]');
            if (empty) {
                empty.classList.toggle('d-none', visibleCards > 0);
            }
            setActiveTab();
        }

        function applyHash(keepAtTop) {
            activeCategory = categoryFromHash();
            apply();
            if (keepAtTop) {
                window.requestAnimationFrame(() => window.scrollTo({ top: 0, left: 0, behavior: 'auto' }));
            }
        }

        if (input) {
            input.addEventListener('input', apply);
        }

        tabs.forEach((tab) => {
            tab.addEventListener('click', (event) => {
                event.preventDefault();
                activeCategory = String(tab.dataset.toolsCategoryTab || 'overview');
                apply();
                if (history.replaceState) {
                    const hash = activeCategory === 'overview' ? 'tools-uebersicht' : 'tools-' + activeCategory;
                    const url = window.location.pathname + '#' + hash;
                    history.replaceState(null, '', url);
                }
            });
        });

        window.addEventListener('hashchange', () => applyHash(true));
        applyHash(Boolean(window.location.hash));
    }

    function initTextCounter() {
        const input = $('#tools-text-input');
        if (!input) {
            return;
        }
        const update = () => {
            const text = input.value;
            setText('#tools-count-chars', String(text.length));
            setText('#tools-count-no-space', String(text.replace(/\s/g, '').length));
            setText('#tools-count-words', String((text.trim().match(/\S+/g) || []).length));
            setText('#tools-count-lines', String(text === '' ? 0 : text.split(/\r\n|\r|\n/).length));
            setText('#tools-count-paragraphs', String(text.trim() === '' ? 0 : text.trim().split(/\n\s*\n/).filter(Boolean).length));
        };
        input.addEventListener('input', update);
        update();
    }

    function initTextCleaner() {
        const input = $('#tools-clean-input');
        const output = $('#tools-clean-output');
        if (!input) {
            return;
        }

        const locale = 'de-DE';
        const cleanWhitespace = (value) => value
            .replace(/[ \t]{2,}/g, ' ')
            .replace(/(?:\r?\n\s*){2,}/g, '\n')
            .trim();
        const titleCase = (value) => value
            .toLocaleLowerCase(locale)
            .replace(/(^|[\s([{/"'„‚-])(\p{L})/gu, (match, prefix, char) => prefix + char.toLocaleUpperCase(locale));
        const sentenceCase = (value) => value
            .toLocaleLowerCase(locale)
            .replace(/(^\s*|[.!?]\s+)(\p{L})/gu, (match, prefix, char) => prefix + char.toLocaleUpperCase(locale));

        $$('[data-clean-action]').forEach((button) => {
            button.addEventListener('click', () => {
                const action = button.dataset.cleanAction;
                let result = input.value;
                if (action === 'clean') {
                    result = cleanWhitespace(result);
                }
                if (action === 'lower') {
                    result = result.toLocaleLowerCase(locale);
                }
                if (action === 'title') {
                    result = titleCase(result);
                }
                if (action === 'sentence') {
                    result = sentenceCase(result);
                }
                if (output) {
                    output.value = result;
                } else {
                    input.value = result;
                    input.dispatchEvent(new Event('input'));
                }
            });
        });
    }

    function utf8ToBase64(value) {
        const bytes = new TextEncoder().encode(value);
        let binary = '';
        bytes.forEach((byte) => { binary += String.fromCharCode(byte); });
        return btoa(binary);
    }

    function base64ToUtf8(value) {
        const binary = atob(value);
        const bytes = Uint8Array.from(binary, (char) => char.charCodeAt(0));
        return new TextDecoder().decode(bytes);
    }

    function initCodecs() {
        const base64Input = $('#tools-base64-input');
        if (base64Input) {
            $('#tools-base64-encode')?.addEventListener('click', () => {
                try {
                    setText('#tools-base64-output', utf8ToBase64(base64Input.value));
                } catch (error) {
                    setText('#tools-base64-output', 'Fehler: ' + error.message);
                }
            });
            $('#tools-base64-decode')?.addEventListener('click', () => {
                try {
                    setText('#tools-base64-output', base64ToUtf8(base64Input.value.trim()));
                } catch (error) {
                    setText('#tools-base64-output', 'Fehler: Base64 konnte nicht dekodiert werden.');
                }
            });
        }

        const urlInput = $('#tools-url-input');
        if (urlInput) {
            $('#tools-url-encode')?.addEventListener('click', () => setText('#tools-url-output', encodeURIComponent(urlInput.value)));
            $('#tools-url-decode')?.addEventListener('click', () => {
                try {
                    setText('#tools-url-output', decodeURIComponent(urlInput.value));
                } catch (error) {
                    setText('#tools-url-output', 'Fehler: URL konnte nicht dekodiert werden.');
                }
            });
        }
    }

    function initJson() {
        const input = $('#tools-json-input');
        const status = $('#tools-json-status');
        if (!input || !status) {
            return;
        }
        function parse() {
            return JSON.parse(input.value);
        }
        $('#tools-json-format')?.addEventListener('click', () => {
            try {
                input.value = JSON.stringify(parse(), null, 2);
                status.className = 'small mt-2 text-success';
                status.textContent = 'JSON ist gültig.';
            } catch (error) {
                status.className = 'small mt-2 text-danger';
                status.textContent = 'Ungültiges JSON: ' + error.message;
            }
        });
        $('#tools-json-minify')?.addEventListener('click', () => {
            try {
                input.value = JSON.stringify(parse());
                status.className = 'small mt-2 text-success';
                status.textContent = 'JSON ist gültig und minimiert.';
            } catch (error) {
                status.className = 'small mt-2 text-danger';
                status.textContent = 'Ungültiges JSON: ' + error.message;
            }
        });
    }

    function initGenerators() {
        $('#tools-uuid-generate')?.addEventListener('click', () => {
            const uuid = window.crypto?.randomUUID ? window.crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
                const random = Math.random() * 16 | 0;
                const value = char === 'x' ? random : (random & 0x3 | 0x8);
                return value.toString(16);
            });
            setText('#tools-uuid-output', uuid);
        });

        $('#tools-password-generate')?.addEventListener('click', () => {
            const status = $('#tools-password-status');
            const length = Math.max(8, Math.min(128, parseInt($('#tools-password-length')?.value || '24', 10)));
            const count = Math.max(1, Math.min(100, parseInt($('#tools-password-count')?.value || '1', 10)));
            const groups = [
                $('#tools-password-lower')?.checked ? 'abcdefghijkmnopqrstuvwxyz' : '',
                $('#tools-password-upper')?.checked ? 'ABCDEFGHJKLMNPQRSTUVWXYZ' : '',
                $('#tools-password-numbers')?.checked ? '23456789' : '',
                $('#tools-password-symbols')?.checked ? '!@#$%&*+-_=.?' : ''
            ].filter(Boolean);

            if (groups.length === 0) {
                if (status) {
                    status.className = 'small mt-2 text-danger';
                    status.textContent = 'Bitte mindestens eine Zeichengruppe auswählen.';
                }
                setOutput('#tools-password-output', '');
                return;
            }
            if (!window.crypto?.getRandomValues) {
                if (status) {
                    status.className = 'small mt-2 text-danger';
                    status.textContent = 'Sichere Zufallswerte werden von diesem Browser nicht unterstützt.';
                }
                return;
            }

            const alphabet = groups.join('');
            const randomIndex = (max) => {
                const bytes = new Uint32Array(1);
                window.crypto.getRandomValues(bytes);
                return bytes[0] % max;
            };
            const shuffle = (chars) => {
                for (let index = chars.length - 1; index > 0; index -= 1) {
                    const swapIndex = randomIndex(index + 1);
                    [chars[index], chars[swapIndex]] = [chars[swapIndex], chars[index]];
                }
                return chars;
            };
            const makePassword = () => {
                const chars = [];
                groups.forEach((group) => chars.push(group[randomIndex(group.length)]));
                while (chars.length < length) {
                    chars.push(alphabet[randomIndex(alphabet.length)]);
                }
                return shuffle(chars).join('');
            };

            const passwords = Array.from({ length: count }, makePassword);
            setOutput('#tools-password-output', passwords.join('\n'));
            if (status) {
                status.className = 'small mt-2 text-success';
                status.textContent = count === 1 ? 'Passwort erzeugt.' : count + ' Passwörter erzeugt.';
            }
        });
    }

    function initTimestamp() {
        const timestamp = $('#tools-timestamp');
        const date = $('#tools-date');
        if (!timestamp || !date) {
            return;
        }
        $('#tools-timestamp-to-date')?.addEventListener('click', () => {
            const seconds = parseInt(timestamp.value, 10);
            if (Number.isNaN(seconds)) {
                return;
            }
            const local = new Date(seconds * 1000);
            date.value = new Date(local.getTime() - local.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
        });
        $('#tools-date-to-timestamp')?.addEventListener('click', () => {
            const local = new Date(date.value);
            if (!Number.isNaN(local.getTime())) {
                timestamp.value = String(Math.floor(local.getTime() / 1000));
            }
        });
        $('#tools-timestamp-now')?.addEventListener('click', () => {
            timestamp.value = String(Math.floor(Date.now() / 1000));
            $('#tools-timestamp-to-date')?.dispatchEvent(new Event('click'));
        });
    }

    function sha256Fallback(value) {
        const bytes = Array.from(new TextEncoder().encode(value));
        const bitLength = bytes.length * 8;
        bytes.push(0x80);
        while ((bytes.length % 64) !== 56) {
            bytes.push(0);
        }
        for (let shift = 56; shift >= 0; shift -= 8) {
            bytes.push(Math.floor(bitLength / (2 ** shift)) & 0xff);
        }

        const rightRotate = (number, amount) => (number >>> amount) | (number << (32 - amount));
        const primes = [
            0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
            0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
            0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
            0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
            0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
            0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
            0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
            0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
        ];
        let hash = [0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19];

        for (let chunk = 0; chunk < bytes.length; chunk += 64) {
            const words = new Array(64);
            for (let index = 0; index < 16; index += 1) {
                const offset = chunk + index * 4;
                words[index] = ((bytes[offset] << 24) | (bytes[offset + 1] << 16) | (bytes[offset + 2] << 8) | bytes[offset + 3]) >>> 0;
            }
            for (let index = 16; index < 64; index += 1) {
                const s0 = rightRotate(words[index - 15], 7) ^ rightRotate(words[index - 15], 18) ^ (words[index - 15] >>> 3);
                const s1 = rightRotate(words[index - 2], 17) ^ rightRotate(words[index - 2], 19) ^ (words[index - 2] >>> 10);
                words[index] = (words[index - 16] + s0 + words[index - 7] + s1) >>> 0;
            }

            let [a, b, c, d, e, f, g, h] = hash;
            for (let index = 0; index < 64; index += 1) {
                const s1 = rightRotate(e, 6) ^ rightRotate(e, 11) ^ rightRotate(e, 25);
                const ch = (e & f) ^ (~e & g);
                const temp1 = (h + s1 + ch + primes[index] + words[index]) >>> 0;
                const s0 = rightRotate(a, 2) ^ rightRotate(a, 13) ^ rightRotate(a, 22);
                const maj = (a & b) ^ (a & c) ^ (b & c);
                const temp2 = (s0 + maj) >>> 0;
                h = g;
                g = f;
                f = e;
                e = (d + temp1) >>> 0;
                d = c;
                c = b;
                b = a;
                a = (temp1 + temp2) >>> 0;
            }
            hash = hash.map((value, index) => (value + [a, b, c, d, e, f, g, h][index]) >>> 0);
        }

        return hash.map((value) => value.toString(16).padStart(8, '0')).join('');
    }

    async function digestHex(algorithm, value) {
        if (window.crypto?.subtle) {
            const buffer = await window.crypto.subtle.digest(algorithm, new TextEncoder().encode(value));
            return Array.from(new Uint8Array(buffer), (byte) => byte.toString(16).padStart(2, '0')).join('');
        }
        if (algorithm === 'SHA-256') {
            return sha256Fallback(value);
        }
        throw new Error('Web Crypto API unavailable');
    }

    function initCopyButtons() {
        $$('[data-copy-target]').forEach((button) => {
            button.addEventListener('click', async () => {
                const target = $(String(button.dataset.copyTarget || ''));
                if (!target) {
                    return;
                }
                const value = 'value' in target ? target.value : target.textContent;
                if (!value) {
                    return;
                }
                try {
                    await navigator.clipboard.writeText(value);
                    const original = button.textContent;
                    button.textContent = 'Kopiert';
                    setTimeout(() => {
                        button.textContent = original;
                    }, 1200);
                } catch (error) {
                    const original = button.textContent;
                    button.textContent = 'Nicht kopiert';
                    setTimeout(() => {
                        button.textContent = original;
                    }, 1600);
                }
            });
        });
    }

    function initHash() {
        const input = $('#tools-hash-input');
        const button = $('#tools-hash-generate');
        const algorithm = $('#tools-hash-algorithm');
        if (!input || !button || !algorithm) {
            return;
        }
        button.addEventListener('click', async () => {
            const selected = String(algorithm.value || 'SHA-256');
            if (!window.crypto?.subtle && selected !== 'SHA-256') {
                setText('#tools-hash-output', 'Dieser Algorithmus benötigt die Web Crypto API. Bitte HTTPS oder localhost verwenden.');
                return;
            }

            button.disabled = true;
            setText('#tools-hash-output', selected + ' wird erzeugt...');
            try {
                const hash = await digestHex(selected, input.value);
                setText('#tools-hash-output', selected + ':\n' + hash);
            } catch (error) {
                setText('#tools-hash-output', 'Hash konnte nicht erzeugt werden. Der Algorithmus wird möglicherweise nicht unterstützt.');
            } finally {
                button.disabled = false;
            }
        });
    }

    function initQr() {
        const input = $('#tools-qr-input');
        const canvas = $('#tools-qr-canvas');
        const status = $('#tools-qr-status');
        if (!input || !canvas) {
            return;
        }
        $('#tools-qr-generate')?.addEventListener('click', () => {
            const text = input.value.trim();
            if (text === '') {
                if (status) status.textContent = 'Bitte Text oder URL eingeben.';
                return;
            }
            try {
                if (window.QRCode && typeof window.QRCode.toCanvas === 'function') {
                    window.QRCode.toCanvas(canvas, text, { width: 220, margin: 1 }, (error) => {
                        if (status) status.textContent = error ? 'QR-Code konnte nicht erzeugt werden.' : 'QR-Code erzeugt.';
                    });
                    return;
                }
                if (typeof window.qrcode !== 'function') {
                    if (status) status.textContent = 'QR-Bibliothek konnte nicht geladen werden.';
                    return;
                }

                const qr = window.qrcode(0, 'M');
                qr.addData(text);
                qr.make();

                const context = canvas.getContext('2d');
                if (!context) {
                    if (status) status.textContent = 'QR-Code konnte nicht gezeichnet werden.';
                    return;
                }

                const modules = qr.getModuleCount();
                const margin = 8;
                const size = Math.min(canvas.width, canvas.height);
                const cell = Math.floor((size - margin * 2) / modules);
                const qrSize = cell * modules;
                const offset = Math.floor((size - qrSize) / 2);

                context.fillStyle = '#ffffff';
                context.fillRect(0, 0, canvas.width, canvas.height);
                context.fillStyle = '#000000';
                for (let row = 0; row < modules; row += 1) {
                    for (let col = 0; col < modules; col += 1) {
                        if (qr.isDark(row, col)) {
                            context.fillRect(offset + col * cell, offset + row * cell, cell, cell);
                        }
                    }
                }
                if (status) status.textContent = 'QR-Code erzeugt.';
            } catch (error) {
                if (status) status.textContent = 'QR-Code konnte nicht erzeugt werden.';
            }
        });
    }

    function markdownToHtml(value) {
        let text = escapeHtml(value);
        text = text.replace(/^### (.*)$/gm, '<h3>$1</h3>');
        text = text.replace(/^## (.*)$/gm, '<h2>$1</h2>');
        text = text.replace(/^# (.*)$/gm, '<h1>$1</h1>');
        text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        text = text.replace(/\*(.*?)\*/g, '<em>$1</em>');
        text = text.replace(/`([^`]+)`/g, '<code>$1</code>');
        text = text.replace(/\[([^\]]+)]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" rel="noopener noreferrer" target="_blank">$1</a>');
        return text.split(/\n{2,}/).map((block) => {
            if (/^<h[1-3]>/.test(block)) {
                return block;
            }
            return '<p>' + block.replace(/\n/g, '<br>') + '</p>';
        }).join('');
    }

    function initMarkdown() {
        const input = $('#tools-markdown-input');
        const preview = $('#tools-markdown-preview');
        if (!input || !preview) {
            return;
        }
        const update = () => {
            preview.innerHTML = markdownToHtml(input.value);
        };
        input.addEventListener('input', update);
        update();
    }

    function initRegex() {
        const pattern = $('#tools-regex-pattern');
        const flags = $('#tools-regex-flags');
        const text = $('#tools-regex-text');
        const output = $('#tools-regex-output');
        if (!pattern || !flags || !text || !output) {
            return;
        }
        const update = () => {
            try {
                const regex = new RegExp(pattern.value, flags.value);
                const matches = Array.from(text.value.matchAll(regex));
                output.textContent = matches.length === 0
                    ? 'Keine Treffer.'
                    : matches.map((match, index) => '#' + (index + 1) + ' [' + match.index + ']: ' + match[0]).join('\n');
            } catch (error) {
                output.textContent = 'Regex-Fehler: ' + error.message;
            }
        };
        [pattern, flags, text].forEach((node) => node.addEventListener('input', update));
        update();
    }

    function renderAdminResult(data) {
        const target = $('#tools-admin-result');
        if (!target) {
            return;
        }
        const ok = Boolean(data.ok);
        const output = data.output || JSON.stringify(data.job || data.records || {}, null, 2);
        target.innerHTML = [
            '<div class="' + (ok ? 'text-success' : 'text-danger') + ' fw-semibold mb-1">' + escapeHtml(data.title || (ok ? 'OK' : 'Fehler')) + '</div>',
            '<div class="mb-2">' + escapeHtml(data.summary || '') + '</div>',
            output ? '<pre class="tools-output mb-0">' + escapeHtml(output) + '</pre>' : ''
        ].join('');
        const resultCard = $('#tools-admin-result-card');
        if (resultCard) {
            resultCard.classList.add('tools-admin-result-card-active');
            const rect = resultCard.getBoundingClientRect();
            const visible = rect.top >= 0 && rect.bottom <= window.innerHeight;
            if (!visible) {
                resultCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    }

    function statusClass(status) {
        if (status === 'done') return 'text-bg-success';
        if (status === 'running') return 'text-bg-primary';
        if (status === 'queued') return 'text-bg-warning';
        if (status === 'error') return 'text-bg-danger';
        return 'text-bg-secondary';
    }

    function renderSpeechJobs(jobs) {
        const target = $('#tools-speech-jobs');
        if (!target) {
            return;
        }
        if (!Array.isArray(jobs) || jobs.length === 0) {
            target.innerHTML = '<div class="alert alert-secondary mb-0">Noch keine Speech-to-Text-Jobs vorhanden.</div>';
            return;
        }
        const deleteUrl = target.dataset.deleteUrl || '/admin/tools/speech/delete';
        const csrfToken = target.dataset.csrfToken || '';
        const openRawDetails = new Set($$('.tools-speech-job details[open]', target)
            .map((details) => details.closest('.tools-speech-job')?.dataset.jobId || '')
            .filter(Boolean));
        target.innerHTML = '<div class="vstack gap-2">' + jobs.map((job) => {
            const status = String(job.status || '');
            const jobId = String(job.id || '');
            const results = job.results || {};
            const links = ['txt', 'srt', 'vtt'].map((format) => {
                const result = results[format] || {};
                if (!result.available || !result.url) {
                    return '';
                }
                return '<a class="btn btn-outline-secondary btn-sm" href="' + escapeHtml(result.url) + '">' + format.toUpperCase() + ' herunterladen</a>';
            }).join('');
            const modelName = job.model_name || String(job.model_path || '').split('/').pop();
            const prettyTranscript = String(job.transcript_pretty || '');
            const rawTranscript = String(job.transcript || '');
            const visibleTranscript = prettyTranscript || rawTranscript;
            const transcript = visibleTranscript
                ? '<div class="tools-transcript-flow mt-2 mb-2">' + escapeHtmlWithBreaks(visibleTranscript) + '</div>'
                    + (rawTranscript && rawTranscript !== prettyTranscript
                        ? '<details class="small mb-2"' + (openRawDetails.has(jobId) ? ' open' : '') + '><summary>Rohtext anzeigen</summary><pre class="tools-output mt-2 mb-0">' + escapeHtml(rawTranscript) + '</pre></details>'
                        : '')
                : '';
            const error = job.error
                ? '<div class="text-danger small mt-2">' + escapeHtml(job.error) + '</div>'
                : '';
            const deleteForm = status !== 'running'
                ? '<form method="post" action="' + escapeHtml(deleteUrl) + '" onsubmit="return confirm(&quot;Speech-to-Text-Job wirklich löschen?&quot;);">'
                    + '<input type="hidden" name="_csrf" value="' + escapeHtml(csrfToken) + '">'
                    + '<input type="hidden" name="job_id" value="' + escapeHtml(job.id || '') + '">'
                    + '<button class="btn btn-outline-danger btn-sm" type="submit">Löschen</button>'
                    + '</form>'
                : '';

            return [
                '<article class="tools-speech-job border rounded-3 p-3" data-job-id="' + escapeHtml(jobId) + '">',
                '<div class="d-flex flex-wrap justify-content-between gap-2">',
                '<div><strong>' + escapeHtml(job.original_name || 'Audio') + '</strong>',
                '<div class="text-muted small">' + escapeHtml(job.created_at_local || job.created_at || '') + (job.timezone_name ? ' · ' + escapeHtml(job.timezone_name) : '') + ' · ' + escapeHtml(String(job.language || '').toUpperCase()) + '</div>',
                '<div class="text-muted small">Modell: ' + escapeHtml(modelName || '') + '</div>',
                job.duration_label ? '<div class="text-muted small">Laufzeit: ' + escapeHtml(job.duration_label) + '</div>' : '',
                '</div>',
                '<span class="badge ' + statusClass(status) + '">' + escapeHtml(status) + '</span>',
                '</div>',
                '<div class="text-muted small mt-2">Originaldatei: ' + (job.source_file_available ? 'vorhanden' : 'entfernt') + ' · WAV: ' + (job.wav_file_available ? 'vorhanden' : 'entfernt') + '</div>',
                error,
                transcript,
                (links || deleteForm) ? '<div class="d-flex flex-wrap gap-2 mt-2">' + links + deleteForm + '</div>' : '',
                '</article>'
            ].join('');
        }).join('') + '</div>';
    }

    async function refreshSpeechJobs() {
        const target = $('#tools-speech-jobs');
        if (!target || !target.dataset.statusUrl) {
            return;
        }
        try {
            const response = await fetch(target.dataset.statusUrl, { credentials: 'same-origin' });
            const data = await response.json();
            if (data && Array.isArray(data.jobs)) {
                renderSpeechJobs(data.jobs);
            }
        } catch (error) {
            // Keep the previous visible state; polling should not be noisy.
        }
    }

    function hasActiveSpeechJobs() {
        return $$('.tools-speech-job .badge').some((badge) => ['queued', 'running'].includes(badge.textContent.trim()));
    }

    function csrfHeaders(form) {
        const csrfToken = form.querySelector('input[name="_csrf"]')?.value || '';

        return {
            'Accept': 'application/json',
            'X-CSRF-Token': csrfToken,
            'X-Requested-With': 'XMLHttpRequest'
        };
    }

    function initAdminForms() {
        const speechModelSelect = $('#tools-speech-model-select');
        const speechModelInput = $('#tools-speech-model');
        if (speechModelSelect && speechModelInput) {
            speechModelSelect.addEventListener('change', () => {
                if (speechModelSelect.value !== '') {
                    speechModelInput.value = speechModelSelect.value;
                }
            });
        }

        $$('.js-tools-admin-form').forEach((form) => {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                renderAdminResult({ ok: true, title: 'Läuft', summary: 'Tool wird ausgeführt...' });
                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        body: new FormData(form),
                        credentials: 'same-origin',
                        headers: csrfHeaders(form)
                    });
                    renderAdminResult(await response.json());
                } catch (error) {
                    renderAdminResult({ ok: false, title: 'Fehler', summary: 'Request fehlgeschlagen.' });
                }
            });
        });

        $$('.js-tools-speech-form').forEach((form) => {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                renderAdminResult({ ok: true, title: 'Upload', summary: 'Upload wird gespeichert und Worker wird gestartet...' });
                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        body: new FormData(form),
                        credentials: 'same-origin',
                        headers: csrfHeaders(form)
                    });
                    const data = await response.json();
                    renderAdminResult(data);
                    if (data && data.job) {
                        await refreshSpeechJobs();
                    }
                } catch (error) {
                    renderAdminResult({ ok: false, title: 'Fehler', summary: 'Upload fehlgeschlagen.' });
                }
            });
        });

        $('#tools-speech-refresh')?.addEventListener('click', refreshSpeechJobs);

        const jobsTarget = $('#tools-speech-jobs');
        if (jobsTarget) {
            setInterval(() => {
                if (document.hidden) {
                    return;
                }
                refreshSpeechJobs();
            }, hasActiveSpeechJobs() ? 2500 : 5000);
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        initFilters();
        initTextCounter();
        initTextCleaner();
        initCodecs();
        initJson();
        initGenerators();
        initTimestamp();
        initCopyButtons();
        initHash();
        initQr();
        initMarkdown();
        initRegex();
        initAdminForms();
    });
})();
