<?php
declare(strict_types=1);

$detail = is_array($message_detail ?? null) ? $message_detail : null;
$contentMode = (string) ($message_content_mode ?? 'text');
$contentHtml = (string) ($message_content_html ?? '');
$mailInfo = (string) ($mail_info ?? '');
$mailError = (string) ($mail_error ?? '');
$blockedImageCount = (int) ($message_blocked_image_count ?? 0);
$imagesAllowed = (bool) ($message_images_allowed ?? false);
$imagesTemporarilyAllowed = (bool) ($message_images_temporarily_allowed ?? false);
$senderWhitelisted = (bool) ($message_sender_whitelisted ?? false);
$domainWhitelisted = (bool) ($message_domain_whitelisted ?? false);
$csrfToken = (string) ($csrf_token ?? '');

$accountId = (int) ($selected_account_id ?? 0);
$folder = (string) ($selected_folder ?? '');
$uid = (int) ($selected_uid ?? 0);
$sort = (string) ($selected_sort ?? 'date');
$direction = (string) ($selected_direction ?? 'desc');
$group = (string) ($selected_group ?? 'none');
$viewMode = (string) ($selected_view_mode ?? 'messages');
$senderKey = strtolower((string) ($selected_sender_key ?? ''));
$untilUid = isset($selected_until_uid) ? (int) $selected_until_uid : 0;

$backUrl = '/mail/accounts?' . http_build_query([
    'account' => $accountId,
    'folder' => $folder,
    'sort' => $sort,
    'direction' => $direction,
    'group' => $group,
    'view' => $viewMode,
    'sender' => $senderKey !== '' ? $senderKey : null,
    'until_uid' => $untilUid > 0 ? $untilUid : null,
]) . '#mail-message-list';

$temporaryLoadUrl = '/mail/message?' . http_build_query([
    'account' => $accountId,
    'folder' => $folder,
    'uid' => $uid,
    'sort' => $sort,
    'direction' => $direction,
    'group' => $group,
    'view' => $viewMode,
    'sender' => $senderKey !== '' ? $senderKey : null,
    'until_uid' => $untilUid > 0 ? $untilUid : null,
    'load_images' => 1,
]);

$fullViewUrl = '/mail/message?' . http_build_query([
    'account' => $accountId,
    'folder' => $folder,
    'uid' => $uid,
    'sort' => $sort,
    'direction' => $direction,
    'group' => $group,
    'view' => $viewMode,
    'sender' => $senderKey !== '' ? $senderKey : null,
    'until_uid' => $untilUid > 0 ? $untilUid : null,
]);

$replyUrl = '/mail/compose?' . http_build_query([
    'account' => $accountId,
    'folder' => $folder,
    'reply_uid' => $uid,
    'view' => $viewMode,
    'sender' => $senderKey !== '' ? $senderKey : null,
    'until_uid' => $untilUid > 0 ? $untilUid : null,
]);
?>

<div class="modulon-mail-detail-content">
    <?php if ($mailInfo !== ''): ?>
        <div class="alert alert-success"><?= htmlspecialchars($mailInfo, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($mailError !== ''): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($mailError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($detail === null): ?>
        <div class="card shadow-sm border-0 app-card">
            <div class="card-body text-body-secondary">Nachricht konnte nicht geladen werden.</div>
        </div>
    <?php else: ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h2 class="h5 mb-0 text-truncate"><?= htmlspecialchars((string) ($detail['subject'] ?? '(ohne Betreff)'), ENT_QUOTES, 'UTF-8') ?></h2>
            <div class="d-flex gap-2">
                <a href="<?= htmlspecialchars($replyUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-primary">Antworten</a>
                <a href="<?= htmlspecialchars($fullViewUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-secondary">Vollansicht</a>
                <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-secondary">Zur Liste</a>
            </div>
        </div>

        <div class="card shadow-sm border-0 app-card mb-3">
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-2 text-body-secondary">Von</dt>
                    <dd class="col-sm-10"><?= htmlspecialchars((string) ($detail['from_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></dd>

                    <dt class="col-sm-2 text-body-secondary">An</dt>
                    <dd class="col-sm-10"><?= htmlspecialchars((string) ($detail['to_raw'] ?? ''), ENT_QUOTES, 'UTF-8') ?></dd>

                    <dt class="col-sm-2 text-body-secondary">Datum</dt>
                    <dd class="col-sm-10"><?= htmlspecialchars((string) ($detail['date_label'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></dd>
                </dl>
            </div>
        </div>

        <?php if (!$imagesAllowed): ?>
            <div class="card shadow-sm border-0 app-card mb-3">
                <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <div class="fw-semibold">Externe Bilder sind blockiert.</div>
                        <div class="small text-body-secondary">
                            <?= $blockedImageCount > 0 ? $blockedImageCount . ' externe Bilder wurden nicht geladen.' : 'Sicherheitsstandard aktiv.' ?>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="<?= htmlspecialchars($temporaryLoadUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-primary js-mail-detail-link">
                            Externe Bilder für diese Nachricht laden
                        </a>
                        <?php if (!$senderWhitelisted && (string) ($detail['sender_email'] ?? '') !== ''): ?>
                            <form method="post" action="/mail/messages/whitelist" class="d-inline js-mail-detail-whitelist">
                                <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                <input type="hidden" name="account_id" value="<?= $accountId ?>">
                                <input type="hidden" name="folder" value="<?= htmlspecialchars($folder, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="uid" value="<?= $uid ?>">
                                <input type="hidden" name="sort" value="<?= htmlspecialchars($sort, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="direction" value="<?= htmlspecialchars($direction, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="group" value="<?= htmlspecialchars($group, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="view" value="<?= htmlspecialchars($viewMode, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="sender" value="<?= htmlspecialchars($senderKey, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="until_uid" value="<?= $untilUid > 0 ? $untilUid : 0 ?>">
                                <input type="hidden" name="scope_type" value="sender">
                                <input type="hidden" name="scope_value" value="<?= htmlspecialchars((string) ($detail['sender_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="btn btn-sm btn-outline-success">
                                    Bilder von diesem Absender immer laden
                                </button>
                            </form>
                        <?php endif; ?>
                        <?php if (!$domainWhitelisted && (string) ($detail['sender_domain'] ?? '') !== ''): ?>
                            <form method="post" action="/mail/messages/whitelist" class="d-inline js-mail-detail-whitelist">
                                <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                <input type="hidden" name="account_id" value="<?= $accountId ?>">
                                <input type="hidden" name="folder" value="<?= htmlspecialchars($folder, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="uid" value="<?= $uid ?>">
                                <input type="hidden" name="sort" value="<?= htmlspecialchars($sort, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="direction" value="<?= htmlspecialchars($direction, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="group" value="<?= htmlspecialchars($group, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="view" value="<?= htmlspecialchars($viewMode, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="sender" value="<?= htmlspecialchars($senderKey, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="until_uid" value="<?= $untilUid > 0 ? $untilUid : 0 ?>">
                                <input type="hidden" name="scope_type" value="domain">
                                <input type="hidden" name="scope_value" value="<?= htmlspecialchars((string) ($detail['sender_domain'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="btn btn-sm btn-outline-success">
                                    Bilder für diese Domain immer laden
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                <?= $imagesTemporarilyAllowed ? 'Externe Bilder für diese Nachricht temporär aktiviert.' : 'Externe Bilder sind für diese Nachricht über Whitelist erlaubt.' ?>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm border-0 app-card">
            <?php if ($contentMode === 'html'): ?>
                <div class="card-body p-0 modulon-mail-iframe-shell">
                    <iframe
                        class="modulon-mail-html-frame js-mail-html-frame"
                        sandbox="allow-popups allow-same-origin"
                        referrerpolicy="no-referrer"
                        loading="lazy"
                        srcdoc="<?= htmlspecialchars($contentHtml, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        title="HTML-Mailinhalt"
                    ></iframe>
                </div>
            <?php else: ?>
                <div class="card-body">
                    <?= $contentHtml ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
