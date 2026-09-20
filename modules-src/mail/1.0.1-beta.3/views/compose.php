<?php
declare(strict_types=1);

$accounts = is_array($accounts ?? null) ? $accounts : [];
$selectedAccountId = (int) ($selected_account_id ?? 0);
$replyFolder = (string) ($reply_folder ?? '');
$replyUid = (int) ($reply_uid ?? 0);
$replyDetail = is_array($reply_detail ?? null) ? $reply_detail : null;
$defaults = is_array($compose_defaults ?? null) ? $compose_defaults : [];
$mailInfo = (string) ($mail_info ?? '');
$mailError = (string) ($mail_error ?? '');
$csrfToken = (string) ($csrf_token ?? '');
?>

<?php require __DIR__ . '/partials/nav.php'; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><?= $replyDetail !== null ? 'Antwort verfassen' : 'Neue E-Mail verfassen' ?></h1>
    <a href="/mail/accounts" class="btn btn-sm btn-outline-secondary">Zurück zur Übersicht</a>
</div>

<?php if ($mailInfo !== ''): ?>
    <div class="alert alert-success"><?= htmlspecialchars($mailInfo, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if ($mailError !== ''): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($mailError, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if ($replyDetail !== null): ?>
    <div class="card shadow-sm border-0 app-card mb-3">
        <div class="card-body py-2">
            <div class="small text-body-secondary">
                Antwort auf: <strong><?= htmlspecialchars((string) ($replyDetail['subject'] ?? '(ohne Betreff)'), ENT_QUOTES, 'UTF-8') ?></strong>
                · <?= htmlspecialchars((string) ($replyDetail['from_label'] ?? 'Unbekannt'), ENT_QUOTES, 'UTF-8') ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="card shadow-sm border-0 app-card">
    <div class="card-body">
        <form method="post" action="/mail/messages/send" class="row g-3">
            <?= \Modulon\Core\View::csrfField($csrfToken) ?>
            <div class="col-12 col-md-4">
                <label class="form-label mb-1" for="account_id">Von-Konto</label>
                <select id="account_id" name="account_id" class="form-select" required>
                    <option value="">Bitte wählen</option>
                    <?php foreach ($accounts as $account): ?>
                        <?php
                        $id = (int) ($account['id'] ?? 0);
                        $label = (string) ($account['display_name'] ?? '');
                        $email = (string) ($account['email_address'] ?? '');
                        ?>
                        <option value="<?= $id ?>" <?= $selectedAccountId === $id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label . ' <' . $email . '>', ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12 col-md-8">
                <label class="form-label mb-1" for="to">An</label>
                <input id="to" name="to" type="text" class="form-control" required
                       value="<?= htmlspecialchars((string) ($defaults['to'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="empfaenger@example.com">
            </div>

            <div class="col-12 col-md-6">
                <label class="form-label mb-1" for="cc">CC (optional)</label>
                <input id="cc" name="cc" type="text" class="form-control"
                       value="<?= htmlspecialchars((string) ($defaults['cc'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="adresse1@example.com, adresse2@example.com">
            </div>

            <div class="col-12 col-md-6">
                <label class="form-label mb-1" for="bcc">BCC (optional)</label>
                <input id="bcc" name="bcc" type="text" class="form-control"
                       value="<?= htmlspecialchars((string) ($defaults['bcc'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="adresse@example.com">
            </div>

            <div class="col-12">
                <label class="form-label mb-1" for="subject">Betreff</label>
                <input id="subject" name="subject" type="text" class="form-control" maxlength="255" required
                       value="<?= htmlspecialchars((string) ($defaults['subject'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="col-12">
                <label class="form-label mb-1" for="body_plain">Nachricht (Text)</label>
                <textarea id="body_plain" name="body_plain" class="form-control" rows="10" required><?= htmlspecialchars((string) ($defaults['body_plain'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>

            <div class="col-12">
                <label class="form-label mb-1" for="body_html">Nachricht (HTML optional)</label>
                <textarea id="body_html" name="body_html" class="form-control" rows="8" placeholder="Optionaler HTML-Inhalt"><?= htmlspecialchars((string) ($defaults['body_html'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                <div class="form-text">Falls leer, wird nur Text versendet.</div>
            </div>

            <input type="hidden" name="in_reply_to" value="<?= htmlspecialchars((string) ($defaults['in_reply_to'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="references" value="<?= htmlspecialchars((string) ($defaults['references'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="reply_folder" value="<?= htmlspecialchars($replyFolder, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="reply_uid" value="<?= $replyUid ?>">

            <div class="col-12">
                <button type="submit" class="btn btn-primary">E-Mail senden</button>
            </div>
        </form>
    </div>
</div>
