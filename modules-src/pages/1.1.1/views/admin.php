<?php
declare(strict_types=1);

$entries = is_array($entries ?? null) ? $entries : [];
$message = (string) ($message ?? '');
$error = (string) ($error ?? '');
$visibilities = is_array($visibilities ?? null) ? $visibilities : ['public', 'user', 'admin'];
$menuGroups = is_array($menu_groups ?? null) ? $menu_groups : [];
$csrfToken = (string) ($csrf_token ?? '');
?>
<div class="container py-4 pages-admin" data-pages-admin>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0">Pages</h1>
        <span class="text-body-secondary small">Microsites wie Impressum und Datenschutz</span>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-success" role="alert"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm app-card mb-3">
        <div class="card-body p-3 p-md-4">
            <h2 class="h6 mb-3">Neue Seite</h2>
            <form method="post" action="/admin/pages/create" class="row g-2">
                <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                <div class="col-12 col-md-6">
                    <label class="form-label small mb-1">Titel</label>
                    <input class="form-control" type="text" name="title" required>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label small mb-1">Slug / URL</label>
                    <input class="form-control" type="text" name="slug" placeholder="impressum">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label small mb-1">Sichtbarkeit</label>
                    <select class="form-select" name="visibility">
                        <?php foreach ($visibilities as $visibility): ?>
                            <option value="<?= htmlspecialchars((string) $visibility, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) ucfirst((string) $visibility), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-5" data-pages-menu-group-wrap>
                    <label class="form-label small mb-1">Menügruppe</label>
                    <input class="form-control" type="text" name="menu_group" placeholder="Rechtliches" list="pages-menu-groups" data-pages-menu-group>
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label small mb-1">Status</label>
                    <select class="form-select" name="is_active">
                        <option value="1">Aktiv</option>
                        <option value="0">Inaktiv</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label small mb-1">Markdown-Inhalt</label>
                    <textarea class="form-control" name="content_markdown" rows="6" required></textarea>
                </div>
                <div class="col-12 d-flex flex-wrap gap-3">
                    <div class="form-check">
                        <input class="form-check-input" id="createShowInHeader" type="checkbox" name="show_in_header" value="1" data-pages-show-in-header>
                        <label class="form-check-label" for="createShowInHeader">Im Header anzeigen</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" id="createShowInFooter" type="checkbox" name="show_in_footer" value="1">
                        <label class="form-check-label" for="createShowInFooter">Im Footer anzeigen</label>
                    </div>
                </div>
                <div class="col-12 text-body-secondary small">
                    Menügruppe ist nur relevant, wenn die Seite im Header angezeigt wird.
                </div>
                <div class="col-12">
                    <button class="btn btn-primary" type="submit">Seite anlegen</button>
                </div>
            </form>
        </div>
    </div>
    <datalist id="pages-menu-groups">
        <?php foreach ($menuGroups as $group): ?>
            <?php $groupValue = trim((string) $group); if ($groupValue === '') { continue; } ?>
            <option value="<?= htmlspecialchars($groupValue, ENT_QUOTES, 'UTF-8') ?>"></option>
        <?php endforeach; ?>
    </datalist>

    <?php foreach ($entries as $entry): ?>
        <?php
        $id = (int) ($entry['id'] ?? 0);
        $isActive = (int) ($entry['is_active'] ?? 0) === 1;
        $showInHeader = (int) ($entry['show_in_header'] ?? 0) === 1;
        $showInFooter = (int) ($entry['show_in_footer'] ?? 0) === 1;
        $slug = (string) ($entry['slug'] ?? '');
        ?>
        <div class="card border-0 shadow-sm app-card mb-3" data-pages-row="<?= $id ?>">
            <div class="card-body p-3 p-md-4">
                <form method="post" action="/admin/pages/update" class="row g-2 mb-2">
                    <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                    <input type="hidden" name="entry_id" value="<?= $id ?>">
                    <div class="col-12 col-md-4">
                        <label class="form-label small mb-1">Titel</label>
                        <input class="form-control" type="text" name="title" value="<?= htmlspecialchars((string) ($entry['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label small mb-1">Slug / URL</label>
                        <input class="form-control" type="text" name="slug" value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="col-12 col-md-4" data-pages-menu-group-wrap>
                        <label class="form-label small mb-1">Menügruppe</label>
                        <input class="form-control" type="text" name="menu_group" value="<?= htmlspecialchars((string) ($entry['menu_group'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" list="pages-menu-groups" data-pages-menu-group>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label small mb-1">Sichtbarkeit</label>
                        <select class="form-select" name="visibility">
                            <?php foreach ($visibilities as $visibility): ?>
                                <?php $selected = (string) ($entry['visibility'] ?? 'public') === (string) $visibility; ?>
                                <option value="<?= htmlspecialchars((string) $visibility, ENT_QUOTES, 'UTF-8') ?>"<?= $selected ? ' selected' : '' ?>><?= htmlspecialchars((string) ucfirst((string) $visibility), ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-2">
                        <label class="form-label small mb-1">Status</label>
                        <select class="form-select" name="is_active">
                            <option value="1"<?= $isActive ? ' selected' : '' ?>>Aktiv</option>
                            <option value="0"<?= !$isActive ? ' selected' : '' ?>>Inaktiv</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-7">
                        <label class="form-label small mb-1">Öffentliche URL</label>
                        <div class="pages-url-preview" data-pages-url-preview>/pages/<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1">Markdown-Inhalt</label>
                        <textarea class="form-control" name="content_markdown" rows="6" required><?= htmlspecialchars((string) ($entry['content_markdown'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-3">
                        <div class="form-check">
                            <input class="form-check-input" id="entryShowInHeader<?= $id ?>" type="checkbox" name="show_in_header" value="1" data-pages-show-in-header<?= $showInHeader ? ' checked' : '' ?>>
                            <label class="form-check-label" for="entryShowInHeader<?= $id ?>">Im Header anzeigen</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" id="entryShowInFooter<?= $id ?>" type="checkbox" name="show_in_footer" value="1"<?= $showInFooter ? ' checked' : '' ?>>
                            <label class="form-check-label" for="entryShowInFooter<?= $id ?>">Im Footer anzeigen</label>
                        </div>
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2">
                        <button class="btn btn-primary btn-sm" type="submit">Speichern</button>
                        <button class="btn btn-outline-secondary btn-sm" type="button" data-pages-toggle data-entry-id="<?= $id ?>" data-next-active="<?= $isActive ? '0' : '1' ?>">
                            <?= $isActive ? 'Deaktivieren' : 'Aktivieren' ?>
                        </button>
                        <button class="btn btn-outline-secondary btn-sm" type="button" data-pages-move data-entry-id="<?= $id ?>" data-direction="up">Hoch</button>
                        <button class="btn btn-outline-secondary btn-sm" type="button" data-pages-move data-entry-id="<?= $id ?>" data-direction="down">Runter</button>
                    </div>
                </form>

                <form method="post" action="/admin/pages/delete" onsubmit="return confirm('Seite wirklich löschen?');">
                    <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                    <input type="hidden" name="entry_id" value="<?= $id ?>">
                    <button class="btn btn-outline-danger btn-sm" type="submit">Löschen</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<script>
(function(){
    const root=document.querySelector('[data-pages-admin]');
    if(!root){return;}
    const csrfHeaders={'X-CSRF-Token':<?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,'Accept':'application/json'};
    function normalizeSlug(value){
        return String(value||'')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g,'-')
            .replace(/^-+|-+$/g,'');
    }
    function updateUrlPreview(form){
        const slugInput=form?.querySelector('input[name="slug"]');
        const preview=form?.querySelector('[data-pages-url-preview]');
        if(!(slugInput instanceof HTMLInputElement) || !(preview instanceof HTMLElement)){return;}
        const slug=normalizeSlug(slugInput.value);
        preview.textContent='/pages/'+(slug!==''?slug:'...');
    }
    function syncMenuGroupState(form){
        const showInHeader=form?.querySelector('[data-pages-show-in-header]');
        const menuGroupWrap=form?.querySelector('[data-pages-menu-group-wrap]');
        if(!(showInHeader instanceof HTMLInputElement) || !(menuGroupWrap instanceof HTMLElement)){return;}
        menuGroupWrap.classList.toggle('d-none',!showInHeader.checked);
    }
    function swapRows(rowA,rowB){
        if(!rowA||!rowB||rowA===rowB){return;}
        const parent=rowA.parentNode;
        if(!parent||parent!==rowB.parentNode){return;}
        const marker=document.createElement('div');
        parent.insertBefore(marker,rowA);
        parent.insertBefore(rowA,rowB);
        parent.insertBefore(rowB,marker);
        parent.removeChild(marker);
    }
    function findRow(id){
        return root.querySelector('[data-pages-row=\"'+String(id)+'\"]');
    }
    async function post(url,payload){
        const body=new URLSearchParams(payload);
        const res=await fetch(url,{method:'POST',headers:{...csrfHeaders,'Content-Type':'application/x-www-form-urlencoded'},body});
        const contentType=res.headers.get('content-type')||'';
        if(!res.ok||!contentType.includes('application/json')){throw new Error('Aktion fehlgeschlagen.');}
        const json=await res.json();
        if(!json.success){throw new Error('Aktion fehlgeschlagen.');}
        return json;
    }
    root.addEventListener('click',async function(event){
        const target=event.target;
        if(!(target instanceof Element)){return;}
        const toggle=target.closest('[data-pages-toggle]');
        if(toggle){
            event.preventDefault();
            const entryId=toggle.getAttribute('data-entry-id')||'0';
            const nextActive=toggle.getAttribute('data-next-active')||'0';
            try{
                await post('/admin/pages/toggle',{entry_id:entryId,active:nextActive});
                const nowActive=nextActive==='1';
                toggle.setAttribute('data-next-active',nowActive?'0':'1');
                toggle.textContent=nowActive?'Deaktivieren':'Aktivieren';
                const statusSelect=toggle.closest('form')?.querySelector('select[name=\"is_active\"]');
                if(statusSelect instanceof HTMLSelectElement){
                    statusSelect.value=nowActive?'1':'0';
                }
            }catch(error){
                alert(error instanceof Error?error.message:'Aktion fehlgeschlagen.');
            }
            return;
        }
        const move=target.closest('[data-pages-move]');
        if(move){
            event.preventDefault();
            const entryId=move.getAttribute('data-entry-id')||'0';
            const direction=move.getAttribute('data-direction')||'';
            try{
                await post('/admin/pages/move',{entry_id:entryId,direction:direction});
                const row=findRow(entryId);
                if(!row){return;}
                if(direction==='up'){
                    const previous=row.previousElementSibling;
                    if(previous){swapRows(row,previous);}
                }else{
                    const next=row.nextElementSibling;
                    if(next){swapRows(next,row);}
                }
            }catch(error){
                alert(error instanceof Error?error.message:'Aktion fehlgeschlagen.');
            }
        }
    });
    root.addEventListener('input',function(event){
        const target=event.target;
        if(!(target instanceof Element)){return;}
        if(target.matches('input[name="slug"]')){
            const form=target.closest('form');
            if(form){updateUrlPreview(form);}
        }
    });
    root.addEventListener('change',function(event){
        const target=event.target;
        if(!(target instanceof Element)){return;}
        if(target.matches('[data-pages-show-in-header]')){
            const form=target.closest('form');
            if(form){syncMenuGroupState(form);}
        }
    });
    root.querySelectorAll('form').forEach(function(form){
        updateUrlPreview(form);
        syncMenuGroupState(form);
    });
})();
</script>
