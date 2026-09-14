<?php
declare(strict_types=1);

$sets = is_array($sets ?? null) ? $sets : [];
$message = (string) ($message ?? '');
$error = (string) ($error ?? '');
$csrfToken = (string) ($csrf_token ?? '');
$e = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
?>

<?php require __DIR__ . '/admin-nav.php'; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <p class="text-uppercase text-body-secondary small fw-semibold mb-1">Fantasy Cards Admin</p>
        <h1 class="h4 mb-1">Sets</h1>
        <p class="text-body-secondary mb-0">Grundsets für Karten und spätere Booster verwalten.</p>
    </div>
    <a class="btn btn-primary" href="/admin/fantasy-cards/sets/create">Set anlegen</a>
</div>

<?php if ($message !== ''): ?><div class="alert alert-success"><?= $e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>

<div class="app-card table-responsive">
    <table class="table table-hover align-middle mb-0 app-table">
        <thead>
            <tr>
                <th class="ps-4">Set</th>
                <th>Karten</th>
                <th>Sortierung</th>
                <th>Aktiv</th>
                <th>Free Packs</th>
                <th class="pe-4 text-end">Aktionen</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($sets === []): ?>
                <tr><td colspan="6" class="ps-4 text-body-secondary">Noch keine Sets vorhanden.</td></tr>
            <?php else: ?>
                <?php foreach ($sets as $set): ?>
                    <tr>
                        <td class="ps-4">
                            <div class="fw-semibold"><?= $e($set['name'] ?? '') ?></div>
                            <div class="small text-body-secondary">/<?= $e($set['slug'] ?? '') ?></div>
                        </td>
                        <td><?= (int) ($set['card_count'] ?? 0) ?></td>
                        <td><?= (int) ($set['sort_order'] ?? 0) ?></td>
                        <td>
                            <button class="btn btn-sm <?= (int) ($set['is_active'] ?? 0) === 1 ? 'btn-success' : 'btn-outline-secondary' ?> fantasycards-js-toggle" type="button" data-url="/admin/fantasy-cards/sets/toggle" data-csrf-token="<?= $e($csrfToken) ?>" data-id-name="set_id" data-id="<?= (int) ($set['id'] ?? 0) ?>" data-field="is_active" data-enabled="<?= (int) ($set['is_active'] ?? 0) === 1 ? '1' : '0' ?>">
                                <?= (int) ($set['is_active'] ?? 0) === 1 ? 'Aktiv' : 'Inaktiv' ?>
                            </button>
                        </td>
                        <td>
                            <button class="btn btn-sm <?= (int) ($set['available_in_free_packs'] ?? 0) === 1 ? 'btn-primary' : 'btn-outline-secondary' ?> fantasycards-js-toggle" type="button" data-url="/admin/fantasy-cards/sets/toggle" data-csrf-token="<?= $e($csrfToken) ?>" data-id-name="set_id" data-id="<?= (int) ($set['id'] ?? 0) ?>" data-field="available_in_free_packs" data-enabled="<?= (int) ($set['available_in_free_packs'] ?? 0) === 1 ? '1' : '0' ?>">
                                <?= (int) ($set['available_in_free_packs'] ?? 0) === 1 ? 'Ja' : 'Nein' ?>
                            </button>
                        </td>
                        <td class="pe-4 text-end"><a class="btn btn-sm btn-outline-primary" href="/admin/fantasy-cards/sets/<?= (int) ($set['id'] ?? 0) ?>/edit">Bearbeiten</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
document.querySelectorAll('.fantasycards-js-toggle').forEach((button) => {
    button.addEventListener('click', async () => {
        const enabled = button.dataset.enabled !== '1';
        const body = new URLSearchParams();
        body.set(button.dataset.idName || 'id', button.dataset.id || '0');
        body.set('field', button.dataset.field || '');
        body.set('enabled', enabled ? '1' : '0');
        button.disabled = true;
        try {
            const response = await fetch(button.dataset.url || '', {
                method: 'POST',
                headers: {'Accept': 'application/json', 'X-CSRF-Token': button.dataset.csrfToken || '', 'X-Requested-With': 'XMLHttpRequest'},
                body
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.message || 'Fehler');
            button.dataset.enabled = enabled ? '1' : '0';
            button.classList.toggle('btn-success', enabled && button.dataset.field === 'is_active');
            button.classList.toggle('btn-primary', enabled && button.dataset.field !== 'is_active');
            button.classList.toggle('btn-outline-secondary', !enabled);
            button.textContent = button.dataset.field === 'is_active' ? (enabled ? 'Aktiv' : 'Inaktiv') : (enabled ? 'Ja' : 'Nein');
        } catch (error) {
            alert(error instanceof Error ? error.message : 'Aktualisierung fehlgeschlagen.');
        } finally {
            button.disabled = false;
        }
    });
});
</script>
