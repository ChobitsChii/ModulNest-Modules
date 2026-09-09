/* Optional UI helper only. Server-side validation remains authoritative. */
const WikiSourceUrl = (() => {
    const validSegment = (value) => /^[A-Za-z0-9][A-Za-z0-9._-]*$/.test(value);
    const validPath = (value) => value.split('/').every(validSegment);
    const encodedPath = (value) => value.split('/').filter(Boolean).map(encodeURIComponent).join('/');

    const build = ({ owner, repository, ref, docsRoot }) => {
        const ownerValue = (owner || '').trim();
        const repoValue = (repository || '').trim();
        const refValue = (ref || '').trim();
        const rootValue = (docsRoot || '').trim().replace(/^\/+|\/+$/g, '');
        if (!validSegment(ownerValue) || !validSegment(repoValue)) return null;
        let value = `https://github.com/${encodeURIComponent(ownerValue)}/${encodeURIComponent(repoValue)}`;
        if (refValue && validPath(refValue)) {
            value += `/tree/${encodedPath(refValue)}`;
            if (rootValue && validPath(rootValue)) value += `/${encodedPath(rootValue)}`;
        }
        return value;
    };

    const parse = (raw, fallback = {}) => {
        let parsed;
        try { parsed = new URL((raw || '').trim()); } catch (_) { return { ok: false, error: 'Bitte eine gültige GitHub-URL eingeben.' }; }
        if (parsed.protocol !== 'https:' || parsed.hostname.toLowerCase() !== 'github.com' || parsed.username || parsed.password || parsed.port || parsed.search || parsed.hash) {
            return { ok: false, error: 'Unterstützt werden nur sichere https://github.com-Repository-URLs.' };
        }
        const parts = parsed.pathname.split('/').filter(Boolean).map(decodeURIComponent);
        if (!validSegment(parts[0] || '') || !validSegment(parts[1] || '')) {
            return { ok: false, error: 'Bitte eine Repository-URL oder eine URL zum Branch/Tag und Dokumentationsordner verwenden.' };
        }
        if (parts.length === 2) return { ok: true, owner: parts[0], repository: parts[1], ref: fallback.ref || 'main', docsRoot: fallback.docsRoot || 'docs' };
        if (parts.length < 5 || parts[2] !== 'tree') {
            return { ok: false, error: 'Bitte eine Repository-URL oder eine URL zum Branch/Tag und Dokumentationsordner verwenden.' };
        }
        const ref = parts[3];
        const docsRoot = parts.slice(4).join('/');
        if (!validSegment(ref) || !validPath(docsRoot)) {
            return { ok: false, error: 'Die URL benötigt einen einfachen Branch- oder Tag-Namen und einen gültigen Dokumentationsordner.' };
        }
        return { ok: true, owner: parts[0], repository: parts[1], ref, docsRoot };
    };

    const bind = () => {
        const form = document.querySelector('[data-wiki-source-form]');
        if (!form) return;
        const urlField = form.querySelector('[data-wiki-url]');
        const feedback = form.querySelector('[data-wiki-url-feedback]');
        const owner = form.querySelector('#wiki_owner');
        const repository = form.querySelector('#wiki_repository');
        const ref = form.querySelector('#wiki_ref');
        const docsRoot = form.querySelector('#wiki_docs_root');
        if (!urlField || !feedback || !owner || !repository || !ref || !docsRoot) return;
        const clearFeedback = () => { urlField.classList.remove('is-invalid'); feedback.textContent = ''; };
        const showFeedback = (message) => { urlField.classList.add('is-invalid'); feedback.textContent = message; };
        const updateUrl = () => { const next = build({ owner: owner.value, repository: repository.value, ref: ref.value, docsRoot: docsRoot.value }); if (next !== null) { urlField.value = next; clearFeedback(); } };
        const applyUrl = () => {
            if (urlField.value.trim() === '') { clearFeedback(); return; }
            const next = parse(urlField.value, { ref: ref.value.trim(), docsRoot: docsRoot.value.trim() });
            if (!next.ok) { showFeedback(next.error); return; }
            owner.value = next.owner; repository.value = next.repository; ref.value = next.ref; docsRoot.value = next.docsRoot;
            updateUrl();
        };
        // Do not change canonical fields until a complete supported URL parses.
        urlField.addEventListener('input', applyUrl);
        urlField.addEventListener('change', applyUrl);
        urlField.addEventListener('paste', () => window.setTimeout(applyUrl, 0));
        [owner, repository, ref, docsRoot].forEach((field) => field.addEventListener('input', updateUrl));
        updateUrl();
        const type = form.querySelector('[data-wiki-source-type]');
        const pickerButton = form.querySelector('[data-wiki-directory-picker]');
        const applyType = () => {
            const local = type?.value === 'local';
            form.querySelectorAll('[data-wiki-github-field]').forEach((node) => { node.hidden = local; });
            const help = form.querySelector('[data-wiki-local-help]'); if (help) help.hidden = !local;
            if (pickerButton) pickerButton.hidden = !local;
            const pathHelp = form.querySelector('[data-wiki-path-help]'); if (pathHelp) pathHelp.textContent = local ? 'Pfad relativ zur ModulNest-Installation. Verzeichnisse außerhalb der Installation sind nicht verfügbar.' : 'Pfad innerhalb des GitHub-Repositories, z. B. docs.';
        };
        type?.addEventListener('change', applyType); applyType();
        const modalElement = document.querySelector('#wikiDirectoryPicker');
        const pathLabel = modalElement?.querySelector('[data-wiki-picker-path]');
        const directories = modalElement?.querySelector('[data-wiki-picker-directories]');
        const parent = modalElement?.querySelector('[data-wiki-picker-parent]');
        const select = modalElement?.querySelector('[data-wiki-picker-select]');
        const error = modalElement?.querySelector('[data-wiki-picker-error]');
        let pickerPath = '';
        const loadDirectories = async (path) => {
            if (!modalElement || !directories || !pathLabel || !parent || !error) return;
            error.classList.add('d-none'); directories.textContent = 'Wird geladen …';
            const response = await fetch(`/admin/wiki/local-directories?path=${encodeURIComponent(path)}`, {headers:{Accept:'application/json'}});
            if (!response.ok) { error.textContent='Das Verzeichnis ist nicht verfügbar.'; error.classList.remove('d-none'); directories.textContent=''; return; }
            const data = await response.json(); pickerPath=data.path||''; pathLabel.textContent=pickerPath||'/'; parent.hidden=pickerPath === '';
            directories.replaceChildren(...(data.directories||[]).map((name)=>{const button=document.createElement('button');button.type='button';button.className='list-group-item list-group-item-action wiki-directory-picker-item';button.textContent=`📁 ${name}`;button.addEventListener('click',()=>loadDirectories([pickerPath,name].filter(Boolean).join('/')));return button;}));
        };
        pickerButton?.addEventListener('click', () => { loadDirectories(docsRoot.value.trim()); window.bootstrap?.Modal.getOrCreateInstance(modalElement).show(); });
        parent?.addEventListener('click', () => loadDirectories(pickerPath.split('/').slice(0,-1).join('/')));
        select?.addEventListener('click', () => { docsRoot.value=pickerPath; window.bootstrap?.Modal.getOrCreateInstance(modalElement).hide(); });
    };
    return { build, parse, bind };
})();

const WikiNavigation = (() => {
    const storagePrefix = 'modulnest.wiki.nav.';

    const bind = () => {
        document.querySelectorAll('[data-wiki-nav-group]').forEach((group) => {
            const toggle = group.querySelector('[data-wiki-nav-toggle]');
            const content = toggle ? document.getElementById(toggle.getAttribute('aria-controls') || '') : null;
            const key = group.dataset.wikiNavGroup || '';
            if (!toggle || !content || !key) return;

            const containsActivePage = group.classList.contains('is-active-group');
            let expanded = containsActivePage;
            if (!containsActivePage) {
                try {
                    const saved = window.sessionStorage.getItem(storagePrefix + key);
                    if (saved !== null) expanded = saved === 'open';
                } catch (_) { /* Private mode or blocked storage: retain the safe default. */ }
            }

            const apply = () => {
                content.hidden = !expanded;
                toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                group.classList.toggle('is-collapsed', !expanded);
            };
            toggle.addEventListener('click', () => {
                expanded = !expanded;
                try { window.sessionStorage.setItem(storagePrefix + key, expanded ? 'open' : 'closed'); } catch (_) { /* no persistent preference */ }
                apply();
            });
            apply();
        });
    };
    return { bind };
})();

const WikiSearch = (() => {
    const delay = 280;
    const appendHighlighted = (node, text, terms) => {
        const source = String(text || '');
        const needles = [...new Set((terms || []).map((term) => String(term)).filter(Boolean))].sort((a, b) => b.length - a.length);
        if (!needles.length) { node.textContent = source; return; }
        const lower = source.toLocaleLowerCase(); let offset = 0;
        while (offset < source.length) {
            let next = null;
            needles.forEach((needle) => { const position=lower.indexOf(needle.toLocaleLowerCase(),offset);if(position>=0&&(next===null||position<next.position||(position===next.position&&needle.length>next.needle.length)))next={position,needle}; });
            if (next === null) { node.append(document.createTextNode(source.slice(offset))); break; }
            if (next.position > offset) node.append(document.createTextNode(source.slice(offset,next.position)));
            const mark=document.createElement('mark');mark.textContent=source.slice(next.position,next.position+next.needle.length);node.append(mark);offset=next.position+next.needle.length;
        }
    };
    const resultNode = (result) => {
        const article = document.createElement('article'); article.className = 'wiki-search-result'; article.setAttribute('role', 'option');
        const route = result.route_path === 'index' ? '' : String(result.route_path).split('/').map(encodeURIComponent).join('/');
        const link = document.createElement('a'); link.href = `/wiki/${route}`; appendHighlighted(link,result.title,result.matched_terms); article.append(link);
        const context = document.createElement('div'); context.className = 'wiki-search-context'; appendHighlighted(context,result.context,result.matched_terms); article.append(context);
        const snippet = document.createElement('p'); appendHighlighted(snippet,result.snippet,result.matched_terms);
        article.append(snippet); return article;
    };
    const bind = () => document.querySelectorAll('[data-wiki-search]').forEach((form) => {
        const input=form.querySelector('[data-wiki-search-input]');const output=form.querySelector('[data-wiki-search-results]');if(!input||!output)return;
        const floating = output.classList.contains('wiki-search-popover');
        const place = () => { if(!floating)return;const rect=input.getBoundingClientRect();const left=Math.max(8,rect.left);output.style.left=`${left}px`;output.style.top=`${rect.bottom+4}px`;output.style.width=`${Math.min(672,window.innerWidth-left-8)}px`; };
        if(floating){document.body.append(output);window.addEventListener('resize',place);document.addEventListener('scroll',place,true);}
        let timer=0,controller=null,active=-1,requestSequence=0;
        const close=()=>{if(!form.classList.contains('wiki-search-form-page'))output.hidden=true;input.setAttribute('aria-expanded','false');active=-1;};
        const render=(data)=>{output.replaceChildren();const query=input.value.trim();if(query.length<2){close();return;}if(!data.available){const p=document.createElement('p');p.className='wiki-search-message';p.textContent='Suchindex noch nicht verfügbar.';output.append(p);}else if(!data.results.length){const p=document.createElement('p');p.className='wiki-search-message';p.textContent='Keine Treffer gefunden.';output.append(p);}else output.append(...data.results.map(resultNode));place();output.hidden=false;input.setAttribute('aria-expanded','true');};
        const run=async(sequence)=>{const query=input.value.trim();if(query.length<2){render({available:true,results:[]});return;}controller=new AbortController();try{const response=await fetch(`/wiki/search?q=${encodeURIComponent(query)}`,{headers:{Accept:'application/json'},signal:controller.signal});const data=response.ok?await response.json():null;if(data!==null&&sequence===requestSequence&&query===input.value.trim())render(data);}catch(error){if(error.name!=='AbortError'&&sequence===requestSequence)close();}};
        input.addEventListener('input',()=>{window.clearTimeout(timer);requestSequence++;controller?.abort();const sequence=requestSequence;timer=window.setTimeout(()=>run(sequence),delay);});
        input.addEventListener('keydown',(event)=>{const links=[...output.querySelectorAll('a')];if(event.key==='Escape'){close();return;}if(!['ArrowDown','ArrowUp'].includes(event.key)||links.length===0)return;event.preventDefault();active=(active+(event.key==='ArrowDown'?1:-1)+links.length)%links.length;links[active].focus();});
        form.addEventListener('focusout',()=>window.setTimeout(()=>{if(!form.contains(document.activeElement)&&!output.contains(document.activeElement))close();},0));
    });
    return { bind };
})();

if (typeof module !== 'undefined' && module.exports) module.exports = WikiSourceUrl;
if (typeof document !== 'undefined') {
    WikiSourceUrl.bind();
    WikiNavigation.bind();
    WikiSearch.bind();
}
