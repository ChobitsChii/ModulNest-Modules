<?php
declare(strict_types=1);

$accounts = is_array($accounts ?? null) ? $accounts : [];
$message = (string) ($mail_info ?? '');
$error = (string) ($mail_error ?? '');
$favoriteFolders = is_array($favorite_folders ?? null) ? $favorite_folders : [];
$favoriteMap = is_array($favorite_map ?? null) ? $favorite_map : [];
$accountFolders = is_array($account_folders ?? null) ? $account_folders : [];
$accountErrors = is_array($account_errors ?? null) ? $account_errors : [];
$folderStatsByAccount = is_array($folder_stats_by_account ?? null) ? $folder_stats_by_account : [];
$selectedAccountId = (int) ($selected_account_id ?? 0);
$selectedFolder = (string) ($selected_folder ?? '');
$selectedSort = (string) ($selected_sort ?? 'date');
$selectedDirection = (string) ($selected_direction ?? 'desc');
$selectedGroup = (string) ($selected_group ?? 'none');
$selectedViewMode = (string) ($selected_view_mode ?? 'messages');
$selectedMainTab = (string) ($selected_main_tab ?? 'workspace');
$csrfToken = (string) ($csrf_token ?? '');
$selectedSenderKey = strtolower((string) ($selected_sender_key ?? ''));
$messages = is_array($messages ?? null) ? $messages : [];
$messageGroups = is_array($message_groups ?? null) ? $message_groups : [];
$senderStats = is_array($sender_stats ?? null) ? $sender_stats : [];
$excludedSenderKeys = is_array($excluded_sender_keys ?? null) ? $excluded_sender_keys : [];
$messageError = (string) ($message_error ?? '');
$imapPrereqError = (string) ($imap_prereq_error ?? '');
$messageListLimit = (int) ($message_list_limit ?? 50);
$messageHasMore = (bool) ($message_has_more ?? false);
$messageNextUntilUid = isset($message_next_until_uid) ? (int) $message_next_until_uid : 0;
$messageCurrentUntilUid = isset($message_current_until_uid) ? (int) $message_current_until_uid : 0;

$selectedAccountFolders = is_array($accountFolders[$selectedAccountId] ?? null) ? $accountFolders[$selectedAccountId] : [];
$sectionHeading = $selectedMainTab === 'accounts' ? 'Konten & Ordner' : 'Arbeitsbereich';
?>

<?php require __DIR__ . '/partials/nav.php'; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h4 mb-1"><?= htmlspecialchars($sectionHeading, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="text-body-secondary mb-0">
            Multi-Account-Webmail-Client für IMAP/SMTP mit sicherem serverseitigem Abruf und Versand.
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="/mail/compose" class="btn btn-sm btn-outline-primary">Neue E-Mail</a>
        <a href="/mail/accounts/create" class="btn btn-primary btn-sm">Mailkonto hinzufügen</a>
    </div>
</div>

<?php if ($imapPrereqError !== ''): ?>
    <div class="alert alert-warning">
        <strong>Mail-Voraussetzung fehlt:</strong> <?= htmlspecialchars($imapPrereqError, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<div class="card shadow-sm border-0 app-card mb-3">
    <div class="card-body">
        <h2 class="h6 text-uppercase text-body-secondary mb-3">Favoriten</h2>
        <?php if ($favoriteFolders === []): ?>
            <p class="text-body-secondary mb-0">Noch keine Favoriten-Ordner markiert.</p>
        <?php else: ?>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($favoriteFolders as $favorite): ?>
                    <?php
                    $favAccountId = (int) ($favorite['mail_account_id'] ?? 0);
                    $favFolder = (string) ($favorite['folder_name'] ?? '');
                    $favAccountName = (string) ($favorite['display_name'] ?? '');
                    $favEmail = (string) ($favorite['email_address'] ?? '');
                    $favoriteOpenUrl = '/mail?' . http_build_query([
                        'account' => $favAccountId,
                        'folder' => $favFolder,
                        'sort' => $selectedSort,
                        'direction' => $selectedDirection,
                        'group' => 'none',
                        'view' => 'messages',
                    ]);
                    ?>
                    <div class="modulon-mail-favorite-chip">
                        <a href="<?= htmlspecialchars($favoriteOpenUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-warning modulon-mail-favorite-link">
                            ★ <?= htmlspecialchars($favFolder, ENT_QUOTES, 'UTF-8') ?>
                            <span class="text-body-secondary">· <?= htmlspecialchars($favAccountName . ' (' . $favEmail . ')', ENT_QUOTES, 'UTF-8') ?></span>
                        </a>
                        <form method="post" action="/mail/accounts/<?= $favAccountId ?>/favorites/toggle" class="m-0">
                            <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                            <input type="hidden" name="folder_name" value="<?= htmlspecialchars($favFolder, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="mode" value="remove">
                            <input type="hidden" name="section" value="<?= htmlspecialchars($selectedMainTab, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="account" value="<?= $selectedAccountId ?>">
                            <input type="hidden" name="folder" value="<?= htmlspecialchars($selectedFolder, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="sort" value="<?= htmlspecialchars($selectedSort, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="direction" value="<?= htmlspecialchars($selectedDirection, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="group" value="<?= htmlspecialchars($selectedGroup, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="view" value="<?= htmlspecialchars($selectedViewMode, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="sender" value="<?= htmlspecialchars($selectedSenderKey, ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger modulon-mail-favorite-remove" title="Favorit entfernen" aria-label="Favorit entfernen">
                                ×
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($selectedMainTab === 'accounts'): ?>
<div class="row g-3 mb-3">
    <?php foreach ($accounts as $account): ?>
        <?php
        $accountId = (int) ($account['id'] ?? 0);
        $accountName = (string) ($account['display_name'] ?? '');
        $accountMail = (string) ($account['email_address'] ?? '');
        $isActive = (int) ($account['is_active'] ?? 0) === 1;
        $folders = is_array($accountFolders[$accountId] ?? null) ? $accountFolders[$accountId] : [];
        $accountError = (string) ($accountErrors[$accountId] ?? '');
        $favoritesForAccount = is_array($favoriteMap[$accountId] ?? null) ? $favoriteMap[$accountId] : [];
        $folderStats = is_array($folderStatsByAccount[$accountId] ?? null) ? $folderStatsByAccount[$accountId] : [];
        ?>
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm border-0 app-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                        <div>
                            <h3 class="h6 mb-1"><?= htmlspecialchars($accountName, ENT_QUOTES, 'UTF-8') ?></h3>
                            <p class="small text-body-secondary mb-0"><?= htmlspecialchars($accountMail, ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                        <span class="badge <?= $isActive ? 'text-bg-success' : 'text-bg-secondary' ?>">
                            <?= $isActive ? 'Aktiv' : 'Inaktiv' ?>
                        </span>
                    </div>

                    <?php if (!$isActive): ?>
                        <p class="small text-body-secondary mb-0">Konto ist inaktiv. Ordner werden nicht geladen.</p>
                    <?php elseif ($accountError !== ''): ?>
                        <div class="alert alert-warning mb-0">
                            <strong>IMAP:</strong> <?= htmlspecialchars($accountError, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php elseif ($folders === []): ?>
                        <p class="small text-body-secondary mb-0">Keine IMAP-Ordner gefunden.</p>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($folders as $folderName): ?>
                                <?php $isFavorite = (bool) ($favoritesForAccount[$folderName] ?? false); ?>
                                <?php $stats = is_array($folderStats[$folderName] ?? null) ? $folderStats[$folderName] : ['total_count' => 0, 'unread_count' => 0]; ?>
                                <div class="list-group-item px-0 d-flex justify-content-between align-items-center gap-2 bg-transparent border-0 border-bottom">
                                    <div class="d-flex align-items-center gap-2 text-truncate flex-wrap">
                                        <?php if ($selectedAccountId === $accountId && $selectedFolder === (string) $folderName): ?>
                                            <span class="badge text-bg-primary">Aktiv</span>
                                        <?php endif; ?>
                                        <span class="text-truncate"><?= htmlspecialchars((string) $folderName, ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="badge text-bg-secondary js-folder-total" data-folder-stats-key="<?= $accountId ?>::<?= htmlspecialchars((string) $folderName, ENT_QUOTES, 'UTF-8') ?>">
                                            <?= (int) ($stats['total_count'] ?? 0) ?>
                                        </span>
                                        <span class="badge <?= (int) ($stats['unread_count'] ?? 0) > 0 ? 'text-bg-primary' : 'text-bg-secondary' ?> js-folder-unread" data-folder-stats-key="<?= $accountId ?>::<?= htmlspecialchars((string) $folderName, ENT_QUOTES, 'UTF-8') ?>">
                                            <?= (int) ($stats['unread_count'] ?? 0) ?> ungelesen
                                        </span>
                                    </div>
                                    <div class="d-flex align-items-center gap-1">
                                        <?php
                                        $folderUrl = '/mail?' . http_build_query([
                                            'account' => $accountId,
                                            'folder' => (string) $folderName,
                                            'sort' => $selectedSort,
                                            'direction' => $selectedDirection,
                                            'group' => $selectedGroup,
                                            'view' => $selectedViewMode,
                                            'sender' => $selectedViewMode === 'senders' ? $selectedSenderKey : null,
                                        ]);
                                        ?>
                                        <a href="<?= htmlspecialchars($folderUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-primary">Öffnen</a>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-info js-refresh-folder"
                                            data-account-id="<?= $accountId ?>"
                                            data-folder-name="<?= htmlspecialchars((string) $folderName, ENT_QUOTES, 'UTF-8') ?>"
                                            data-stats-key="<?= $accountId ?>::<?= htmlspecialchars((string) $folderName, ENT_QUOTES, 'UTF-8') ?>"
                                        >
                                            Aktualisieren
                                        </button>
                                        <form method="post" action="/mail/accounts/<?= $accountId ?>/favorites/toggle" class="m-0">
                                            <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                            <input type="hidden" name="folder_name" value="<?= htmlspecialchars((string) $folderName, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="section" value="accounts">
                                            <input type="hidden" name="mode" value="toggle">
                                            <input type="hidden" name="account" value="<?= $selectedAccountId ?>">
                                            <input type="hidden" name="folder" value="<?= htmlspecialchars($selectedFolder, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="sort" value="<?= htmlspecialchars($selectedSort, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="direction" value="<?= htmlspecialchars($selectedDirection, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="group" value="<?= htmlspecialchars($selectedGroup, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="view" value="<?= htmlspecialchars($selectedViewMode, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="sender" value="<?= htmlspecialchars($selectedSenderKey, ENT_QUOTES, 'UTF-8') ?>">
                                            <button type="submit" class="btn btn-sm <?= $isFavorite ? 'btn-warning' : 'btn-outline-secondary' ?>">
                                                <?= $isFavorite ? '★ Favorit' : '☆ Favorit' ?>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($selectedMainTab === 'workspace'): ?>
<div id="mail-message-list"></div>
<div class="card shadow-sm border-0 app-card mt-3 mb-3">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h2 class="h6 text-uppercase text-body-secondary mb-0">Nachrichtenliste</h2>
            <span class="small text-body-secondary">
                <?= $selectedViewMode === 'senders'
                    ? 'Absender-orientierte Übersicht je Ordner. Klick auf einen Absender zeigt die zugehörigen Nachrichten.'
                    : 'Sortierung und Gruppierung sind getrennt steuerbar. Es werden die neuesten ' . $messageListLimit . ' Nachrichten geladen.' ?>
            </span>
        </div>

        <div class="mb-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div class="btn-group btn-group-sm" role="group" aria-label="Mail-Ansicht">
                    <?php
                    $messageViewUrl = '/mail?' . http_build_query([
                        'account' => $selectedAccountId > 0 ? $selectedAccountId : null,
                        'folder' => $selectedFolder !== '' ? $selectedFolder : null,
                        'sort' => $selectedSort,
                        'direction' => $selectedDirection,
                        'group' => $selectedGroup,
                        'view' => 'messages',
                    ]);
                    $senderViewUrl = '/mail?' . http_build_query([
                        'account' => $selectedAccountId > 0 ? $selectedAccountId : null,
                        'folder' => $selectedFolder !== '' ? $selectedFolder : null,
                        'sort' => $selectedSort,
                        'direction' => $selectedDirection,
                        'group' => 'none',
                        'view' => 'senders',
                    ]);
                    ?>
                    <a href="<?= htmlspecialchars($messageViewUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn <?= $selectedViewMode === 'messages' ? 'btn-primary' : 'btn-outline-primary' ?>">Nachrichten</a>
                    <a href="<?= htmlspecialchars($senderViewUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn <?= $selectedViewMode === 'senders' ? 'btn-primary' : 'btn-outline-primary' ?>">Absender</a>
                </div>
                <?php if ($selectedViewMode === 'messages'): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="mail-preview-toggle" aria-pressed="true">
                        Vorschau aus
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <form method="get" action="/mail" class="row g-2 align-items-end mb-3">
            <div class="col-12 col-lg-3">
                <label class="form-label small text-body-secondary mb-1" for="mail-account-select">Konto</label>
                <select class="form-select form-select-sm" id="mail-account-select" name="account">
                    <option value="0">Bitte wählen</option>
                    <?php foreach ($accounts as $account): ?>
                        <?php
                        $accountId = (int) ($account['id'] ?? 0);
                        $isActive = (int) ($account['is_active'] ?? 0) === 1;
                        if (!$isActive) {
                            continue;
                        }
                        ?>
                        <option value="<?= $accountId ?>" <?= $selectedAccountId === $accountId ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) ($account['display_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-lg-3">
                <label class="form-label small text-body-secondary mb-1" for="mail-folder-select">Ordner</label>
                <select class="form-select form-select-sm" id="mail-folder-select" name="folder">
                    <?php if ($selectedAccountFolders === []): ?>
                        <option value="">Keine Ordner</option>
                    <?php else: ?>
                        <?php foreach ($selectedAccountFolders as $folderName): ?>
                            <option value="<?= htmlspecialchars((string) $folderName, ENT_QUOTES, 'UTF-8') ?>" <?= $selectedFolder === (string) $folderName ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string) $folderName, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-12 col-lg-2">
                <label class="form-label small text-body-secondary mb-1" for="mail-sort-select">Sortieren nach</label>
                <select class="form-select form-select-sm" id="mail-sort-select" name="sort">
                    <option value="date" <?= $selectedSort === 'date' ? 'selected' : '' ?>>Datum</option>
                </select>
            </div>
            <div class="col-12 col-lg-2">
                <label class="form-label small text-body-secondary mb-1" for="mail-direction-select">Richtung</label>
                <select class="form-select form-select-sm" id="mail-direction-select" name="direction">
                    <option value="desc" <?= $selectedDirection === 'desc' ? 'selected' : '' ?>>Neueste zuerst</option>
                    <option value="asc" <?= $selectedDirection === 'asc' ? 'selected' : '' ?>>Älteste zuerst</option>
                </select>
            </div>
            <div class="col-12 col-lg-2">
                <label class="form-label small text-body-secondary mb-1" for="mail-group-select">Gruppieren nach</label>
                <select class="form-select form-select-sm" id="mail-group-select" name="group">
                    <option value="none" <?= $selectedGroup === 'none' ? 'selected' : '' ?>>Keine</option>
                    <option value="sender" <?= $selectedGroup === 'sender' ? 'selected' : '' ?>>Absender</option>
                </select>
            </div>
            <input type="hidden" name="view" value="<?= htmlspecialchars($selectedViewMode, ENT_QUOTES, 'UTF-8') ?>">
            <?php if ($selectedSenderKey !== ''): ?>
                <input type="hidden" name="sender" value="<?= htmlspecialchars($selectedSenderKey, ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>
            <div class="col-12">
                <button type="submit" class="btn btn-sm btn-primary">Ansicht aktualisieren</button>
            </div>
        </form>

        <?php if ($selectedViewMode === 'messages' && $selectedSenderKey !== ''): ?>
            <?php
            $clearSenderFilterUrl = '/mail?' . http_build_query([
                'account' => $selectedAccountId > 0 ? $selectedAccountId : null,
                'folder' => $selectedFolder !== '' ? $selectedFolder : null,
                'sort' => $selectedSort,
                'direction' => $selectedDirection,
                'group' => 'none',
                'view' => 'messages',
            ]);
            ?>
            <div class="alert alert-info d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <strong>Absender-Filter aktiv:</strong>
                    <code><?= htmlspecialchars($selectedSenderKey, ENT_QUOTES, 'UTF-8') ?></code>
                </div>
                <a href="<?= htmlspecialchars($clearSenderFilterUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-mail-filter-clear">Filter entfernen</a>
            </div>
        <?php endif; ?>

        <?php if ($selectedAccountId <= 0 || $selectedFolder === ''): ?>
            <p class="text-body-secondary mb-0">Bitte aktives Konto und Ordner auswählen.</p>
        <?php elseif ($messageError !== ''): ?>
            <div class="alert alert-warning mb-0">
                <strong>Nachrichtenabruf:</strong> <?= htmlspecialchars($messageError, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php elseif ($selectedViewMode === 'senders'): ?>
            <?php if ($senderStats === []): ?>
                <p class="text-body-secondary mb-3">Keine Absender im ausgewählten Ordner gefunden.</p>
            <?php else: ?>
                <div class="table-responsive mb-3">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Absender</th>
                            <th style="width: 120px;">Gesamt</th>
                            <th style="width: 120px;">Ungelesen</th>
                            <th style="width: 180px;">Letzte Nachricht</th>
                            <th style="width: 240px;">Aktionen</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($senderStats as $senderEntry): ?>
                            <?php
                            $senderKey = strtolower((string) ($senderEntry['sender_key'] ?? ''));
                            $senderLabel = (string) ($senderEntry['sender_label'] ?? $senderKey);
                            $senderUrl = '/mail?' . http_build_query([
                                'account' => $selectedAccountId,
                                'folder' => $selectedFolder,
                                'sort' => $selectedSort,
                                'direction' => $selectedDirection,
                                'group' => 'none',
                                'view' => 'messages',
                                'sender' => $senderKey,
                            ]);
                            ?>
                            <tr>
                                <td class="<?= $selectedSenderKey === $senderKey ? 'fw-semibold' : '' ?>">
                                    <a href="<?= htmlspecialchars($senderUrl, ENT_QUOTES, 'UTF-8') ?>" class="text-reset text-decoration-none">
                                        <?= htmlspecialchars($senderLabel, ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                </td>
                                <td><?= (int) ($senderEntry['total_count'] ?? 0) ?></td>
                                <td>
                                    <span class="badge <?= (int) ($senderEntry['unread_count'] ?? 0) > 0 ? 'text-bg-primary' : 'text-bg-secondary' ?>">
                                        <?= (int) ($senderEntry['unread_count'] ?? 0) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars((string) ($senderEntry['latest_date_label'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <div class="d-flex flex-wrap gap-1">
                                        <a href="<?= htmlspecialchars($senderUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-primary">
                                            Nachrichten anzeigen
                                        </a>
                                        <form method="post" action="/mail/accounts/<?= $selectedAccountId ?>/senders/exclude" class="m-0">
                                            <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                            <input type="hidden" name="folder" value="<?= htmlspecialchars($selectedFolder, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="sender_key" value="<?= htmlspecialchars($senderKey, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="sort" value="<?= htmlspecialchars($selectedSort, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="direction" value="<?= htmlspecialchars($selectedDirection, ENT_QUOTES, 'UTF-8') ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-warning">Ausschließen</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($excludedSenderKeys !== []): ?>
                <div class="mb-3">
                    <h3 class="h6 text-uppercase text-body-secondary mb-2">Ausgeschlossene Absender</h3>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($excludedSenderKeys as $excludedKey): ?>
                            <form method="post" action="/mail/accounts/<?= $selectedAccountId ?>/senders/include" class="d-inline-flex gap-2 align-items-center">
                                <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                <input type="hidden" name="folder" value="<?= htmlspecialchars($selectedFolder, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="sender_key" value="<?= htmlspecialchars((string) $excludedKey, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="sort" value="<?= htmlspecialchars($selectedSort, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="direction" value="<?= htmlspecialchars($selectedDirection, ENT_QUOTES, 'UTF-8') ?>">
                                <span class="badge text-bg-secondary"><?= htmlspecialchars((string) $excludedKey, ENT_QUOTES, 'UTF-8') ?></span>
                                <button type="submit" class="btn btn-sm btn-outline-success">Wieder anzeigen</button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="modulon-mail-workspace" id="mail-workspace">
                <div class="modulon-mail-list-pane" id="mail-list-pane">
                    <?php if ($messages === []): ?>
                        <p class="text-body-secondary mb-0">Keine Nachrichten im ausgewählten Ordner.</p>
                    <?php elseif ($selectedGroup === 'sender'): ?>
                        <div class="d-flex flex-column gap-3">
                            <?php foreach ($messageGroups as $group): ?>
                                <?php
                                $groupLabel = (string) ($group['label'] ?? 'Unbekannt');
                                $groupCount = (int) ($group['count'] ?? 0);
                                $groupMessages = is_array($group['messages'] ?? null) ? $group['messages'] : [];
                                ?>
                                <div class="border rounded-2 p-2">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div class="fw-semibold"><?= htmlspecialchars($groupLabel, ENT_QUOTES, 'UTF-8') ?></div>
                                        <span class="badge text-bg-secondary"><?= $groupCount ?> Nachricht<?= $groupCount === 1 ? '' : 'en' ?></span>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle mb-0">
                                            <thead>
                                            <tr>
                                                <th>Betreff</th>
                                                <th style="width: 180px;">Datum</th>
                                                <th style="width: 120px;">Status</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($groupMessages as $entry): ?>
                                                <?php $isRead = (bool) ($entry['is_read'] ?? false); ?>
                                                <?php
                                                $messageUrl = '/mail/message?' . http_build_query([
                                                    'account' => $selectedAccountId,
                                                    'folder' => $selectedFolder,
                                                    'uid' => (int) ($entry['uid'] ?? 0),
                                                    'sort' => $selectedSort,
                                                    'direction' => $selectedDirection,
                                                    'group' => $selectedGroup,
                                                    'view' => 'messages',
                                                    'sender' => $selectedSenderKey !== '' ? $selectedSenderKey : null,
                                                    'until_uid' => $messageCurrentUntilUid > 0 ? $messageCurrentUntilUid : null,
                                                ]);
                                                ?>
                                                <tr>
                                                    <td class="<?= $isRead ? 'text-body-secondary' : 'fw-semibold' ?>">
                                                        <a
                                                            href="<?= htmlspecialchars($messageUrl, ENT_QUOTES, 'UTF-8') ?>"
                                                            class="text-reset text-decoration-none js-mail-open"
                                                            data-message-url="<?= htmlspecialchars($messageUrl, ENT_QUOTES, 'UTF-8') ?>"
                                                        >
                                                            <?= htmlspecialchars((string) ($entry['subject'] ?? '(ohne Betreff)'), ENT_QUOTES, 'UTF-8') ?>
                                                        </a>
                                                    </td>
                                                    <td><?= htmlspecialchars((string) ($entry['date_label'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td>
                                                        <span class="badge <?= $isRead ? 'text-bg-secondary' : 'text-bg-primary' ?>">
                                                            <?= $isRead ? 'Gelesen' : 'Ungelesen' ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                <tr>
                                    <th>Absender</th>
                                    <th>Betreff</th>
                                    <th style="width: 180px;">Datum</th>
                                    <th style="width: 120px;">Status</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($messages as $entry): ?>
                                    <?php $isRead = (bool) ($entry['is_read'] ?? false); ?>
                                    <?php
                                    $messageUrl = '/mail/message?' . http_build_query([
                                        'account' => $selectedAccountId,
                                        'folder' => $selectedFolder,
                                        'uid' => (int) ($entry['uid'] ?? 0),
                                        'sort' => $selectedSort,
                                        'direction' => $selectedDirection,
                                        'group' => $selectedGroup,
                                        'view' => 'messages',
                                        'sender' => $selectedSenderKey !== '' ? $selectedSenderKey : null,
                                        'until_uid' => $messageCurrentUntilUid > 0 ? $messageCurrentUntilUid : null,
                                    ]);
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) ($entry['sender_label'] ?? 'Unbekannt'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="<?= $isRead ? 'text-body-secondary' : 'fw-semibold' ?>">
                                            <a
                                                href="<?= htmlspecialchars($messageUrl, ENT_QUOTES, 'UTF-8') ?>"
                                                class="text-reset text-decoration-none js-mail-open"
                                                data-message-url="<?= htmlspecialchars($messageUrl, ENT_QUOTES, 'UTF-8') ?>"
                                            >
                                                <?= htmlspecialchars((string) ($entry['subject'] ?? '(ohne Betreff)'), ENT_QUOTES, 'UTF-8') ?>
                                            </a>
                                        </td>
                                        <td><?= htmlspecialchars((string) ($entry['date_label'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <span class="badge <?= $isRead ? 'text-bg-secondary' : 'text-bg-primary' ?>">
                                                <?= $isRead ? 'Gelesen' : 'Ungelesen' ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <?php if ($messages !== [] && $messageHasMore && $messageNextUntilUid > 0): ?>
                        <div class="mt-3">
                            <form method="get" action="/mail#mail-message-list" class="d-inline">
                                <input type="hidden" name="account" value="<?= $selectedAccountId ?>">
                                <input type="hidden" name="folder" value="<?= htmlspecialchars($selectedFolder, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="sort" value="<?= htmlspecialchars($selectedSort, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="direction" value="<?= htmlspecialchars($selectedDirection, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="group" value="<?= htmlspecialchars($selectedGroup, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="view" value="<?= htmlspecialchars($selectedViewMode, ENT_QUOTES, 'UTF-8') ?>">
                                <?php if ($selectedSenderKey !== ''): ?>
                                    <input type="hidden" name="sender" value="<?= htmlspecialchars($selectedSenderKey, ENT_QUOTES, 'UTF-8') ?>">
                                <?php endif; ?>
                                <input type="hidden" name="until_uid" value="<?= $messageNextUntilUid ?>">
                                <button type="submit" class="btn btn-sm btn-outline-primary">Mehr laden</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="modulon-mail-split-handle" id="mail-split-handle" aria-label="Größe anpassen" role="separator"></div>

                <div id="mail-detail-panel" class="modulon-mail-detail-panel is-open" data-account-id="<?= $selectedAccountId ?>" data-folder="<?= htmlspecialchars($selectedFolder, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <strong class="small text-uppercase text-body-secondary">Nachricht</strong>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="mail-detail-close">Schließen</button>
                    </div>
                    <div id="mail-detail-content" class="modulon-mail-detail-placeholder text-body-secondary">
                        Bitte eine Nachricht auswählen.
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<script src="/assets/js/mail-frame-autosize.js"></script>
<script>
(() => {
    const csrfHeaders = {'X-CSRF-Token': <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, 'Accept': 'application/json'};
    const workspace = document.getElementById('mail-workspace');
    const listPane = document.getElementById('mail-list-pane');
    const splitHandle = document.getElementById('mail-split-handle');
    const detailPanel = document.getElementById('mail-detail-panel');
    const detailContent = document.getElementById('mail-detail-content');
    const closeButton = document.getElementById('mail-detail-close');
    const previewToggle = document.getElementById('mail-preview-toggle');
    const previewEnabledStorageKey = 'modulon.mail.preview.enabled';
    const previewListHeightStorageKey = 'modulon.mail.preview.listHeight';
    const splitHandleHeight = 10;
    const minListHeight = 180;
    const minDetailHeight = 180;
    let activeRequest = null;
    let isResizing = false;
    let previewEnabled = true;
    let currentListHeight = 0;

    function readStorage(key) {
        try {
            return window.localStorage.getItem(key);
        } catch (_error) {
            return null;
        }
    }

    function writeStorage(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch (_error) {
            // localStorage optional
        }
    }

    function setDetailLoading() {
        if (!detailContent) return;
        detailContent.classList.remove('modulon-mail-detail-placeholder', 'text-body-secondary');
        detailContent.innerHTML = '<div class="text-body-secondary">Nachricht wird geladen...</div>';
        detailContent.scrollTop = 0;
    }

    function closeDetailPanel() {
        if (!detailPanel || !detailContent) return;
        setPreviewEnabled(false);
    }

    function resetDetailContent() {
        if (!detailContent) return;
        detailContent.classList.add('modulon-mail-detail-placeholder', 'text-body-secondary');
        detailContent.innerHTML = 'Bitte eine Nachricht auswählen.';
        detailContent.scrollTop = 0;
        document.querySelectorAll('.js-mail-open.is-active').forEach((node) => node.classList.remove('is-active'));
    }

    function setPreviewToggleLabel() {
        if (!previewToggle) return;
        previewToggle.textContent = previewEnabled ? 'Vorschau aus' : 'Vorschau an';
        previewToggle.setAttribute('aria-pressed', previewEnabled ? 'true' : 'false');
    }

    function setPreviewEnabled(enabled) {
        if (!workspace || !listPane) return;
        previewEnabled = enabled;
        workspace.classList.toggle('is-preview-hidden', !previewEnabled);
        if (previewEnabled && currentListHeight > 0) {
            clampListHeight(currentListHeight);
        } else if (!previewEnabled) {
            listPane.style.height = '';
            workspace.style.removeProperty('--mail-list-pane-height');
            resetDetailContent();
        }
        setPreviewToggleLabel();
        writeStorage(previewEnabledStorageKey, previewEnabled ? '1' : '0');
    }

    async function loadMessageDetail(url, trigger) {
        if (!detailPanel || !detailContent) {
            window.location.href = url;
            return;
        }
        if (!previewEnabled) {
            setPreviewEnabled(true);
        }
        const separator = url.includes('?') ? '&' : '?';
        const requestUrl = `${url}${separator}partial=1`;

        if (activeRequest && typeof activeRequest.abort === 'function') {
            activeRequest.abort();
        }
        activeRequest = new AbortController();

        setDetailLoading();
        document.querySelectorAll('.js-mail-open.is-active').forEach((node) => node.classList.remove('is-active'));
        if (trigger) {
            trigger.classList.add('is-active');
        }

        try {
            const response = await fetch(requestUrl, {
                method: 'GET',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                signal: activeRequest.signal,
            });
            if (!response.ok) {
                throw new Error('Detail konnte nicht geladen werden.');
            }
            detailContent.classList.remove('modulon-mail-detail-placeholder', 'text-body-secondary');
            detailContent.innerHTML = await response.text();
            detailContent.scrollTop = 0;
            if (window.ModulonMailFrameAutosize && typeof window.ModulonMailFrameAutosize.init === 'function') {
                window.ModulonMailFrameAutosize.init(detailContent);
            }
            if (previewEnabled) {
                clampListHeight(currentListHeight > 0 ? currentListHeight : defaultListHeight());
            }
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }
            detailContent.classList.remove('modulon-mail-detail-placeholder');
            detailContent.classList.add('text-body-secondary');
            detailContent.innerHTML = '<div class="alert alert-warning mb-0">Nachricht konnte nicht geladen werden.</div>';
            detailContent.scrollTop = 0;
        }
    }

    document.addEventListener('click', (event) => {
        const link = event.target.closest('.js-mail-open');
        if (!link) return;
        event.preventDefault();
        const href = link.getAttribute('href');
        if (!href) return;
        loadMessageDetail(href, link);
    });

    document.addEventListener('click', (event) => {
        const link = event.target.closest('.js-mail-detail-link');
        if (!link || !workspace || !detailContent) return;
        event.preventDefault();
        const href = link.getAttribute('href');
        if (!href) return;
        const activeTrigger = document.querySelector('.js-mail-open.is-active');
        loadMessageDetail(href, activeTrigger instanceof Element ? activeTrigger : null);
    });

    document.addEventListener('submit', async (event) => {
        const form = event.target.closest('.js-mail-detail-whitelist');
        if (!form || !workspace || !detailContent) return;
        event.preventDefault();

        const formData = new FormData(form);
        const payload = new URLSearchParams();
        formData.forEach((value, key) => {
            if (typeof value === 'string') {
                payload.append(key, value);
            }
        });

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: {
                    ...csrfHeaders,
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: payload.toString(),
            });
            if (!response.ok) {
                throw new Error('Whitelist-Aktion konnte nicht gespeichert werden.');
            }

            const query = new URLSearchParams();
            query.set('account', String(formData.get('account_id') || '0'));
            query.set('folder', String(formData.get('folder') || ''));
            query.set('uid', String(formData.get('uid') || '0'));
            query.set('sort', String(formData.get('sort') || 'date'));
            query.set('direction', String(formData.get('direction') || 'desc'));
            query.set('group', String(formData.get('group') || 'none'));
            query.set('view', String(formData.get('view') || 'messages'));
            const sender = String(formData.get('sender') || '');
            const untilUid = String(formData.get('until_uid') || '');
            if (sender !== '') {
                query.set('sender', sender);
            }
            if (untilUid !== '' && untilUid !== '0') {
                query.set('until_uid', untilUid);
            }

            const reloadUrl = `/mail/message?${query.toString()}`;
            const activeTrigger = document.querySelector('.js-mail-open.is-active');
            loadMessageDetail(reloadUrl, activeTrigger instanceof Element ? activeTrigger : null);
        } catch (error) {
            detailContent.classList.remove('modulon-mail-detail-placeholder');
            detailContent.innerHTML = '<div class="alert alert-warning mb-0">Whitelist-Aktion konnte nicht ausgeführt werden.</div>';
        }
    });

    if (closeButton) {
        closeButton.addEventListener('click', closeDetailPanel);
    }

    function clampListHeight(height) {
        if (!workspace || !listPane || !detailPanel || !previewEnabled) {
            return;
        }
        const workspaceHeight = workspace.getBoundingClientRect().height;
        const maxList = Math.max(minListHeight, workspaceHeight - minDetailHeight - splitHandleHeight);
        const clamped = Math.max(minListHeight, Math.min(height, maxList));
        listPane.style.height = `${clamped}px`;
        workspace.style.setProperty('--mail-list-pane-height', `${clamped}px`);
        currentListHeight = clamped;
        writeStorage(previewListHeightStorageKey, String(clamped));
    }

    function defaultListHeight() {
        if (!workspace) {
            return minListHeight;
        }
        const workspaceHeight = workspace.getBoundingClientRect().height;
        return Math.round((workspaceHeight - splitHandleHeight) / 2);
    }

    if (workspace && listPane && detailPanel && splitHandle) {
        const storedPreviewEnabled = readStorage(previewEnabledStorageKey);
        previewEnabled = storedPreviewEnabled !== '0';
        const storedListHeight = Number.parseFloat(readStorage(previewListHeightStorageKey) || '');
        currentListHeight = Number.isFinite(storedListHeight) ? storedListHeight : 0;
        if (previewEnabled) {
            clampListHeight(currentListHeight > 0 ? currentListHeight : defaultListHeight());
        } else {
            listPane.style.height = '';
            workspace.style.removeProperty('--mail-list-pane-height');
        }
        workspace.classList.toggle('is-preview-hidden', !previewEnabled);
        setPreviewToggleLabel();

        splitHandle.addEventListener('mousedown', (event) => {
            if (!previewEnabled) {
                return;
            }
            event.preventDefault();
            isResizing = true;
            document.body.classList.add('is-mail-resizing');
        });
        window.addEventListener('mousemove', (event) => {
            if (!isResizing || !workspace) {
                return;
            }
            const rect = workspace.getBoundingClientRect();
            const nextHeight = event.clientY - rect.top;
            clampListHeight(nextHeight);
        });
        window.addEventListener('mouseup', () => {
            if (!isResizing) {
                return;
            }
            isResizing = false;
            document.body.classList.remove('is-mail-resizing');
        });
        window.addEventListener('resize', () => {
            if (!previewEnabled) {
                return;
            }
            if (!listPane.style.height && currentListHeight <= 0) {
                return;
            }
            const nextHeight = parseFloat(listPane.style.height || String(currentListHeight));
            clampListHeight(Number.isFinite(nextHeight) ? nextHeight : defaultListHeight());
        });
    }

    if (previewToggle) {
        previewToggle.addEventListener('click', () => {
            setPreviewEnabled(!previewEnabled);
        });
    }

    async function refreshFolder(button) {
        const accountId = button.getAttribute('data-account-id');
        const folderName = button.getAttribute('data-folder-name');
        const statsKey = button.getAttribute('data-stats-key');
        if (!accountId || !folderName || !statsKey) return;

        const originalLabel = button.textContent;
        button.disabled = true;
        button.textContent = 'Aktualisiere...';

        try {
            const body = new URLSearchParams();
            body.set('folder', folderName);
            const response = await fetch(`/mail/accounts/${accountId}/folders/refresh`, {
                method: 'POST',
                headers: {
                    ...csrfHeaders,
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: body.toString(),
            });
            const payload = await response.json();
            if (!payload || payload.success === false) {
                throw new Error((payload && payload.error) ? payload.error : 'Ordner konnte nicht aktualisiert werden.');
            }

            const totalBadge = Array.from(document.querySelectorAll('.js-folder-total'))
                .find((node) => node.getAttribute('data-folder-stats-key') === statsKey);
            const unreadBadge = Array.from(document.querySelectorAll('.js-folder-unread'))
                .find((node) => node.getAttribute('data-folder-stats-key') === statsKey);
            if (totalBadge) {
                totalBadge.textContent = String(payload.total_count ?? 0);
            }
            if (unreadBadge) {
                const unread = Number(payload.unread_count ?? 0);
                unreadBadge.textContent = `${unread} ungelesen`;
                unreadBadge.classList.remove('text-bg-primary', 'text-bg-secondary');
                unreadBadge.classList.add(unread > 0 ? 'text-bg-primary' : 'text-bg-secondary');
            }
        } catch (error) {
            alert(error.message || 'Ordner konnte nicht aktualisiert werden.');
        } finally {
            button.disabled = false;
            button.textContent = originalLabel;
        }
    }

    document.querySelectorAll('.js-refresh-folder').forEach((button) => {
        button.addEventListener('click', () => refreshFolder(button));
    });

    if (window.ModulonMailFrameAutosize && typeof window.ModulonMailFrameAutosize.init === 'function') {
        window.ModulonMailFrameAutosize.init(document);
    }
})();
</script>

<?php if ($message !== ''): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if ($selectedMainTab === 'accounts'): ?>
    <div class="card shadow-sm border-0 app-card mb-3">
        <div class="card-body">
            <h2 class="h6 text-uppercase text-body-secondary mb-3">Sicherheitsgrundlagen</h2>
            <ul class="mb-0 text-body-secondary">
                <li>IMAP/SMTP werden nur serverseitig verarbeitet.</li>
                <li>Zugangsdaten werden verschlüsselt gespeichert.</li>
                <li>Externe Bilder in HTML-Mails bleiben standardmäßig blockiert.</li>
                <li>Whitelist-Logik ist im Datenmodell vorbereitet.</li>
                <li>Sortierung und Gruppierung sind bewusst als getrennte Konzepte vorgesehen.</li>
            </ul>
        </div>
    </div>

    <div class="card shadow-sm border-0 app-card">
        <div class="card-body p-0">
            <?php if ($accounts === []): ?>
                <div class="p-4 text-body-secondary">Noch keine Mailkonten vorhanden.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 app-table">
                        <thead>
                        <tr>
                            <th class="ps-4">Name</th>
                            <th>E-Mail</th>
                            <th>IMAP</th>
                            <th>SMTP</th>
                            <th>Status</th>
                            <th class="pe-4 text-end">Aktionen</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($accounts as $account): ?>
                            <?php
                            $id = (int) ($account['id'] ?? 0);
                            $isActive = (int) ($account['is_active'] ?? 0) === 1;
                            ?>
                            <tr>
                                <td class="ps-4 fw-medium"><?= htmlspecialchars((string) ($account['display_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) ($account['email_address'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <code><?= htmlspecialchars((string) ($account['imap_host'] ?? ''), ENT_QUOTES, 'UTF-8') ?>:<?= (int) ($account['imap_port'] ?? 0) ?></code>
                                    <span class="badge text-bg-secondary ms-1"><?= htmlspecialchars((string) ($account['imap_encryption'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                </td>
                                <td>
                                    <code><?= htmlspecialchars((string) ($account['smtp_host'] ?? ''), ENT_QUOTES, 'UTF-8') ?>:<?= (int) ($account['smtp_port'] ?? 0) ?></code>
                                    <span class="badge text-bg-secondary ms-1"><?= htmlspecialchars((string) ($account['smtp_encryption'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                </td>
                                <td>
                                    <span class="badge <?= $isActive ? 'text-bg-success' : 'text-bg-secondary' ?>">
                                        <?= $isActive ? 'Aktiv' : 'Inaktiv' ?>
                                    </span>
                                </td>
                                <td class="pe-4 text-end">
                                    <form method="post" action="/mail/accounts/<?= $id ?>/toggle-active" class="d-inline">
                                        <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                        <button type="submit" class="btn btn-sm <?= $isActive ? 'btn-outline-warning' : 'btn-outline-success' ?>">
                                            <?= $isActive ? 'Deaktivieren' : 'Aktivieren' ?>
                                        </button>
                                    </form>
                                    <a href="/mail/accounts/<?= $id ?>/edit" class="btn btn-sm btn-outline-secondary ms-1">Bearbeiten</a>
                                    <form method="post" action="/mail/accounts/<?= $id ?>/delete" class="d-inline ms-1" onsubmit="return confirm('Mailkonto wirklich löschen?');">
                                        <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Löschen</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
