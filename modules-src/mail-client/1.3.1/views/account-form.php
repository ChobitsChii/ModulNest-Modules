<?php
declare(strict_types=1);
/**
 * Konto anlegen / bearbeiten Formular.
 *
 * @var array|null $account   Null = neu anlegen, Array = bestehend bearbeiten
 * @var array      $old       Alte POST-Daten (bei Fehler)
 * @var array      $aliases   Vorhandene Absender-Aliase
 * @var string     $error
 * @var string     $csrf_token
 */
$account   = is_array($account ?? null) ? $account : null;
$old       = is_array($old ?? null) ? $old : [];
$aliases   = is_array($aliases ?? null) ? $aliases : [];
$error     = (string) ($error ?? '');
$csrfToken = (string) ($csrf_token ?? '');
$isEdit    = $account !== null;

$actionUrl = $isEdit
    ? '/mail-client/accounts/' . (int) $account['id']
    : '/mail-client/accounts';

$v = static function (string $key, mixed $default = '') use ($account, $old): string {
    $val = $old[$key] ?? $account[$key] ?? $default;
    return htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8');
};
?>
<style><?php require __DIR__ . '/../assets/css/mail-client.css'; ?></style>
<script>
(function() {
    const isApp = window.matchMedia('(display-mode: standalone)').matches
        || window.matchMedia('(display-mode: fullscreen)').matches
        || window.matchMedia('(display-mode: minimal-ui)').matches
        || (window.navigator && window.navigator.standalone === true)
        || localStorage.getItem('mc_is_maximized') === '1'
        || (window.opener && window.name === 'ModulonMailClientPopout')
        || (new URLSearchParams(window.location.search).get('frameless') === '1');
    if (isApp) {
        document.body.classList.add('mc-app-mode', 'mc-standalone-subpage');
    }
})();
</script>

<div class="mc-subpage-container">
<div class="row justify-content-center">
<div class="col-12 col-lg-9 col-xl-8">

<div class="d-flex align-items-center gap-2 mb-4">
    <a href="/mail-client" class="btn btn-sm btn-outline-secondary" title="Zurück zum Mail-Client">
        <i class="bi bi-arrow-left"></i> Zurück
    </a>
    <h1 class="h4 mb-0">
        <?= $isEdit ? 'Mailkonto bearbeiten' : 'Mailkonto hinzufügen' ?>
    </h1>
</div>

<?php if ($error !== ''): ?>
<div class="alert alert-danger">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
</div>
<?php endif; ?>

<form method="post" action="<?= htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8') ?>">
    <?= \Modulon\Core\View::csrfField($csrfToken) ?>

    <!-- Konto-Infos -->
    <div class="card shadow-sm border mb-3">
        <div class="card-header"><strong>Allgemein</strong></div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label" for="mc-display-name">Anzeigename <span class="text-danger">*</span></label>
                <input type="text" id="mc-display-name" name="display_name"
                       class="form-control" required maxlength="120"
                       placeholder="z.B. Privat oder Arbeit"
                       value="<?= $v('display_name') ?>">
            </div>
            <div class="mb-0">
                <label class="form-label" for="mc-email">E-Mail-Adresse <span class="text-danger">*</span></label>
                <input type="email" id="mc-email" name="email_address"
                       class="form-control" required maxlength="190"
                       placeholder="user@example.com"
                       value="<?= $v('email_address') ?>">
            </div>
            <?php if (!$isEdit): ?>
            <div class="mt-3">
                <button type="button" id="mc-autoconfig-btn" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-magic"></i> Einstellungen automatisch erkennen
                </button>
                <span id="mc-autoconfig-status" class="ms-2 text-body-secondary small" style="display:none;"></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Absender-Aliase / Identitäten -->
    <div class="card shadow-sm border mb-3">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
                <strong>Weitere Absender-Aliase / Identitäten</strong>
                <div class="text-body-secondary small">Zusätzliche E-Mail-Adressen für dieses Postfach (z. B. alias@meinedomain.de), um mit diesen als Absender zu schreiben.</div>
            </div>
            <button type="button" class="btn btn-outline-primary btn-sm" id="mc-btn-add-alias">
                <i class="bi bi-plus-lg me-1"></i> Alias hinzufügen
            </button>
        </div>
        <div class="card-body">
            <div id="mc-alias-container" class="d-flex flex-column gap-2">
                <?php foreach ($aliases as $idx => $al): ?>
                <div class="row g-2 align-items-center mc-alias-row">
                    <div class="col-12 col-sm-5">
                        <input type="text" name="aliases[<?= $idx ?>][name]" class="form-control form-control-sm"
                               placeholder="Anzeigename (optional, z. B. Max Mustermann)"
                               value="<?= htmlspecialchars((string)($al['display_name'] ?? $al['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="col-10 col-sm-6">
                        <input type="email" name="aliases[<?= $idx ?>][email]" class="form-control form-control-sm"
                               placeholder="E-Mail-Adresse (z. B. alias@meinedomain.de)"
                               value="<?= htmlspecialchars((string)($al['email_address'] ?? $al['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="col-2 col-sm-1 text-end">
                        <button type="button" class="btn btn-outline-danger btn-sm mc-btn-remove-alias" title="Alias entfernen">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div id="mc-alias-empty" class="text-body-secondary small py-2 fst-italic" style="<?= empty($aliases) ? '' : 'display:none;' ?>">
                Keine zusätzlichen Absender-Aliase eingerichtet. Klicken Sie auf „Alias hinzufügen“, falls Sie weitere Adressen über dieses Konto nutzen möchten.
            </div>
        </div>
    </div>

    <!-- IMAP -->
    <div class="card shadow-sm border mb-3">
        <div class="card-header"><strong>IMAP-Einstellungen</strong> <span class="text-body-secondary">(Empfang)</span></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-7">
                    <label class="form-label" for="mc-imap-host">IMAP-Server <span class="text-danger">*</span></label>
                    <input type="text" id="mc-imap-host" name="imap_host"
                           class="form-control" required maxlength="190"
                           placeholder="imap.example.com"
                           value="<?= $v('imap_host') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="mc-imap-port">Port</label>
                    <input type="number" id="mc-imap-port" name="imap_port"
                           class="form-control" required min="1" max="65535"
                           value="<?= $v('imap_port', '993') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="mc-imap-enc">Verschlüsselung</label>
                    <select id="mc-imap-enc" name="imap_encryption" class="form-select">
                        <option value="ssl"  <?= $v('imap_encryption', 'ssl') === 'ssl'  ? 'selected' : '' ?>>SSL / TLS</option>
                        <option value="tls"  <?= $v('imap_encryption') === 'tls'          ? 'selected' : '' ?>>STARTTLS</option>
                        <option value="none" <?= $v('imap_encryption') === 'none'         ? 'selected' : '' ?>>Keine</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label" for="mc-imap-user">Benutzername <span class="text-danger">*</span></label>
                    <input type="text" id="mc-imap-user" name="imap_username"
                           class="form-control" required maxlength="190"
                           placeholder="Meist identisch mit E-Mail-Adresse"
                           value="<?= $v('imap_username') ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- SMTP -->
    <div class="card shadow-sm border mb-3">
        <div class="card-header"><strong>SMTP-Einstellungen</strong> <span class="text-body-secondary">(Versand)</span></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-7">
                    <label class="form-label" for="mc-smtp-host">SMTP-Server <span class="text-danger">*</span></label>
                    <input type="text" id="mc-smtp-host" name="smtp_host"
                           class="form-control" required maxlength="190"
                           placeholder="smtp.example.com"
                           value="<?= $v('smtp_host') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="mc-smtp-port">Port</label>
                    <input type="number" id="mc-smtp-port" name="smtp_port"
                           class="form-control" required min="1" max="65535"
                           value="<?= $v('smtp_port', '465') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="mc-smtp-enc">Verschlüsselung</label>
                    <select id="mc-smtp-enc" name="smtp_encryption" class="form-select">
                        <option value="ssl"  <?= $v('smtp_encryption', 'ssl') === 'ssl'  ? 'selected' : '' ?>>SSL / TLS</option>
                        <option value="tls"  <?= $v('smtp_encryption') === 'tls'          ? 'selected' : '' ?>>STARTTLS</option>
                        <option value="none" <?= $v('smtp_encryption') === 'none'         ? 'selected' : '' ?>>Keine</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label" for="mc-smtp-user">Benutzername <span class="text-danger">*</span></label>
                    <input type="text" id="mc-smtp-user" name="smtp_username"
                           class="form-control" required maxlength="190"
                           placeholder="Meist identisch mit E-Mail-Adresse"
                           value="<?= $v('smtp_username') ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- Passwort -->
    <div class="card shadow-sm border mb-4">
        <div class="card-header">
            <strong>Passwort</strong>
            <?php if ($isEdit): ?>
            <span class="text-body-secondary">(Nur ausfüllen wenn ändern)</span>
            <?php else: ?>
            <span class="text-danger">*</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <div class="mb-0">
                <input type="password" id="mc-password" name="password"
                       class="form-control" autocomplete="new-password"
                       <?= !$isEdit ? 'required' : '' ?>
                       placeholder="<?= $isEdit ? 'Leer lassen um Passwort nicht zu ändern' : 'Passwort oder App-Passwort' ?>">
                <div class="form-text">
                    Das Passwort wird verschlüsselt gespeichert (AES-256-GCM).
                    Viele Anbieter (Google, Microsoft) erfordern App-Passwörter wenn 2FA aktiv ist.
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 flex-wrap">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check2"></i> <?= $isEdit ? 'Änderungen speichern' : 'Konto anlegen' ?>
        </button>
        <a href="/mail-client" class="btn btn-outline-secondary">Abbrechen</a>
        <?php if ($isEdit): ?>
        <button type="submit"
                formaction="/mail-client/accounts/<?= (int) $account['id'] ?>/delete"
                class="btn btn-outline-danger ms-auto"
                onclick="return confirm('Dieses Konto und alle zugehörigen Daten wirklich löschen?')">
            <i class="bi bi-trash"></i> Konto löschen
        </button>
        <?php endif; ?>
    </div>
</form>

</div>
</div>
</div>

<script>
(function() {
    // 1. Einstellungen automatisch erkennen (nur bei Neuanlage)
    const autoconfigBtn    = document.getElementById('mc-autoconfig-btn');
    const autoconfigStatus = document.getElementById('mc-autoconfig-status');

    if (autoconfigBtn) {
        autoconfigBtn.addEventListener('click', async function(e) {
            e.preventDefault();
            const email = document.getElementById('mc-email')?.value?.trim();
            if (!email || !email.includes('@')) {
                alert('Bitte zuerst eine gültige E-Mail-Adresse eingeben.');
                return;
            }

            autoconfigBtn.disabled = true;
            autoconfigBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Suche läuft…';
            if (autoconfigStatus) autoconfigStatus.style.display = 'none';

            try {
                const resp = await fetch('/mail-client/api/autoconfig?' + new URLSearchParams({ email }), {
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin',
                });
                const data = await resp.json();

                if (!data.found) {
                    if (autoconfigStatus) {
                        autoconfigStatus.textContent = '❌ Keine Einstellungen gefunden – bitte manuell ausfüllen.';
                        autoconfigStatus.className = 'ms-2 text-danger small';
                        autoconfigStatus.style.display = 'inline';
                    }
                    return;
                }

                // Felder befüllen
                const set = (id, val) => { const el = document.getElementById(id); if (el && val) el.value = val; };
                const setSelect = (id, val) => {
                    const el = document.getElementById(id);
                    if (!el || !val) return;
                    for (const opt of el.options) { if (opt.value === val) { opt.selected = true; break; } }
                };

                set('mc-imap-host', data.imap_host);
                set('mc-imap-port', data.imap_port);
                setSelect('mc-imap-enc', data.imap_encryption);
                set('mc-smtp-host', data.smtp_host);
                set('mc-smtp-port', data.smtp_port);
                setSelect('mc-smtp-enc', data.smtp_encryption);

                // Benutzernamen-Format anwenden (falls nicht %EMAILADDRESS%)
                const fmt = data.imap_username_format || '';
                if (fmt && fmt !== '%EMAILADDRESS%') {
                    const local = email.split('@')[0];
                    const resolved = fmt.replace('%EMAILADDRESS%', email).replace('%EMAILLOCALPART%', local);
                    set('mc-imap-user', resolved);
                    set('mc-smtp-user', resolved);
                }

                if (autoconfigStatus) {
                    autoconfigStatus.textContent = '✓ Gefunden via ' + (data.source || 'Autoconfig') + ' – bitte Passwort eingeben.';
                    autoconfigStatus.className = 'ms-2 text-success small';
                    autoconfigStatus.style.display = 'inline';
                }

            } catch (e) {
                if (autoconfigStatus) {
                    autoconfigStatus.textContent = '❌ Fehler bei der Erkennung.';
                    autoconfigStatus.className = 'ms-2 text-danger small';
                    autoconfigStatus.style.display = 'inline';
                }
            } finally {
                autoconfigBtn.disabled = false;
                autoconfigBtn.innerHTML = '<i class="bi bi-magic"></i> Einstellungen automatisch erkennen';
            }
        });
    }

    // 2. Absender-Aliase dynamisch hinzufügen / entfernen
    const aliasContainer = document.getElementById('mc-alias-container');
    const aliasEmpty     = document.getElementById('mc-alias-empty');
    const addAliasBtn    = document.getElementById('mc-btn-add-alias');

    function checkEmptyAliases() {
        if (!aliasContainer || !aliasEmpty) return;
        const rows = aliasContainer.querySelectorAll('.mc-alias-row');
        aliasEmpty.style.display = rows.length === 0 ? 'block' : 'none';
    }

    if (addAliasBtn && aliasContainer) {
        addAliasBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const idx = Date.now();
            const row = document.createElement('div');
            row.className = 'row g-2 align-items-center mc-alias-row';
            row.innerHTML = `
                <div class="col-12 col-sm-5">
                    <input type="text" name="aliases[${idx}][name]" class="form-control form-control-sm"
                           placeholder="Anzeigename (optional, z. B. Max Mustermann)">
                </div>
                <div class="col-10 col-sm-6">
                    <input type="email" name="aliases[${idx}][email]" class="form-control form-control-sm"
                           placeholder="E-Mail-Adresse (z. B. alias@meinedomain.de)" required>
                </div>
                <div class="col-2 col-sm-1 text-end">
                    <button type="button" class="btn btn-outline-danger btn-sm mc-btn-remove-alias" title="Alias entfernen">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            `;
            aliasContainer.appendChild(row);
            checkEmptyAliases();
            row.querySelector('input[type="email"]')?.focus();
        });

        aliasContainer.addEventListener('click', function(e) {
            const btn = e.target.closest('.mc-btn-remove-alias');
            if (btn) {
                e.preventDefault();
                e.stopPropagation();
                const row = btn.closest('.mc-alias-row');
                if (row) {
                    row.remove();
                    checkEmptyAliases();
                }
            }
        });

        checkEmptyAliases();
    }
})();
</script>
