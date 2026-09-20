<?php
declare(strict_types=1);

$message = (string) ($message ?? '');
$error = (string) ($error ?? '');
$entries = is_array($entries ?? null) ? $entries : [];
$adminSection = (string) ($admin_section ?? 'news');
$csrfToken = (string) ($csrf_token ?? '');
?>
<div class="d-flex align-items-center justify-content-between mb-4">
    <h1 class="h4 mb-0">Admin / News</h1>
    <a href="/admin/news/create" class="btn btn-primary btn-sm">Neuen Eintrag erstellen</a>
</div>

<?php if ($message !== ''): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="card shadow-sm border-0 app-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 app-table">
                <thead>
                <tr>
                    <th class="ps-4">Titel</th>
                    <th>Typ</th>
                    <th>Version</th>
                    <th>Status</th>
                    <th>Veröffentlicht</th>
                    <th class="pe-4 text-end">Aktionen</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($entries === []): ?>
                    <tr><td colspan="6" class="ps-4 text-body-secondary">Keine Einträge vorhanden.</td></tr>
                <?php else: ?>
                    <?php foreach ($entries as $entry): ?>
                        <?php
                        $id = (int) ($entry['id'] ?? 0);
                        $title = (string) ($entry['title'] ?? '');
                        $slug = (string) ($entry['slug'] ?? '');
                        $type = (string) ($entry['type'] ?? 'news');
                        $version = trim((string) ($entry['version'] ?? ''));
                        $effectiveStatus = strtolower((string) ($entry['effective_status'] ?? 'draft'));
                        $publishedAt = (string) ($entry['published_at'] ?? '');
                        $statusLabel = 'Entwurf';
                        $statusBadgeClass = 'text-bg-secondary';
                        if ($effectiveStatus === 'scheduled') {
                            $statusLabel = 'Geplant';
                            $statusBadgeClass = 'text-bg-warning';
                        } elseif ($effectiveStatus === 'published') {
                            $statusLabel = 'Veröffentlicht';
                            $statusBadgeClass = 'text-bg-success';
                        }
                        ?>
                        <tr>
                            <td class="ps-4">
                                <div class="fw-medium"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="small text-body-secondary">/news/<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?></div>
                            </td>
                            <td><span class="badge text-bg-secondary"><?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td><?= $version !== '' ? htmlspecialchars($version, ENT_QUOTES, 'UTF-8') : '<span class="text-body-secondary">-</span>' ?></td>
                            <td>
                                <span class="badge <?= $statusBadgeClass ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span>
                            </td>
                            <td>
                                <?= $publishedAt !== '' ? htmlspecialchars($publishedAt, ENT_QUOTES, 'UTF-8') : '<span class="text-body-secondary">-</span>' ?>
                                <?php if ($effectiveStatus === 'scheduled' && $publishedAt !== ''): ?>
                                    <div class="small text-body-secondary">wird nach DB-Zeit live</div>
                                <?php endif; ?>
                            </td>
                            <td class="pe-4 text-end text-nowrap">
                                <a href="/admin/news/<?= $id ?>/edit" class="btn btn-sm btn-outline-secondary">Bearbeiten</a>
                                <form method="post" action="/admin/news/delete" class="d-inline ms-1" onsubmit="return confirm('Eintrag wirklich löschen?');">
                                    <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                    <input type="hidden" name="entry_id" value="<?= $id ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Löschen</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
