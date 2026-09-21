<?php
declare(strict_types=1);
/**
 * Compose Empty-State – Hinweis wenn noch kein Mail-Konto existiert.
 *
 * @var string $title
 * @var string $current_path
 */
$title        = (string) ($title ?? 'Kein Mail-Konto');
$currentPath  = (string) ($current_path ?? '/mail-client/compose');
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
<div class="col-12 col-xl-8">

<div class="d-flex align-items-center gap-2 mb-4">
    <a href="/mail-client" class="btn btn-sm btn-outline-secondary" title="Zurück zum Mail-Client">
        <i class="bi bi-arrow-left"></i> Zurück
    </a>
    <h1 class="h4 mb-0">Neue E-Mail</h1>
</div>

<div class="card shadow-sm border">
    <div class="card-body text-center py-5">

        <div class="mb-3">
            <i class="bi bi-envelope-plus" style="font-size: 3.5rem; color: #64748b;"></i>
        </div>

        <h2 class="h5 mb-2">Noch kein Mail-Konto eingerichtet</h2>
        <p class="text-muted mb-4">
            Du hast noch kein E-Mail-Konto in deinem Mail-Client registriert. 
            Ohne Konto kannst du weder E-Mails empfangen noch versenden.
        </p>

        <div class="d-flex gap-2 justify-content-center flex-wrap">
            <a href="/mail-client/accounts/create" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i>
                Jetzt Mail-Konto anlegen
            </a>
            <a href="/mail-client" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>
                Zurück zum Posteingang
            </a>
        </div>

    </div>
</div>

</div>
</div>
</div><!-- /.mc-subpage-container -->
