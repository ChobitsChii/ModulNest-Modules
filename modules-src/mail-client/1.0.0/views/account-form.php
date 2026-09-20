<?php
declare(strict_types=1);
/**
 * Konto anlegen / bearbeiten Formular.
 *
 * @var array|null $account   Null = neu anlegen, Array = bestehend bearbeiten
 * @var array      $old       Alte POST-Daten (bei Fehler)
 * @var string     $error
 * @var string     $csrf_token
 */
$account   = is_array($account ?? null) ? $account : null;
$old       = is_array($old ?? null) ? $old : [];
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
<div class="row justify-content-center">
<div class="col-12 col-lg-8 col-xl-7">

<div class="d-flex align-items-center gap-2 mb-4">
    <a href="/mail-client" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i>
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
                        <?php foreach (['ssl' => 'SSL/TLS (993)', 'starttls' => 'STARTTLS (143)', 'tls' => 'TLS'] as $val => $label): ?>
                        <option value="<?= $val ?>" <?= $v('imap_encryption', 'ssl') === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label" for="mc-imap-user">IMAP-Benutzername</label>
                    <input type="text" id="mc-imap-user" name="imap_username"
                           class="form-control" maxlength="190" autocomplete="username"
                           placeholder="Leer lassen = E-Mail-Adresse verwenden"
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
                           value="<?= $v('smtp_port', '587') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="mc-smtp-enc">Verschlüsselung</label>
                    <select id="mc-smtp-enc" name="smtp_encryption" class="form-select">
                        <?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'starttls' => 'STARTTLS'] as $val => $label): ?>
                        <option value="<?= $val ?>" <?= $v('smtp_encryption', 'tls') === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label" for="mc-smtp-user">SMTP-Benutzername</label>
                    <input type="text" id="mc-smtp-user" name="smtp_username"
                           class="form-control" maxlength="190" autocomplete="username"
                           placeholder="Leer lassen = E-Mail-Adresse verwenden"
                           value="<?= $v('smtp_username') ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- Passwort -->
    <div class="card shadow-sm border mb-3">
        <div class="card-header"><strong>Passwort</strong></div>
        <div class="card-body">
            <div class="mb-0">
                <label class="form-label" for="mc-password">
                    Passwort / App-Passwort
                    <?= $isEdit ? '<span class="text-body-secondary">(leer lassen = unverändert)</span>' : '<span class="text-danger">*</span>' ?>
                </label>
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

