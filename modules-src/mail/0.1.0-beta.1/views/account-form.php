<?php
declare(strict_types=1);

$account = is_array($account ?? null) ? $account : null;
$message = (string) ($mail_info ?? '');
$error = (string) ($mail_error ?? '');
$imapEncryptions = is_array($imap_encryptions ?? null) ? $imap_encryptions : ['tls', 'ssl', 'starttls'];
$smtpEncryptions = is_array($smtp_encryptions ?? null) ? $smtp_encryptions : ['tls', 'ssl', 'starttls'];
$isEdit = $account !== null;
$accountId = (int) ($account['id'] ?? 0);
$csrfToken = (string) ($csrf_token ?? '');
?>

<?php require __DIR__ . '/partials/nav.php'; ?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h1 class="h4 mb-0"><?= $isEdit ? 'Mailkonto bearbeiten' : 'Mailkonto hinzufügen' ?></h1>
    <a href="/mail/accounts" class="btn btn-outline-secondary btn-sm">Zurück zur Übersicht</a>
</div>

<?php if ($message !== ''): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="card shadow-sm border-0 app-card">
    <div class="card-body">
        <form method="post" action="<?= $isEdit ? '/mail/accounts/' . $accountId . '/update' : '/mail/accounts' ?>" class="row g-3">
            <?= \Modulon\Core\View::csrfField($csrfToken) ?>
            <div class="col-12 col-md-4">
                <label class="form-label mb-1" for="display_name">Anzeigename</label>
                <input id="display_name" class="form-control" type="text" name="display_name" maxlength="120" required
                       value="<?= htmlspecialchars((string) ($account['display_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label mb-1" for="email_address">E-Mail-Adresse</label>
                <input id="email_address" class="form-control" type="email" name="email_address" maxlength="190" required
                       value="<?= htmlspecialchars((string) ($account['email_address'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-12 col-md-4 d-flex align-items-end">
                <div class="form-check mb-2">
                    <input id="is_active" class="form-check-input" type="checkbox" name="is_active" value="1"
                        <?= (int) ($account['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                    <label class="form-check-label" for="is_active">Konto aktiv</label>
                </div>
            </div>

            <div class="col-12"><hr class="my-1"></div>
            <div class="col-12"><h2 class="h6 text-uppercase text-body-secondary mb-0">IMAP</h2></div>
            <div class="col-12 col-md-4">
                <label class="form-label mb-1" for="imap_host">IMAP-Host</label>
                <input id="imap_host" class="form-control" type="text" name="imap_host" maxlength="190" required
                       value="<?= htmlspecialchars((string) ($account['imap_host'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label mb-1" for="imap_port">Port</label>
                <input id="imap_port" class="form-control" type="number" min="1" max="65535" name="imap_port" required
                       value="<?= (int) ($account['imap_port'] ?? 993) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label mb-1" for="imap_encryption">Verschlüsselung</label>
                <select id="imap_encryption" class="form-select" name="imap_encryption" required>
                    <?php $selectedImap = (string) ($account['imap_encryption'] ?? 'tls'); ?>
                    <?php foreach ($imapEncryptions as $encryption): ?>
                        <option value="<?= htmlspecialchars((string) $encryption, ENT_QUOTES, 'UTF-8') ?>"<?= $selectedImap === $encryption ? ' selected' : '' ?>>
                            <?= htmlspecialchars((string) $encryption, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label mb-1" for="imap_username">IMAP-Benutzername</label>
                <input id="imap_username" class="form-control" type="text" name="imap_username" maxlength="190" required
                       value="<?= htmlspecialchars((string) ($account['imap_username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="col-12"><hr class="my-1"></div>
            <div class="col-12"><h2 class="h6 text-uppercase text-body-secondary mb-0">SMTP</h2></div>
            <div class="col-12 col-md-4">
                <label class="form-label mb-1" for="smtp_host">SMTP-Host</label>
                <input id="smtp_host" class="form-control" type="text" name="smtp_host" maxlength="190" required
                       value="<?= htmlspecialchars((string) ($account['smtp_host'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label mb-1" for="smtp_port">Port</label>
                <input id="smtp_port" class="form-control" type="number" min="1" max="65535" name="smtp_port" required
                       value="<?= (int) ($account['smtp_port'] ?? 587) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label mb-1" for="smtp_encryption">Verschlüsselung</label>
                <select id="smtp_encryption" class="form-select" name="smtp_encryption" required>
                    <?php $selectedSmtp = (string) ($account['smtp_encryption'] ?? 'tls'); ?>
                    <?php foreach ($smtpEncryptions as $encryption): ?>
                        <option value="<?= htmlspecialchars((string) $encryption, ENT_QUOTES, 'UTF-8') ?>"<?= $selectedSmtp === $encryption ? ' selected' : '' ?>>
                            <?= htmlspecialchars((string) $encryption, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label mb-1" for="smtp_username">SMTP-Benutzername</label>
                <input id="smtp_username" class="form-control" type="text" name="smtp_username" maxlength="190" required
                       value="<?= htmlspecialchars((string) ($account['smtp_username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="col-12"><hr class="my-1"></div>
            <div class="col-12 col-md-6">
                <label class="form-label mb-1" for="password"><?= $isEdit ? 'Neues Passwort (optional)' : 'Passwort' ?></label>
                <input id="password" class="form-control" type="password" name="password" autocomplete="new-password" <?= $isEdit ? '' : 'required' ?>>
                <div class="form-text">Zertifikatsprüfung für IMAP/SMTP ist fest aktiv und kann nicht deaktiviert werden.</div>
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-primary">Speichern</button>
                <a href="/mail/accounts" class="btn btn-outline-secondary ms-1">Abbrechen</a>
            </div>
        </form>
    </div>
</div>
