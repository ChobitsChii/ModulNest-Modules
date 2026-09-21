<?php
declare(strict_types=1);
/**
 * Compose-View – Mail verfassen mit Rich-Text-Editor.
 *
 * @var array  $accounts         Liste der Konten
 * @var string $error
 * @var string $success
 * @var string $reply_to
 * @var string $reply_subject
 * @var int    $reply_account_id
 * @var string $csrf_token
 */
$accounts        = is_array($accounts ?? null) ? $accounts : [];
$error           = (string) ($error ?? '');
$success         = (string) ($success ?? '');
$replyTo         = (string) ($reply_to ?? '');
$replySubject    = (string) ($reply_subject ?? '');
$replyAccountId  = (int) ($reply_account_id ?? 0);
$replyFromEmail  = (string) ($reply_from_email ?? "");
$replySenderVal  = (string) ($reply_sender_val ?? "");
$csrfToken       = (string) ($csrf_token ?? '');
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
<div class="col-12 col-xl-10">

<div class="d-flex align-items-center gap-2 mb-4">
    <a href="/mail-client" class="btn btn-sm btn-outline-secondary" title="Zurück zum Mail-Client">
        <i class="bi bi-arrow-left"></i> Zurück
    </a>
    <h1 class="h4 mb-0">Neue E-Mail</h1>
</div>

<?php if ($error !== ''): ?>
<div class="alert alert-danger">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
</div>
<?php endif; ?>

<?php if ($success !== ''): ?>
<div class="alert alert-success">
    <i class="bi bi-check-circle-fill me-2"></i>
    <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
</div>
<?php endif; ?>

<div class="card shadow-sm border">
<div class="card-body p-3 p-md-4">

<form method="post" action="/mail-client/compose" id="mc-compose-form">
    <?= \Modulon\Core\View::csrfField($csrfToken) ?>

    <!-- Absender-Konto & Identität / Alias -->
    <div class="mb-3">
        <label class="form-label" for="mc-c-account">Von <span class="text-danger">*</span></label>
        <select id="mc-c-account" name="sender_identity" class="form-select" required>
            <?php foreach ($accounts as $acc): ?>
            <?php
            $accId = (int) $acc['id'];
            $accAliases = is_array($acc['aliases'] ?? null) ? $acc['aliases'] : [];
            $valAcc = 'acc:' . $accId;
            $isAccSelected = ($replySenderVal !== '' && $replySenderVal === $valAcc)
                || ($replySenderVal === '' && $replyAccountId === $accId && empty($replyFromEmail));
            ?>
            <optgroup label="<?= htmlspecialchars($acc['display_name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($acc['email_address'], ENT_QUOTES, 'UTF-8') ?>)">
                <option value="<?= $valAcc ?>" <?= $isAccSelected ? 'selected' : '' ?>>
                    <?= htmlspecialchars($acc['display_name'], ENT_QUOTES, 'UTF-8') ?> &lt;<?= htmlspecialchars($acc['email_address'], ENT_QUOTES, 'UTF-8') ?>&gt;
                </option>
                <?php foreach ($accAliases as $alias): ?>
                <?php
                $valAlias = 'alias:' . (int) $alias['id'];
                $aliasDisplayName = (string) ($alias['display_name'] !== '' ? $alias['display_name'] : $acc['display_name']);
                $aliasEmail = (string) $alias['email_address'];
                $isAliasSelected = ($replySenderVal !== '' && $replySenderVal === $valAlias)
                    || (!empty($replyFromEmail) && strtolower($replyFromEmail) === strtolower($aliasEmail));
                ?>
                <option value="<?= $valAlias ?>" <?= $isAliasSelected ? 'selected' : '' ?>>
                    ↳ <?= htmlspecialchars($aliasDisplayName, ENT_QUOTES, 'UTF-8') ?> &lt;<?= htmlspecialchars($aliasEmail, ENT_QUOTES, 'UTF-8') ?>&gt;
                </option>
                <?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
        </select>
        <input type="hidden" name="account_id" id="mc-c-hidden-account-id" value="<?= $replyAccountId ?>">
    </div>

    <!-- An -->
    <div class="mb-3">
        <label class="form-label" for="mc-c-to">An <span class="text-danger">*</span></label>
        <input type="text" id="mc-c-to" name="to"
               class="form-control" required
               placeholder="Empfänger, z.B. name@example.com oder mehrere kommagetrennt"
               value="<?= htmlspecialchars($replyTo, ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <!-- CC (ausklappbar) -->
    <div class="mb-3">
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none"
                    onclick="document.getElementById('mc-cc-row').classList.toggle('d-none')">
                CC / BCC hinzufügen
            </button>
        </div>
        <div id="mc-cc-row" class="d-none mt-2">
            <div class="mb-2">
                <input type="text" name="cc" class="form-control"
                       placeholder="CC (kommagetrennt)">
            </div>
            <div>
                <input type="text" name="bcc" class="form-control"
                       placeholder="BCC (kommagetrennt)">
            </div>
        </div>
    </div>

    <!-- Betreff -->
    <div class="mb-3">
        <label class="form-label" for="mc-c-subject">Betreff <span class="text-danger">*</span></label>
        <input type="text" id="mc-c-subject" name="subject"
               class="form-control" required maxlength="998"
               value="<?= htmlspecialchars($replySubject, ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <!-- Rich-Text-Editor -->
    <div class="mb-3">
        <label class="form-label">Nachricht <span class="text-danger">*</span></label>
        <!-- Toolbar wird per JS befüllt -->
        <div class="mc-compose-editor-toolbar" id="mc-compose-toolbar"></div>
        <!-- contenteditable body -->
        <div class="mc-compose-body" id="mc-compose-body-editor" aria-label="E-Mail-Text"></div>
        <!-- Hidden input – wird vor Submit per JS befüllt -->
        <input type="hidden" name="body" id="mc-compose-body-hidden">
        <div class="form-text">
            <i class="bi bi-shield-check text-success"></i>
            Der Inhalt wird vor dem Versand automatisch auf Sicherheit geprüft.
            JavaScript und schädliche Inhalte werden entfernt.
        </div>
    </div>

    <div class="d-flex gap-2 flex-wrap">
        <button type="submit" class="btn btn-primary" id="mc-send-btn">
            <i class="bi bi-send"></i> Senden
        </button>
        <a href="/mail-client" class="btn btn-outline-secondary">Abbrechen</a>
    </div>
</form>

</div><!-- /.card-body -->
</div><!-- /.card -->

</div>
</div>
</div><!-- /.mc-subpage-container -->

<script><?php require __DIR__ . '/../assets/js/mail-client.js'; ?></script>
<script>
// Senden-Button: Spinner während des Ladens zeigen
document.getElementById('mc-compose-form')?.addEventListener('submit', function() {
    const btn = document.getElementById('mc-send-btn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Wird gesendet…';
    }
});
</script>

