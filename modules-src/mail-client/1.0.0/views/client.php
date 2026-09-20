<?php
declare(strict_types=1);
/**
 * Haupt-3-Pane-View des Mail-Clients.
 *
 * @var array  $accounts    Liste der Mail-Konten des Benutzers
 * @var string $csrf_token
 * @var string $current_path
 */
$accounts  = is_array($accounts ?? null) ? $accounts : [];
$csrfToken = (string) ($csrf_token ?? '');
$success   = (string) ($mail_success ?? '');
?>
<style><?php require __DIR__ . '/../assets/css/mail-client.css'; ?></style>

<div class="mc-app">

    <!-- ── Toolbar ─────────────────────────────────────────────────────── -->
    <div class="mc-toolbar">
        <!-- Mobile: Sidebar öffnen -->
        <button class="btn btn-sm btn-outline-secondary d-sm-none" onclick="mailClientApp.openSidebar()" aria-label="Ordner">
            <i class="bi bi-list"></i>
        </button>

        <span class="mc-toolbar-brand d-none d-sm-inline-flex">
            <i class="bi bi-envelope-at"></i> Mail-Client
        </span>

        <div class="mc-toolbar-actions">
            <a href="/mail-client/compose" class="btn btn-sm btn-primary">
                <i class="bi bi-pencil-square"></i> <span class="d-none d-md-inline">Neue Mail</span>
            </a>
            <a href="/mail-client/accounts/create" class="btn btn-sm btn-outline-secondary" title="Konto hinzufügen">
                <i class="bi bi-person-plus"></i>
            </a>
        </div>
    </div>

    <?php if ($success !== ''): ?>
    <div class="alert alert-success mb-0 rounded-0 border-x-0 py-1 px-3" style="font-size:0.84rem;">
        <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <?php if ($accounts === []): ?>
    <!-- Kein Konto angelegt -->
    <div class="flex-grow-1 d-flex flex-column align-items-center justify-content-center text-center p-4 gap-3">
        <i class="bi bi-envelope-x" style="font-size:3.5rem;opacity:0.4;"></i>
        <div>
            <h2 class="h5">Noch kein Mailkonto hinterlegt</h2>
            <p class="text-body-secondary mb-3">Füge dein erstes IMAP-Konto hinzu, um loszulegen.</p>
            <a href="/mail-client/accounts/create" class="btn btn-primary">
                <i class="bi bi-person-plus"></i> Konto hinzufügen
            </a>
        </div>
    </div>
    <?php else: ?>

    <!-- ── Drei Panes ──────────────────────────────────────────────────── -->
    <div class="mc-panes">

        <!-- ─ Linkes Pane: Ordnerbaum ─ -->
        <div class="mc-sidebar" id="mc-sidebar">

            <?php foreach ($accounts as $acc): ?>
            <?php
            $accId    = (int) $acc['id'];
            $accName  = (string) $acc['display_name'];
            $accEmail = (string) $acc['email_address'];
            ?>
            <div class="mc-account-group">
                <div class="mc-account-header" data-account="<?= $accId ?>">
                    <i class="bi bi-chevron-down mc-account-toggle"></i>
                    <span class="flex-grow-1 overflow-hidden">
                        <span class="d-block text-truncate"><?= htmlspecialchars($accName, ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="mc-account-email"><?= htmlspecialchars($accEmail, ENT_QUOTES, 'UTF-8') ?></span>
                    </span>
                    <a href="/mail-client/accounts/<?= $accId ?>/edit"
                       class="btn btn-link btn-sm p-0 text-secondary"
                       title="Konto bearbeiten"
                       onclick="event.stopPropagation()">
                        <i class="bi bi-gear"></i>
                    </a>
                </div>
                <ul class="mc-folder-list" data-account="<?= $accId ?>">
                    <!-- Ordner werden per AJAX geladen nach erstem Klick.
                         Initial zeigen wir Platzhalter-Ordner für bekannte Typen -->
                    <li class="mc-folder-item"
                        data-account="<?= $accId ?>"
                        data-folder="INBOX"
                        title="Posteingang">
                        <i class="bi bi-inbox mc-folder-icon"></i>
                        <span class="mc-folder-name">Posteingang</span>
                    </li>
                    <li class="mc-folder-item"
                        data-account="<?= $accId ?>"
                        data-folder-load="true"
                        data-folder=""
                        onclick="mailClientApp.loadFolders(<?= $accId ?>, this)"
                        title="Alle Ordner laden">
                        <i class="bi bi-three-dots mc-folder-icon"></i>
                        <span class="mc-folder-name text-secondary" style="font-size:0.78rem;">Alle Ordner laden…</span>
                    </li>
                </ul>
            </div>
            <?php endforeach; ?>

        </div>
        <!-- Mobile: Sidebar-Backdrop -->
        <div class="d-sm-none" id="mc-sidebar-backdrop"
             style="display:none!important;position:fixed;inset:0;z-index:1059;background:rgba(0,0,0,0.4)"
             onclick="mailClientApp.closeSidebar()"></div>

        <div class="mc-sidebar-resizer" id="mc-sidebar-resizer"></div>

        <!-- ─ Mittleres Pane: Nachrichten-Liste ─ -->
        <div class="mc-list-pane">
            <div class="mc-list-header">
                <span class="mc-list-header-title text-secondary">Ordner wählen</span>
                <span class="mc-list-unseen-count d-none"></span>
            </div>
            <div class="mc-message-list" id="mc-message-list">
                <div class="mc-preview-empty py-5">
                    <i class="bi bi-folder2-open mc-preview-empty-icon"></i>
                    <div class="text-secondary small">Ordner in der Sidebar auswählen</div>
                </div>
            </div>
            <div class="mc-load-more">
                <button id="mc-load-more" class="btn btn-sm btn-outline-secondary d-none">
                    Ältere Nachrichten laden
                </button>
            </div>
        </div>

        <div class="mc-list-resizer" id="mc-list-resizer"></div>

        <!-- ─ Rechtes Pane: Vorschau ─ -->
        <div class="mc-preview-pane" id="mc-preview-pane">
            <!-- Mobile: Schließen-Button -->
            <div class="d-lg-none d-flex justify-content-end p-1 border-bottom">
                <button class="btn btn-sm btn-outline-secondary" onclick="mailClientApp.closePreview()">
                    <i class="bi bi-x-lg"></i> Schließen
                </button>
            </div>

            <div class="mc-preview-header d-none"></div>
            <div class="mc-preview-actions d-none"></div>
            <div class="mc-preview-body">
                <div class="mc-preview-empty">
                    <i class="bi bi-envelope mc-preview-empty-icon"></i>
                    <div>Nachricht auswählen um sie hier anzuzeigen</div>
                    <div class="text-secondary small">Doppelklick öffnet im Vollbild (neuer Tab)</div>
                </div>
            </div>
        </div>

    </div><!-- /.mc-panes -->
    <?php endif; ?>

</div><!-- /.mc-app -->

<!-- CSRF-Token für AJAX -->
<span data-csrf="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>" style="display:none;"></span>
<input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">


<script><?php require __DIR__ . '/../assets/js/mail-client.js'; ?></script>


<script>
// Ordner per AJAX laden und in der Sidebar rendern
mailClientApp.loadFolders = async function(accountId, triggerEl) {
    const list = triggerEl?.closest('ul');
    if (!list) return;
    triggerEl.innerHTML = '<li class="mc-folder-item text-secondary" style="font-size:0.78rem;"><span class="spinner-border spinner-border-sm me-1"></span>Laden…</li>';
    try {
        const data = await fetch(`/mail-client/api/folders?account=${accountId}`, {
            headers: { 'Accept': 'application/json', 'X-CSRF-Token': document.querySelector('[data-csrf]')?.dataset.csrf || '' },
            credentials: 'same-origin',
        }).then(r => r.json());

        const icons = {inbox:'bi-inbox',sent:'bi-send',drafts:'bi-file-earmark-text',trash:'bi-trash',spam:'bi-shield-exclamation',archive:'bi-archive',folder:'bi-folder'};
        list.innerHTML = (data.folders || []).map(f => `
            <li class="mc-folder-item"
                data-account="${accountId}"
                data-folder="${f.name.replace(/"/g,'&quot;')}"
                title="${f.name.replace(/"/g,'&quot;')}">
                <i class="bi ${icons[f.special]||icons.folder} mc-folder-icon"></i>
                <span class="mc-folder-name">${f.name.replace(/</g,'&lt;')}</span>
                ${f.unseen > 0 ? `<span class="mc-folder-unseen">${f.unseen}</span>` : ''}
            </li>
        `).join('');

        // Click-Binding neu aufsetzen
        list.querySelectorAll('.mc-folder-item[data-folder]').forEach(el => {
            el.addEventListener('click', async (e) => {
                e.preventDefault();
                const folder = el.dataset.folder;
                if (!folder) return;
                document.querySelectorAll('.mc-folder-item').forEach(f => f.classList.remove('active'));
                el.classList.add('active');
                window.mailClientState = window.mailClientState || {};
                // Signal an SPA-Controller
                el.dispatchEvent(new CustomEvent('mc-folder-select', { bubbles: true, detail: { accountId, folder } }));
            });
        });
    } catch (err) {
        list.innerHTML = `<li class="mc-folder-item text-danger small">${err.message}</li>`;
    }
};
</script>

