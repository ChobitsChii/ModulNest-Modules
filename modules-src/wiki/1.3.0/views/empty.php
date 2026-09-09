<?php
declare(strict_types=1);

$isAdmin = (bool) ($is_admin ?? false);
?>
<div class="row g-4">
    <div class="col-12 col-lg-8">
        <section class="card shadow-sm border-0 app-card">
            <div class="card-body p-4 p-md-5">
                <p class="text-uppercase text-body-secondary small fw-semibold mb-1">Wiki</p>
                <h1 class="h3 mb-3">Wiki noch nicht eingerichtet</h1>
                <p class="text-body-secondary mb-0">Für dieses Wiki wurden noch keine Inhalte synchronisiert.</p>
                <?php if ($isAdmin): ?>
                    <div class="alert alert-info mt-4 mb-0" role="status">
                        Als Administrator kannst du die Wiki-Quelle einrichten und anschließend synchronisieren.
                        <a class="alert-link" href="/admin/wiki">Wiki verwalten</a>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>
