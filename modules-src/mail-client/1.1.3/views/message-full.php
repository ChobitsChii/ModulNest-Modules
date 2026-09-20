<?php
declare(strict_types=1);
/**
 * Vollbild-Mail-Ansicht (wird in neuem Tab/Fenster geöffnet).
 *
 * @var array|null $message    Die kompletten Mail-Daten
 * @var array      $account    Das Mail-Konto
 * @var string     $error
 * @var bool       $allow_external
 * @var int        $blocked_images
 * @var string     $csrf_token
 */
$message        = is_array($message ?? null) ? $message : null;
$account        = is_array($account ?? null) ? $account : [];
$error          = (string) ($error ?? '');
$allowExternal  = (bool) ($allow_external ?? false);
$blockedImages  = (int) ($blocked_images ?? 0);
$csrfToken      = (string) ($csrf_token ?? '');
?>
<style>
<?php require __DIR__ . '/../assets/css/mail-client.css'; ?>

/* Standalone Vollbild-Ansicht: Header & Footer von Modulon ausblenden */
nav.navbar, footer, .app-footer, #admin-update-banner-container, .admin-update-banner-shell {
    display: none !important;
}
body {
    background: var(--bs-body-bg) !important;
    padding: 0 !important;
    margin: 0 !important;
}
main, .app-container, .container, .container-fluid {
    padding: 1.5rem !important;
    margin: 0 auto !important;
    max-width: 960px !important;
    box-shadow: none !important;
}

@media print {
    nav.navbar, footer, .app-footer, .mc-fullview-actions, button, .btn, .alert, .mc-attachment-chip i {
        display: none !important;
    }
    main, .app-container, .container, .container-fluid, .mc-fullview {
        padding: 0 !important;
        margin: 0 !important;
        max-width: 100% !important;
    }
    .mc-fullview-header {
        border-bottom: 2px solid #333 !important;
        padding-bottom: 1rem !important;
        margin-bottom: 1.5rem !important;
    }
}
</style>

<div class="mc-fullview">

    <?php if ($error !== ''): ?>
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <?php if ($message !== null): ?>

    <!-- ── Header ─────────────────────────────────────────────────────── -->
    <div class="mc-fullview-header">
        <h1 class="mc-fullview-subject">
            <?= htmlspecialchars($message['subject'] ?: '(kein Betreff)', ENT_QUOTES, 'UTF-8') ?>
        </h1>

        <dl class="row g-1 mb-3" style="font-size:0.85rem;">
            <dt class="col-sm-1 text-body-secondary">Von</dt>
            <dd class="col-sm-11">
                <?php
                $fromStr = $message['from_name'] !== ''
                    ? htmlspecialchars($message['from_name'], ENT_QUOTES, 'UTF-8') . ' &lt;' . htmlspecialchars($message['from'], ENT_QUOTES, 'UTF-8') . '&gt;'
                    : htmlspecialchars($message['from'], ENT_QUOTES, 'UTF-8');
                echo $fromStr;
                ?>
            </dd>
            <dt class="col-sm-1 text-body-secondary">An</dt>
            <dd class="col-sm-11"><?= htmlspecialchars($message['to'], ENT_QUOTES, 'UTF-8') ?></dd>
            <?php if (!empty($message['cc'])): ?>
            <dt class="col-sm-1 text-body-secondary">CC</dt>
            <dd class="col-sm-11"><?= htmlspecialchars($message['cc'], ENT_QUOTES, 'UTF-8') ?></dd>
            <?php endif; ?>
            <dt class="col-sm-1 text-body-secondary">Datum</dt>
            <dd class="col-sm-11"><?= htmlspecialchars($message['date'], ENT_QUOTES, 'UTF-8') ?></dd>
        </dl>

        <!-- Aktions-Buttons -->
        <div class="d-flex flex-wrap gap-2 mb-2">
            <a href="/mail-client/compose?reply_to=<?= rawurlencode($message['from']) ?>&subject=<?= rawurlencode('Re: ' . $message['subject']) ?>&account=<?= (int) $account['id'] ?>"
               class="btn btn-sm btn-primary">
                <i class="bi bi-reply"></i> Antworten
            </a>
            <a href="/mail-client/compose?reply_to=<?= rawurlencode($message['to']) ?>&subject=<?= rawurlencode('Fwd: ' . $message['subject']) ?>&account=<?= (int) $account['id'] ?>"
               class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-forward"></i> Weiterleiten
            </a>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                <i class="bi bi-printer"></i> Drucken
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.close()">
                <i class="bi bi-x-lg"></i> Schließen
            </button>
        </div>

        <!-- Blockierte Bilder -->
        <?php if ($blockedImages > 0 && !$allowExternal): ?>
        <div class="alert alert-warning py-2 px-3 mb-0 d-flex align-items-center gap-2" style="font-size:0.83rem;">
            <i class="bi bi-shield-exclamation"></i>
            <span><?= $blockedImages ?> externe Bild(er) wurden blockiert.</span>
            <form method="post" action="/mail-client/api/whitelist" class="ms-auto mb-0 d-inline">
                <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                <input type="hidden" name="scope_type" value="sender">
                <input type="hidden" name="scope_value" value="<?= htmlspecialchars($message['from'], ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="btn btn-sm btn-warning">Absender vertrauen &amp; neu laden</button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Mail-Body (sandboxed iframe) ─────────────────────────────────── -->
    <div class="mc-fullview-body">
        <iframe
            class="mc-safe-iframe w-100 border-0"
            sandbox="allow-same-origin allow-popups"
            style="min-height: 500px; height: auto;"
            srcdoc="<?= htmlspecialchars('<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
                body{margin:1rem;font-family:system-ui,sans-serif;font-size:15px;line-height:1.6;color:#212529;word-break:break-word;}
                a{color:#0d6efd;}img{max-width:100%;height:auto;}
                table{max-width:100%;overflow-x:auto;display:block;}
                pre{white-space:pre-wrap;word-break:break-all;}
                blockquote{border-left:3px solid #dee2e6;padding-left:1rem;color:#6c757d;}
                .mc-blocked-image{width:48px!important;height:48px!important;border:1px dashed #999;background:#f5f5f5;}
            </style></head><body>' . ($message['safe_html'] ?: '<em style="color:#666">(Kein Inhalt)</em>') . '</body></html>', ENT_QUOTES, 'UTF-8') ?>"
            onload="this.style.height=(this.contentDocument.body.scrollHeight+40)+'px'"
        ></iframe>
    </div>

    <!-- ── Anhänge ──────────────────────────────────────────────────────── -->
    <?php if (!empty($message['attachments'])): ?>
    <div class="mt-3 pt-3 border-top">
        <h6 class="text-body-secondary mb-2">
            <i class="bi bi-paperclip me-1"></i>
            <?= count($message['attachments']) ?> Anhang/Anhänge
        </h6>
        <div class="d-flex flex-wrap gap-2">
            <?php foreach ($message['attachments'] as $att): ?>
            <a href="/mail-client/api/attachment?account=<?= (int) $account['id'] ?>&folder=<?= rawurlencode($message['folder'] ?? 'INBOX') ?>&uid=<?= (int) $message['uid'] ?>&part=<?= rawurlencode($att['part_id']) ?>"
               class="mc-attachment-chip"
               download="<?= htmlspecialchars($att['filename'], ENT_QUOTES, 'UTF-8') ?>">
                <i class="bi bi-file-earmark"></i>
                <?= htmlspecialchars($att['filename'], ENT_QUOTES, 'UTF-8') ?>
                <?php if ($att['size'] > 0): ?>
                <span class="text-secondary">(<?= htmlspecialchars(number_format($att['size'] / 1024, 1), ENT_QUOTES, 'UTF-8') ?> KB)</span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>

</div><!-- /.mc-fullview -->

