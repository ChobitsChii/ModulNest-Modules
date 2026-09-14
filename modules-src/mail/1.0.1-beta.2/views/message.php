<?php
declare(strict_types=1);
?>

<?php require __DIR__ . '/partials/nav.php'; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">Nachricht</h1>
</div>

<?php require __DIR__ . '/partials/message-detail.php'; ?>

<script src="/assets/js/mail-frame-autosize.js"></script>
<script>
(() => {
    if (window.ModulonMailFrameAutosize && typeof window.ModulonMailFrameAutosize.init === 'function') {
        window.ModulonMailFrameAutosize.init(document);
    }
})();
</script>
