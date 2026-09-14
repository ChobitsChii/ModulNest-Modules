<?php

declare(strict_types=1);

namespace ModulNest\Mail;

use Modulon\Core\Env;
use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\SecretBox;
use Modulon\Core\Session;
use Modulon\Core\View;
use Modulon\Modules\Auth\AuthService;

final class MailController
{
    private const MESSAGE_LIST_LIMIT = 50;
    private const INDEX_FETCH_CHUNK_SIZE = 200;
    private const FLAG_REFRESH_WINDOW = 1000;

    private readonly MailTransportBackendInterface $transportBackend;

    /**
     * @var array<int, string>
     */
    private array $allowedImapEncryption = ['tls', 'ssl', 'starttls'];
    /**
     * @var array<int, string>
     */
    private array $allowedSmtpEncryption = ['tls', 'ssl', 'starttls'];

    public function __construct(
        private readonly MailRepository $mail,
        private readonly Session $session,
        private readonly ?AuthService $auth = null,
        ?MailTransportBackendInterface $transportBackend = null,
    ) {
        $this->transportBackend = $transportBackend ?? new WebklexSymfonyMailBackend();
    }

    public function index(Request $request): Response
    {
        return $this->renderAccountOverview($request);
    }

    public function subRoute(Request $request): Response
    {
        $path = trim($request->path(), '/');
        if ($path === 'mail' || $path === 'mail/') {
            return $this->renderAccountOverview($request);
        }
        if ($path === 'mail/accounts') {
            return $this->renderAccountOverview($request);
        }
        if ($path === 'mail/accounts/create') {
            return $this->renderAccountForm($request, null);
        }
        if ($path === 'mail/compose') {
            return $this->renderComposeView($request);
        }
        if (preg_match('/^mail\/accounts\/([0-9]+)\/edit$/', $path, $matches) === 1) {
            return $this->renderAccountForm($request, (int) $matches[1]);
        }
        if ($path === 'mail/message') {
            return $this->renderMessageView($request);
        }

        return new Response(View::render('errors/404', $this->viewData($request, ['title' => '404 Not Found'])), 404);
    }

    public function createAccount(Request $request): Response
    {
        $user = $this->auth?->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);
        $normalized = $this->normalizeAccountPayload($request, true);
        if ($normalized['error'] !== null) {
            $this->session->flash('mail_error', $normalized['error']);
            return Response::redirect('/mail/accounts/create');
        }

        $payload = $normalized['data'];
        $password = (string) $payload['password'];
        $key = Env::get('MAIL_CREDENTIAL_KEY', '');
        if ($key === '') {
            $this->session->flash('mail_error', 'MAIL_CREDENTIAL_KEY ist nicht gesetzt.');
            return Response::redirect('/mail/accounts/create');
        }

        try {
            $encrypted = SecretBox::encrypt($password, $key);
        } catch (\RuntimeException $exception) {
            $this->session->flash('mail_error', 'Zugangsdaten konnten nicht sicher gespeichert werden.');
            return Response::redirect('/mail/accounts/create');
        }

        $payload['encrypted_password'] = $encrypted;
        unset($payload['password']);

        try {
            $this->mail->createAccount($userId, $payload);
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                $this->session->flash('mail_error', 'Dieses Mailkonto ist für den Benutzer bereits vorhanden.');
                return Response::redirect('/mail/accounts/create');
            }
            $this->session->flash('mail_error', 'Mailkonto konnte nicht gespeichert werden.');
            return Response::redirect('/mail/accounts/create');
        }
        $this->session->flash('mail_info', 'Mailkonto angelegt.');

        return Response::redirect('/mail/accounts');
    }

    public function accountPostSubRoute(Request $request): Response
    {
        $path = trim($request->path(), '/');
        if (preg_match('/^mail\/accounts\/([0-9]+)\/update$/', $path, $matches) === 1) {
            return $this->updateAccount($request, (int) $matches[1]);
        }
        if (preg_match('/^mail\/accounts\/([0-9]+)\/delete$/', $path, $matches) === 1) {
            return $this->deleteAccount($request, (int) $matches[1]);
        }
        if (preg_match('/^mail\/accounts\/([0-9]+)\/toggle-active$/', $path, $matches) === 1) {
            return $this->toggleAccountActive($request, (int) $matches[1]);
        }
        if (preg_match('/^mail\/accounts\/([0-9]+)\/favorites\/toggle$/', $path, $matches) === 1) {
            return $this->toggleFavoriteFolder($request, (int) $matches[1]);
        }
        if (preg_match('/^mail\/accounts\/([0-9]+)\/senders\/exclude$/', $path, $matches) === 1) {
            return $this->excludeSender($request, (int) $matches[1]);
        }
        if (preg_match('/^mail\/accounts\/([0-9]+)\/senders\/include$/', $path, $matches) === 1) {
            return $this->includeSender($request, (int) $matches[1]);
        }
        if (preg_match('/^mail\/accounts\/([0-9]+)\/folders\/refresh$/', $path, $matches) === 1) {
            return $this->refreshFolderIndex($request, (int) $matches[1]);
        }

        return new Response(View::render('errors/404', $this->viewData($request, ['title' => '404 Not Found'])), 404);
    }

    public function messagePostSubRoute(Request $request): Response
    {
        $path = trim($request->path(), '/');
        if ($path === 'mail/messages/send') {
            return $this->sendMessage($request);
        }
        if ($path === 'mail/messages/whitelist') {
            return $this->saveMessageWhitelistRule($request);
        }

        return new Response(View::render('errors/404', $this->viewData($request, ['title' => '404 Not Found'])), 404);
    }

    private function updateAccount(Request $request, int $accountId): Response
    {
        $user = $this->auth?->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);
        $existing = $this->mail->findAccountForUser($accountId, $userId);
        if ($existing === null) {
            $this->session->flash('mail_error', 'Mailkonto nicht gefunden.');
            return Response::redirect('/mail/accounts');
        }

        $normalized = $this->normalizeAccountPayload($request, false);
        if ($normalized['error'] !== null) {
            $this->session->flash('mail_error', $normalized['error']);
            return Response::redirect('/mail/accounts/' . $accountId . '/edit');
        }

        $payload = $normalized['data'];
        $password = (string) ($payload['password'] ?? '');
        unset($payload['password']);

        if ($password !== '') {
            $key = Env::get('MAIL_CREDENTIAL_KEY', '');
            if ($key === '') {
                $this->session->flash('mail_error', 'MAIL_CREDENTIAL_KEY ist nicht gesetzt.');
                return Response::redirect('/mail/accounts/' . $accountId . '/edit');
            }

            try {
                $payload['encrypted_password'] = SecretBox::encrypt($password, $key);
            } catch (\RuntimeException) {
                $this->session->flash('mail_error', 'Zugangsdaten konnten nicht sicher gespeichert werden.');
                return Response::redirect('/mail/accounts/' . $accountId . '/edit');
            }

            try {
                $this->mail->updateAccount($accountId, $userId, $payload);
            } catch (\PDOException $exception) {
                if ((string) $exception->getCode() === '23000') {
                    $this->session->flash('mail_error', 'Dieses Mailkonto ist für den Benutzer bereits vorhanden.');
                    return Response::redirect('/mail/accounts/' . $accountId . '/edit');
                }
                $this->session->flash('mail_error', 'Mailkonto konnte nicht gespeichert werden.');
                return Response::redirect('/mail/accounts/' . $accountId . '/edit');
            }
        } else {
            try {
                $this->mail->updateAccountWithoutPassword($accountId, $userId, $payload);
            } catch (\PDOException $exception) {
                if ((string) $exception->getCode() === '23000') {
                    $this->session->flash('mail_error', 'Dieses Mailkonto ist für den Benutzer bereits vorhanden.');
                    return Response::redirect('/mail/accounts/' . $accountId . '/edit');
                }
                $this->session->flash('mail_error', 'Mailkonto konnte nicht gespeichert werden.');
                return Response::redirect('/mail/accounts/' . $accountId . '/edit');
            }
        }

        $this->session->flash('mail_info', 'Mailkonto gespeichert.');
        return Response::redirect('/mail/accounts/' . $accountId . '/edit');
    }

    private function deleteAccount(Request $request, int $accountId): Response
    {
        $user = $this->auth?->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);
        $existing = $this->mail->findAccountForUser($accountId, $userId);
        if ($existing === null) {
            $this->session->flash('mail_error', 'Mailkonto nicht gefunden.');
            return Response::redirect('/mail/accounts');
        }

        $this->mail->deleteAccount($accountId, $userId);
        $this->session->flash('mail_info', 'Mailkonto gelöscht.');

        return Response::redirect('/mail/accounts');
    }

    private function toggleAccountActive(Request $request, int $accountId): Response
    {
        $user = $this->auth?->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);
        $existing = $this->mail->findAccountForUser($accountId, $userId);
        if ($existing === null) {
            $this->session->flash('mail_error', 'Mailkonto nicht gefunden.');
            return Response::redirect('/mail/accounts');
        }

        $current = (int) ($existing['is_active'] ?? 0) === 1;
        $this->mail->setAccountActiveState($accountId, $userId, !$current);
        $this->session->flash('mail_info', !$current ? 'Mailkonto aktiviert.' : 'Mailkonto deaktiviert.');

        return Response::redirect('/mail/accounts');
    }

    private function toggleFavoriteFolder(Request $request, int $accountId): Response
    {
        $user = $this->auth?->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);
        $account = $this->mail->findAccountForUser($accountId, $userId);
        if ($account === null) {
            $this->session->flash('mail_error', 'Mailkonto nicht gefunden.');
            return Response::redirect('/mail/accounts');
        }

        $folderName = trim((string) $request->input('folder_name', ''));
        $redirectSection = strtolower(trim((string) $request->input('section', 'workspace')));
        if (!in_array($redirectSection, ['workspace', 'accounts'], true)) {
            $redirectSection = 'workspace';
        }
        $redirectAccountId = (int) $request->input('account', '0');
        if ($redirectAccountId <= 0) {
            $redirectAccountId = $accountId;
        }
        $redirectFolder = trim((string) $request->input('folder', ''));
        $redirectSort = strtolower((string) $request->input('sort', 'date'));
        if (!in_array($redirectSort, ['date'], true)) {
            $redirectSort = 'date';
        }
        $redirectDirection = strtolower((string) $request->input('direction', 'desc'));
        if (!in_array($redirectDirection, ['asc', 'desc'], true)) {
            $redirectDirection = 'desc';
        }
        $redirectGroup = strtolower((string) $request->input('group', 'none'));
        if (!in_array($redirectGroup, ['none', 'sender'], true)) {
            $redirectGroup = 'none';
        }
        $redirectView = strtolower((string) $request->input('view', 'messages'));
        if (!in_array($redirectView, ['messages', 'senders'], true)) {
            $redirectView = 'messages';
        }
        $redirectSender = strtolower(trim((string) $request->input('sender', '')));
        $mode = strtolower(trim((string) $request->input('mode', 'toggle')));

        if ($folderName === '' || mb_strlen($folderName) > 255) {
            $this->session->flash('mail_error', 'Ordnername ist ungültig.');
            return Response::redirect(
                $this->accountsUrl(
                    $redirectAccountId,
                    $redirectFolder,
                    $redirectSort,
                    $redirectDirection,
                    $redirectGroup,
                    $redirectView,
                    $redirectSender,
                    0,
                    $redirectSection
                )
            );
        }

        $favoriteExists = $this->mail->favoriteFolderExists($userId, $accountId, $folderName);
        if ($mode === 'remove' && $favoriteExists) {
            $this->mail->removeFavoriteFolder($userId, $accountId, $folderName);
            $this->session->flash('mail_info', 'Favorit entfernt.');
        } elseif ($mode === 'add' && !$favoriteExists) {
            $this->mail->addFavoriteFolder($userId, $accountId, $folderName);
            $this->session->flash('mail_info', 'Favorit hinzugefügt.');
        } elseif ($mode === 'toggle' || $mode === '') {
            if ($favoriteExists) {
                $this->mail->removeFavoriteFolder($userId, $accountId, $folderName);
                $this->session->flash('mail_info', 'Favorit entfernt.');
            } else {
                $this->mail->addFavoriteFolder($userId, $accountId, $folderName);
                $this->session->flash('mail_info', 'Favorit hinzugefügt.');
            }
        }

        return Response::redirect(
            $this->accountsUrl(
                $redirectAccountId,
                $redirectFolder,
                $redirectSort,
                $redirectDirection,
                $redirectGroup,
                $redirectView,
                $redirectSender,
                0,
                $redirectSection
            )
        );
    }

    private function excludeSender(Request $request, int $accountId): Response
    {
        $user = $this->auth?->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);
        $account = $this->mail->findAccountForUser($accountId, $userId);
        if ($account === null) {
            $this->session->flash('mail_error', 'Mailkonto nicht gefunden.');
            return Response::redirect('/mail/accounts');
        }

        $folder = trim((string) $request->input('folder', ''));
        $senderKey = strtolower(trim((string) $request->input('sender_key', '')));
        $sort = strtolower((string) $request->input('sort', 'date'));
        $direction = strtolower((string) $request->input('direction', 'desc'));
        if (!in_array($sort, ['date'], true)) {
            $sort = 'date';
        }
        if (!in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'desc';
        }

        if ($folder === '' || $senderKey === '') {
            $this->session->flash('mail_error', 'Ungültiger Absender-Ausschluss.');
            return Response::redirect($this->accountsUrl($accountId, $folder, $sort, $direction, 'none', 'senders'));
        }

        $this->mail->addSenderExclusion($userId, $accountId, $folder, $senderKey);
        $this->session->flash('mail_info', 'Absender aus der Absender-Ansicht ausgeschlossen.');

        return Response::redirect($this->accountsUrl($accountId, $folder, $sort, $direction, 'none', 'senders'));
    }

    private function includeSender(Request $request, int $accountId): Response
    {
        $user = $this->auth?->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);
        $account = $this->mail->findAccountForUser($accountId, $userId);
        if ($account === null) {
            $this->session->flash('mail_error', 'Mailkonto nicht gefunden.');
            return Response::redirect('/mail/accounts');
        }

        $folder = trim((string) $request->input('folder', ''));
        $senderKey = strtolower(trim((string) $request->input('sender_key', '')));
        $sort = strtolower((string) $request->input('sort', 'date'));
        $direction = strtolower((string) $request->input('direction', 'desc'));
        if (!in_array($sort, ['date'], true)) {
            $sort = 'date';
        }
        if (!in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'desc';
        }

        if ($folder === '' || $senderKey === '') {
            $this->session->flash('mail_error', 'Ungültiger Absender-Einschluss.');
            return Response::redirect($this->accountsUrl($accountId, $folder, $sort, $direction, 'none', 'senders'));
        }

        $this->mail->removeSenderExclusion($userId, $accountId, $folder, $senderKey);
        $this->session->flash('mail_info', 'Absender wieder in der Absender-Ansicht eingeblendet.');

        return Response::redirect($this->accountsUrl($accountId, $folder, $sort, $direction, 'none', 'senders'));
    }

    private function refreshFolderIndex(Request $request, int $accountId): Response
    {
        $user = $this->auth?->currentUser();
        if ($user === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Nicht eingeloggt.'], 401);
        }

        $userId = (int) ($user['id'] ?? 0);
        $account = $this->mail->findAccountForUser($accountId, $userId);
        if ($account === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Mailkonto nicht gefunden.'], 404);
        }

        $folder = trim((string) $request->input('folder', ''));
        if ($folder === '') {
            return $this->jsonResponse(['success' => false, 'error' => 'Ordner fehlt.'], 422);
        }

        $syncError = $this->syncFolderMessageIndex($userId, $accountId, $account, $folder);
        $stats = $this->mail->getIndexedFolderStats($userId, $accountId, $folder);

        return $this->jsonResponse([
            'success' => $syncError === null,
            'folder' => $folder,
            'total_count' => (int) ($stats['total_count'] ?? 0),
            'unread_count' => (int) ($stats['unread_count'] ?? 0),
            'error' => $syncError,
        ], $syncError === null ? 200 : 200);
    }

    private function renderAccountOverview(Request $request): Response
    {
        $user = $this->auth?->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);
        $accounts = $this->mail->listAccountsForUser($userId);
        $favorites = $this->mail->listFavoriteFoldersForUser($userId);
        $favoriteMap = $this->buildFavoriteMap($favorites);
        $imapPrereqError = $this->imapRuntimeError();

        $accountFolders = [];
        $accountErrors = [];
        foreach ($accounts as $account) {
            $accountId = (int) ($account['id'] ?? 0);
            $isActive = (int) ($account['is_active'] ?? 0) === 1;
            if ($accountId <= 0 || !$isActive) {
                continue;
            }
            if ($imapPrereqError !== null) {
                $accountErrors[$accountId] = $imapPrereqError;
                continue;
            }

            $imapResult = $this->loadImapFoldersForAccount($account);
            if ($imapResult['error'] !== null) {
                $accountErrors[$accountId] = $imapResult['error'];
                continue;
            }

            $accountFolders[$accountId] = $imapResult['folders'];
        }

        $accountById = [];
        foreach ($accounts as $account) {
            $accountById[(int) ($account['id'] ?? 0)] = $account;
        }
        $folderStatsByAccount = [];
        foreach ($accounts as $account) {
            $accountId = (int) ($account['id'] ?? 0);
            if ($accountId <= 0) {
                continue;
            }
            $folderStatsByAccount[$accountId] = $this->mail->listIndexedFolderStatsForAccount($userId, $accountId);
        }

        $selectedAccountId = (int) $request->query('account', '0');
        if ($selectedAccountId <= 0 || !isset($accountById[$selectedAccountId])) {
            $selectedAccountId = $this->firstActiveAccountIdWithFolders($accounts, $accountFolders);
        }

        $selectedFolder = trim((string) $request->query('folder', ''));
        $availableFoldersForSelection = is_array($accountFolders[$selectedAccountId] ?? null)
            ? $accountFolders[$selectedAccountId]
            : [];
        if ($selectedFolder === '' || !in_array($selectedFolder, $availableFoldersForSelection, true)) {
            if (in_array('INBOX', $availableFoldersForSelection, true)) {
                $selectedFolder = 'INBOX';
            } else {
                $selectedFolder = (string) ($availableFoldersForSelection[0] ?? '');
            }
        }

        $sortBy = strtolower((string) $request->query('sort', 'date'));
        if (!in_array($sortBy, ['date'], true)) {
            $sortBy = 'date';
        }
        $sortDirection = strtolower((string) $request->query('direction', 'desc'));
        if (!in_array($sortDirection, ['asc', 'desc'], true)) {
            $sortDirection = 'desc';
        }
        $groupBy = strtolower((string) $request->query('group', 'none'));
        if (!in_array($groupBy, ['none', 'sender'], true)) {
            $groupBy = 'none';
        }
        $viewMode = strtolower((string) $request->query('view', 'messages'));
        if (!in_array($viewMode, ['messages', 'senders'], true)) {
            $viewMode = 'messages';
        }
        $isAccountsRoute = trim($request->path(), '/') === 'mail/accounts';
        $mainTab = $isAccountsRoute ? 'accounts' : 'workspace';
        $senderFilterKey = strtolower(trim((string) $request->query('sender', '')));
        if ($viewMode === 'messages' && $senderFilterKey !== '') {
            $groupBy = 'none';
        }

        $messages = [];
        $messageGroups = [];
        $messageError = '';
        $messageHasMore = false;
        $messageNextUntilUid = null;
        $messageCurrentUntilUid = null;
        $senderStats = [];
        $excludedSenderKeys = [];
        $messageUntilUid = (int) $request->query('until_uid', '0');
        if ($messageUntilUid <= 0) {
            $messageUntilUid = null;
        }
        if (
            $mainTab === 'workspace'
            &&
            $selectedAccountId > 0
            && $selectedFolder !== ''
            && isset($accountById[$selectedAccountId])
            && (int) ($accountById[$selectedAccountId]['is_active'] ?? 0) === 1
        ) {
            $syncStart = microtime(true);
            $syncError = $this->syncFolderMessageIndex(
                $userId,
                $selectedAccountId,
                $accountById[$selectedAccountId],
                $selectedFolder
            );
            $folderStatsByAccount[$selectedAccountId] = $this->mail->listIndexedFolderStatsForAccount($userId, $selectedAccountId);
            $syncDurationMs = (microtime(true) - $syncStart) * 1000.0;
            $this->logListFetchTiming($accountById[$selectedAccountId], $selectedFolder . ' [index-sync]', $syncDurationMs, 0);
            if ($syncError !== null) {
                $messageError = $this->firstNonEmptyString($messageError, 'Index-Sync: ' . $syncError . ' (Cache genutzt).');
            }

            if ($viewMode === 'messages') {
                $messageStart = microtime(true);
                $messageResult = $this->mail->listIndexedMessagesForFolder(
                    $userId,
                    $selectedAccountId,
                    $selectedFolder,
                    self::MESSAGE_LIST_LIMIT,
                    $messageUntilUid,
                    $sortDirection,
                    $senderFilterKey
                );
                $messageDurationMs = (microtime(true) - $messageStart) * 1000.0;
                $messages = $this->sortMessages($messageResult['messages'], $sortBy, $sortDirection);
                $messageHasMore = (bool) ($messageResult['has_more'] ?? false);
                $messageNextUntilUid = isset($messageResult['next_until_uid']) ? (int) $messageResult['next_until_uid'] : null;
                if ($messages !== []) {
                    $uids = array_map(static fn (array $entry): int => (int) ($entry['uid'] ?? 0), $messages);
                    $uids = array_values(array_filter($uids, static fn (int $uid): bool => $uid > 0));
                    if ($uids !== []) {
                        $messageCurrentUntilUid = $sortDirection === 'asc' ? max($uids) : min($uids);
                    }
                }
                if ($groupBy === 'sender' && $senderFilterKey === '') {
                    $messageGroups = $this->groupMessagesBySender($messages, $sortDirection);
                }
                $this->logListFetchTiming($accountById[$selectedAccountId], $selectedFolder, $messageDurationMs, count($messages));
            }

            if ($viewMode === 'senders') {
                $excludedSenderKeys = $this->mail->listExcludedSenderKeysForContext($userId, $selectedAccountId, $selectedFolder);
                $senderStart = microtime(true);
                $senderStats = $this->mail->listIndexedSenderStatsForFolder(
                    $userId,
                    $selectedAccountId,
                    $selectedFolder,
                    $excludedSenderKeys
                );
                $senderDurationMs = (microtime(true) - $senderStart) * 1000.0;
                $this->logListFetchTiming(
                    $accountById[$selectedAccountId],
                    $selectedFolder . ' [senders-cache]',
                    $senderDurationMs,
                    count($senderStats)
                );
            }
        }

        return new Response(View::render('@modulnest.mail/index', $this->viewData($request, [
            'title' => $mainTab === 'accounts' ? 'Mail - Konten & Ordner' : 'Mail - Arbeitsbereich',
            'mail_section' => $mainTab === 'accounts' ? 'accounts' : 'workspace',
            'accounts' => $accounts,
            'favorite_folders' => $favorites,
            'favorite_map' => $favoriteMap,
            'account_folders' => $accountFolders,
            'account_errors' => $accountErrors,
            'folder_stats_by_account' => $folderStatsByAccount,
            'selected_account_id' => $selectedAccountId,
            'selected_folder' => $selectedFolder,
            'selected_sort' => $sortBy,
            'selected_direction' => $sortDirection,
            'selected_group' => $groupBy,
            'selected_view_mode' => $viewMode,
            'selected_main_tab' => $mainTab,
            'selected_sender_key' => $senderFilterKey,
            'message_list_limit' => self::MESSAGE_LIST_LIMIT,
            'message_has_more' => $messageHasMore,
            'message_next_until_uid' => $messageNextUntilUid,
            'message_until_uid' => $messageUntilUid,
            'message_current_until_uid' => $messageCurrentUntilUid,
            'messages' => $messages,
            'message_groups' => $messageGroups,
            'sender_stats' => $senderStats,
            'excluded_sender_keys' => $excludedSenderKeys,
            'message_error' => $messageError,
            'mail_info' => $this->session->pullFlash('mail_info'),
            'mail_error' => $this->session->pullFlash('mail_error'),
            'imap_prereq_error' => $imapPrereqError,
        ])));
    }

    private function renderMessageView(Request $request): Response
    {
        $user = $this->auth?->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);
        $accountId = (int) $request->query('account', '0');
        $folder = trim((string) $request->query('folder', ''));
        $uid = (int) $request->query('uid', '0');
        $sort = strtolower((string) $request->query('sort', 'date'));
        $direction = strtolower((string) $request->query('direction', 'desc'));
        $group = strtolower((string) $request->query('group', 'none'));
        $viewMode = strtolower((string) $request->query('view', 'messages'));
        $senderKey = strtolower(trim((string) $request->query('sender', '')));
        $untilUid = (int) $request->query('until_uid', '0');
        $temporaryImageLoad = (string) $request->query('load_images', '0') === '1';
        $partial = (string) $request->query('partial', '0') === '1';

        if ($accountId <= 0 || $folder === '' || $uid <= 0) {
            $this->session->flash('mail_error', 'Ungültige Nachrichtenauswahl.');
            return Response::redirect('/mail/accounts');
        }

        $account = $this->mail->findAccountForUser($accountId, $userId);
        if ($account === null) {
            $this->session->flash('mail_error', 'Mailkonto nicht gefunden.');
            return Response::redirect('/mail/accounts');
        }

        $imapPrereqError = $this->imapRuntimeError();
        if ($imapPrereqError !== null) {
            $data = $this->viewData($request, [
                'title' => 'Nachricht',
                'mail_section' => 'workspace',
                'mail_error' => $imapPrereqError,
                'message_detail' => null,
                'message_content_mode' => 'text',
                'message_content_html' => '',
                'message_blocked_image_count' => 0,
                'message_images_allowed' => false,
                'message_images_temporarily_allowed' => false,
                'message_sender_whitelisted' => false,
                'message_domain_whitelisted' => false,
                'selected_account_id' => $accountId,
                'selected_folder' => $folder,
                'selected_uid' => $uid,
                'selected_sort' => $sort,
                'selected_direction' => $direction,
                'selected_group' => $group,
                'selected_view_mode' => $viewMode,
                'selected_sender_key' => $senderKey,
                'selected_until_uid' => $untilUid > 0 ? $untilUid : null,
            ]);
            if ($partial) {
                return new Response($this->renderTemplate('mail/partials/message-detail', $data));
            }

            return new Response(View::render('@modulnest.mail/message', $data));
        }

        $detailResult = $this->loadMessageDetailForUid($account, $folder, $uid);
        if ($detailResult['error'] !== null) {
            $data = $this->viewData($request, [
                'title' => 'Nachricht',
                'mail_section' => 'workspace',
                'mail_error' => $detailResult['error'],
                'message_detail' => null,
                'message_content_mode' => 'text',
                'message_content_html' => '',
                'message_blocked_image_count' => 0,
                'message_images_allowed' => false,
                'message_images_temporarily_allowed' => false,
                'message_sender_whitelisted' => false,
                'message_domain_whitelisted' => false,
                'selected_account_id' => $accountId,
                'selected_folder' => $folder,
                'selected_uid' => $uid,
                'selected_sort' => $sort,
                'selected_direction' => $direction,
                'selected_group' => $group,
                'selected_view_mode' => $viewMode,
                'selected_sender_key' => $senderKey,
                'selected_until_uid' => $untilUid > 0 ? $untilUid : null,
            ]);
            if ($partial) {
                return new Response($this->renderTemplate('mail/partials/message-detail', $data));
            }

            return new Response(View::render('@modulnest.mail/message', $data));
        }

        $messageDetail = $detailResult['message'];
        $senderEmail = strtolower(trim((string) ($messageDetail['sender_email'] ?? '')));
        $senderDomain = strtolower(trim((string) ($messageDetail['sender_domain'] ?? '')));
        $senderWhitelisted = $senderEmail !== '' && $this->mail->whitelistRuleExists($userId, 'sender', $senderEmail, true);
        $domainWhitelisted = $senderDomain !== '' && $this->mail->whitelistRuleExists($userId, 'domain', $senderDomain, true);
        $allowExternalImages = $temporaryImageLoad || $senderWhitelisted || $domainWhitelisted;

        $sanitizedContent = $this->prepareSafeMessageHtml(
            (string) ($messageDetail['html_body'] ?? ''),
            (string) ($messageDetail['plain_body'] ?? ''),
            $allowExternalImages
        );

        $data = $this->viewData($request, [
            'title' => 'Nachricht',
            'mail_section' => 'workspace',
            'mail_info' => $this->session->pullFlash('mail_info'),
            'mail_error' => $this->session->pullFlash('mail_error'),
            'message_detail' => $messageDetail,
            'message_content_mode' => $sanitizedContent['mode'],
            'message_content_html' => $sanitizedContent['html'],
            'message_blocked_image_count' => $sanitizedContent['blocked_external_images'],
            'message_images_allowed' => $allowExternalImages,
            'message_images_temporarily_allowed' => $temporaryImageLoad,
            'message_sender_whitelisted' => $senderWhitelisted,
            'message_domain_whitelisted' => $domainWhitelisted,
            'selected_account_id' => $accountId,
            'selected_folder' => $folder,
            'selected_uid' => $uid,
            'selected_sort' => $sort,
            'selected_direction' => $direction,
            'selected_group' => $group,
            'selected_view_mode' => $viewMode,
            'selected_sender_key' => $senderKey,
            'selected_until_uid' => $untilUid > 0 ? $untilUid : null,
        ]);
        if ($partial) {
            return new Response($this->renderTemplate('mail/partials/message-detail', $data));
        }

        return new Response(View::render('@modulnest.mail/message', $data));
    }

    private function saveMessageWhitelistRule(Request $request): Response
    {
        $user = $this->auth?->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);
        $accountId = (int) $request->input('account_id', '0');
        $folder = trim((string) $request->input('folder', ''));
        $uid = (int) $request->input('uid', '0');
        $sort = strtolower((string) $request->input('sort', 'date'));
        $direction = strtolower((string) $request->input('direction', 'desc'));
        $group = strtolower((string) $request->input('group', 'none'));
        $viewMode = strtolower((string) $request->input('view', 'messages'));
        $senderKey = strtolower(trim((string) $request->input('sender', '')));
        $untilUid = (int) $request->input('until_uid', '0');
        $scopeType = strtolower(trim((string) $request->input('scope_type', '')));
        $scopeValue = strtolower(trim((string) $request->input('scope_value', '')));

        if ($accountId <= 0 || $folder === '' || $uid <= 0) {
            $this->session->flash('mail_error', 'Ungültige Whitelist-Anfrage.');
            return Response::redirect('/mail/accounts');
        }

        $account = $this->mail->findAccountForUser($accountId, $userId);
        if ($account === null) {
            $this->session->flash('mail_error', 'Mailkonto nicht gefunden.');
            return Response::redirect('/mail/accounts');
        }

        if (!in_array($scopeType, ['sender', 'domain'], true)) {
            $this->session->flash('mail_error', 'Ungültiger Whitelist-Typ.');
            return Response::redirect($this->messageUrl($accountId, $folder, $uid, $sort, $direction, $group, $viewMode, $senderKey, false, $untilUid));
        }

        if ($scopeType === 'sender') {
            if (!filter_var($scopeValue, FILTER_VALIDATE_EMAIL)) {
                $this->session->flash('mail_error', 'Ungültige Absender-Adresse für Whitelist.');
                return Response::redirect($this->messageUrl($accountId, $folder, $uid, $sort, $direction, $group, $viewMode, $senderKey, false, $untilUid));
            }
        } else {
            if (!$this->isValidHost($scopeValue)) {
                $this->session->flash('mail_error', 'Ungültige Domain für Whitelist.');
                return Response::redirect($this->messageUrl($accountId, $folder, $uid, $sort, $direction, $group, $viewMode, $senderKey, false, $untilUid));
            }
        }

        $this->mail->addWhitelistRule($userId, $scopeType, $scopeValue, true);
        $this->session->flash('mail_info', $scopeType === 'sender'
            ? 'Externe Bilder für diesen Absender dauerhaft erlaubt.'
            : 'Externe Bilder für diese Domain dauerhaft erlaubt.');

        return Response::redirect($this->messageUrl($accountId, $folder, $uid, $sort, $direction, $group, $viewMode, $senderKey, false, $untilUid));
    }

    private function renderAccountForm(Request $request, ?int $accountId): Response
    {
        $user = $this->auth?->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);
        $account = null;
        if ($accountId !== null) {
            $account = $this->mail->findAccountForUser($accountId, $userId);
            if ($account === null) {
                return new Response(View::render('errors/404', $this->viewData($request, ['title' => '404 Not Found'])), 404);
            }
        }

        return new Response(View::render('@modulnest.mail/account-form', $this->viewData($request, [
            'title' => $accountId === null ? 'Mailkonto hinzufügen' : 'Mailkonto bearbeiten',
            'mail_section' => 'accounts',
            'account' => $account,
            'mail_info' => $this->session->pullFlash('mail_info'),
            'mail_error' => $this->session->pullFlash('mail_error'),
            'imap_encryptions' => $this->allowedImapEncryption,
            'smtp_encryptions' => $this->allowedSmtpEncryption,
        ])));
    }

    private function renderComposeView(Request $request): Response
    {
        $user = $this->auth?->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);
        $accounts = array_values(array_filter(
            $this->mail->listAccountsForUser($userId),
            static fn (array $account): bool => (int) ($account['is_active'] ?? 0) === 1
        ));

        $selectedAccountId = (int) $request->query('account', '0');
        if ($selectedAccountId <= 0 && $accounts !== []) {
            $selectedAccountId = (int) ($accounts[0]['id'] ?? 0);
        }

        $replyFolder = trim((string) $request->query('folder', ''));
        $replyUid = (int) $request->query('reply_uid', '0');
        $replyDetail = null;
        $defaultTo = '';
        $defaultSubject = '';
        $defaultBody = '';
        $defaultInReplyTo = '';
        $defaultReferences = '';
        $replyContextError = '';

        if ($replyUid > 0 && $selectedAccountId > 0 && $replyFolder !== '') {
            if ($this->imapRuntimeError() !== null) {
                $replyContextError = (string) $this->imapRuntimeError();
            } else {
                $account = $this->mail->findAccountForUser($selectedAccountId, $userId);
                if ($account !== null) {
                    $detailResult = $this->loadMessageDetailForUid($account, $replyFolder, $replyUid);
                    if ($detailResult['error'] === null) {
                        $replyDetail = $detailResult['message'];
                        $defaultTo = (string) ($replyDetail['sender_email'] ?? '');
                        $subject = trim((string) ($replyDetail['subject'] ?? ''));
                        if ($subject !== '' && !preg_match('/^re:/i', $subject)) {
                            $subject = 'Re: ' . $subject;
                        } elseif ($subject === '') {
                            $subject = 'Re: (ohne Betreff)';
                        }
                        $defaultSubject = $subject;

                        $quoted = trim((string) ($replyDetail['plain_body'] ?? ''));
                        if ($quoted === '') {
                            $quoted = strip_tags((string) ($replyDetail['html_body'] ?? ''));
                        }
                        $quoted = trim($quoted);
                        if ($quoted !== '') {
                            $quotedLines = array_map(static fn (string $line): string => '> ' . $line, preg_split('/\R/', $quoted) ?: []);
                            $defaultBody = "\n\n" . implode("\n", $quotedLines);
                        }

                        $defaultInReplyTo = trim((string) ($replyDetail['message_id'] ?? ''));
                        $defaultReferences = $defaultInReplyTo;
                    } else {
                        $replyContextError = (string) $detailResult['error'];
                    }
                }
            }
        }

        return new Response(View::render('@modulnest.mail/compose', $this->viewData($request, [
            'title' => 'Neue E-Mail',
            'mail_section' => 'compose',
            'accounts' => $accounts,
            'selected_account_id' => $selectedAccountId,
            'reply_folder' => $replyFolder,
            'reply_uid' => $replyUid,
            'reply_detail' => $replyDetail,
            'compose_defaults' => [
                'to' => $defaultTo,
                'cc' => '',
                'bcc' => '',
                'subject' => $defaultSubject,
                'body_plain' => $defaultBody,
                'body_html' => '',
                'in_reply_to' => $defaultInReplyTo,
                'references' => $defaultReferences,
            ],
            'mail_info' => $this->session->pullFlash('mail_info'),
            'mail_error' => $this->firstNonEmptyString((string) $this->session->pullFlash('mail_error'), $replyContextError),
            'imap_prereq_error' => $this->imapRuntimeError(),
        ])));
    }

    private function sendMessage(Request $request): Response
    {
        $user = $this->auth?->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);
        $normalized = $this->normalizeComposePayload($request);
        if ($normalized['error'] !== null) {
            $this->session->flash('mail_error', $normalized['error']);
            return Response::redirect('/mail/compose?account=' . (int) $request->input('account_id', '0'));
        }

        $payload = $normalized['data'];
        $accountId = (int) $payload['account_id'];
        $account = $this->mail->findAccountForUser($accountId, $userId);
        if ($account === null || (int) ($account['is_active'] ?? 0) !== 1) {
            $this->session->flash('mail_error', 'Versandkonto nicht gefunden oder inaktiv.');
            return Response::redirect('/mail/compose');
        }

        $connectionState = $this->resolveTransportConnection($account);
        if ($connectionState['error'] !== null) {
            $this->session->flash('mail_error', $connectionState['error']);
            return Response::redirect('/mail/compose?account=' . $accountId);
        }

        try {
            $this->transportBackend->sendMessage((array) $connectionState['connection'], $payload);
        } catch (\RuntimeException $exception) {
            $this->session->flash('mail_error', $exception->getMessage());
            return Response::redirect('/mail/compose?account=' . $accountId);
        }

        $this->session->flash('mail_info', 'E-Mail wurde gesendet.');
        return Response::redirect('/mail?account=' . $accountId . '&folder=INBOX');
    }

    /**
     * @return array{data: array<string, mixed>, error: ?string}
     */
    private function normalizeComposePayload(Request $request): array
    {
        $accountId = (int) $request->input('account_id', '0');
        $toRaw = trim((string) $request->input('to', ''));
        $ccRaw = trim((string) $request->input('cc', ''));
        $bccRaw = trim((string) $request->input('bcc', ''));
        $subject = trim((string) $request->input('subject', ''));
        $bodyPlain = trim((string) $request->input('body_plain', ''));
        $bodyHtml = trim((string) $request->input('body_html', ''));
        $inReplyTo = trim((string) $request->input('in_reply_to', ''));
        $references = trim((string) $request->input('references', ''));

        if ($accountId <= 0) {
            return ['data' => [], 'error' => 'Bitte ein Versandkonto auswählen.'];
        }

        $to = $this->parseAddressList($toRaw);
        $cc = $this->parseAddressList($ccRaw);
        $bcc = $this->parseAddressList($bccRaw);
        if ($to === [] && $cc === [] && $bcc === []) {
            return ['data' => [], 'error' => 'Bitte mindestens einen Empfänger angeben.'];
        }

        if ($subject === '') {
            $subject = '(ohne Betreff)';
        }
        if (mb_strlen($subject) > 255) {
            return ['data' => [], 'error' => 'Betreff ist zu lang (max. 255 Zeichen).'];
        }

        if ($bodyPlain === '' && $bodyHtml === '') {
            return ['data' => [], 'error' => 'Bitte Nachrichtentext eingeben.'];
        }
        if ($bodyPlain === '' && $bodyHtml !== '') {
            $bodyPlain = trim(strip_tags($bodyHtml));
        }

        if ($inReplyTo !== '' && !$this->isSafeMessageIdHeader($inReplyTo)) {
            $inReplyTo = '';
        }
        if ($references !== '' && !$this->isSafeMessageIdHeader($references)) {
            $references = '';
        }

        return [
            'data' => [
                'account_id' => $accountId,
                'to' => $to,
                'cc' => $cc,
                'bcc' => $bcc,
                'subject' => $subject,
                'body_plain' => $bodyPlain,
                'body_html' => $bodyHtml,
                'in_reply_to' => $inReplyTo,
                'references' => $references,
            ],
            'error' => null,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function parseAddressList(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        $addresses = [];
        foreach ($parts as $part) {
            $candidate = trim($part, " \t\n\r\0\x0B<>");
            if ($candidate === '') {
                continue;
            }
            $email = strtolower($candidate);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $addresses[$email] = $email;
        }

        return array_values($addresses);
    }

    private function isSafeMessageIdHeader(string $value): bool
    {
        if ($value === '' || mb_strlen($value) > 1000) {
            return false;
        }
        return preg_match('/^[<>@a-zA-Z0-9._\\-\\s]+$/', $value) === 1;
    }

    /**
     * @return array{data: array<string, mixed>, error: ?string}
     */
    private function normalizeAccountPayload(Request $request, bool $passwordRequired): array
    {
        $displayName = trim((string) $request->input('display_name', ''));
        $emailAddress = trim(strtolower((string) $request->input('email_address', '')));
        $imapHost = trim((string) $request->input('imap_host', ''));
        $imapPort = (int) $request->input('imap_port', '993');
        $imapEncryption = strtolower(trim((string) $request->input('imap_encryption', 'tls')));
        $imapUsername = trim((string) $request->input('imap_username', ''));
        $smtpHost = trim((string) $request->input('smtp_host', ''));
        $smtpPort = (int) $request->input('smtp_port', '587');
        $smtpEncryption = strtolower(trim((string) $request->input('smtp_encryption', 'tls')));
        $smtpUsername = trim((string) $request->input('smtp_username', ''));
        $password = (string) $request->input('password', '');
        $isActive = $request->input('is_active') === '1' ? 1 : 0;

        if ($displayName === '' || mb_strlen($displayName) > 120) {
            return ['data' => [], 'error' => 'Bitte einen Anzeigenamen mit maximal 120 Zeichen angeben.'];
        }
        if (!filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
            return ['data' => [], 'error' => 'Bitte eine gültige E-Mail-Adresse angeben.'];
        }

        if (!$this->isValidHost($imapHost) || !$this->isValidHost($smtpHost)) {
            return ['data' => [], 'error' => 'IMAP/SMTP Host ist ungültig.'];
        }
        if ($imapPort < 1 || $imapPort > 65535 || $smtpPort < 1 || $smtpPort > 65535) {
            return ['data' => [], 'error' => 'IMAP/SMTP Port ist ungültig.'];
        }
        if (!in_array($imapEncryption, $this->allowedImapEncryption, true)) {
            return ['data' => [], 'error' => 'IMAP-Verschlüsselung ist ungültig.'];
        }
        if (!in_array($smtpEncryption, $this->allowedSmtpEncryption, true)) {
            return ['data' => [], 'error' => 'SMTP-Verschlüsselung ist ungültig.'];
        }
        if ($imapUsername === '' || $smtpUsername === '') {
            return ['data' => [], 'error' => 'IMAP- und SMTP-Benutzername sind erforderlich.'];
        }
        if ($passwordRequired && $password === '') {
            return ['data' => [], 'error' => 'Passwort ist erforderlich.'];
        }

        return [
            'data' => [
                'display_name' => mb_substr($displayName, 0, 120),
                'email_address' => $emailAddress,
                'imap_host' => mb_substr($imapHost, 0, 190),
                'imap_port' => $imapPort,
                'imap_encryption' => $imapEncryption,
                'imap_username' => mb_substr($imapUsername, 0, 190),
                'smtp_host' => mb_substr($smtpHost, 0, 190),
                'smtp_port' => $smtpPort,
                'smtp_encryption' => $smtpEncryption,
                'smtp_username' => mb_substr($smtpUsername, 0, 190),
                'password' => $password,
                'is_active' => $isActive,
            ],
            'error' => null,
        ];
    }

    private function isValidHost(string $host): bool
    {
        if ($host === '' || mb_strlen($host) > 190) {
            return false;
        }

        return preg_match('/^[a-z0-9.-]+$/i', $host) === 1;
    }

    /**
     * @param array<int, array<string, mixed>> $favorites
     * @return array<int, array<string, bool>>
     */
    private function buildFavoriteMap(array $favorites): array
    {
        $map = [];
        foreach ($favorites as $favorite) {
            $accountId = (int) ($favorite['mail_account_id'] ?? 0);
            $folderName = (string) ($favorite['folder_name'] ?? '');
            if ($accountId <= 0 || $folderName === '') {
                continue;
            }
            $map[$accountId][$folderName] = true;
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $account
     * @return array{folders: array<int, string>, error: ?string}
     */
    private function loadImapFoldersForAccount(array $account): array
    {
        $imapPrereqError = $this->imapRuntimeError();
        if ($imapPrereqError !== null) {
            return ['folders' => [], 'error' => $imapPrereqError];
        }

        $connectionState = $this->resolveTransportConnection($account);
        if ($connectionState['error'] !== null) {
            return ['folders' => [], 'error' => $connectionState['error']];
        }

        try {
            return [
                'folders' => $this->transportBackend->listFolders((array) $connectionState['connection']),
                'error' => null,
            ];
        } catch (\RuntimeException $exception) {
            return ['folders' => [], 'error' => $this->sanitizeImapError($exception->getMessage())];
        }
    }

    private function sanitizeImapError(string $message): string
    {
        $sanitized = str_replace(["\r", "\n"], ' ', trim($message));
        if ($sanitized === '') {
            return 'IMAP-Fehler.';
        }

        $sanitized = preg_replace('/\{[^}]*\}/', '{server}', $sanitized) ?? $sanitized;
        return mb_substr($sanitized, 0, 220);
    }

    /**
     * @param array<int, array<string, mixed>> $accounts
     * @param array<int, array<int, string>> $accountFolders
     */
    private function firstActiveAccountIdWithFolders(array $accounts, array $accountFolders): int
    {
        foreach ($accounts as $account) {
            $accountId = (int) ($account['id'] ?? 0);
            $isActive = (int) ($account['is_active'] ?? 0) === 1;
            if (!$isActive || $accountId <= 0) {
                continue;
            }
            if (is_array($accountFolders[$accountId] ?? null) && $accountFolders[$accountId] !== []) {
                return $accountId;
            }
        }
        foreach ($accounts as $account) {
            $accountId = (int) ($account['id'] ?? 0);
            $isActive = (int) ($account['is_active'] ?? 0) === 1;
            if ($isActive && $accountId > 0) {
                return $accountId;
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $account
     * @return array{messages: array<int, array<string, mixed>>, has_more: bool, next_until_uid: ?int, error: ?string}
     */
    private function loadMessagesForFolder(
        array $account,
        string $folderName,
        int $limit = self::MESSAGE_LIST_LIMIT,
        ?int $untilUid = null,
        string $direction = 'desc'
    ): array
    {
        $imapPrereqError = $this->imapRuntimeError();
        if ($imapPrereqError !== null) {
            return ['messages' => [], 'has_more' => false, 'next_until_uid' => null, 'error' => $imapPrereqError];
        }

        $connectionState = $this->resolveTransportConnection($account);
        if ($connectionState['error'] !== null) {
            return ['messages' => [], 'has_more' => false, 'next_until_uid' => null, 'error' => $connectionState['error']];
        }

        try {
            $result = $this->transportBackend->listMessages(
                (array) $connectionState['connection'],
                $folderName,
                $limit,
                $untilUid,
                $direction
            );
            return [
                'messages' => is_array($result['messages'] ?? null) ? $result['messages'] : [],
                'has_more' => (bool) ($result['has_more'] ?? false),
                'next_until_uid' => isset($result['next_until_uid']) ? (int) $result['next_until_uid'] : null,
                'error' => null,
            ];
        } catch (\RuntimeException $exception) {
            return ['messages' => [], 'has_more' => false, 'next_until_uid' => null, 'error' => $this->sanitizeImapError($exception->getMessage())];
        }
    }

    /**
     * @param array<string, mixed> $account
     * @param array<int, string> $excludedSenderKeys
     * @return array{senders: array<int, array<string, mixed>>, error: ?string}
     */
    private function loadSenderStatsForFolder(array $account, string $folderName, array $excludedSenderKeys = []): array
    {
        $imapPrereqError = $this->imapRuntimeError();
        if ($imapPrereqError !== null) {
            return ['senders' => [], 'error' => $imapPrereqError];
        }

        $connectionState = $this->resolveTransportConnection($account);
        if ($connectionState['error'] !== null) {
            return ['senders' => [], 'error' => $connectionState['error']];
        }

        try {
            return [
                'senders' => $this->transportBackend->listSenderStats(
                    (array) $connectionState['connection'],
                    $folderName,
                    $excludedSenderKeys
                ),
                'error' => null,
            ];
        } catch (\RuntimeException $exception) {
            return ['senders' => [], 'error' => $this->sanitizeImapError($exception->getMessage())];
        }
    }

    /**
     * @param array<string, mixed> $account
     * @return array{messages: array<int, array<string, mixed>>, has_more: bool, next_until_uid: ?int, error: ?string}
     */
    private function loadMessagesForSender(
        array $account,
        string $folderName,
        string $senderKey,
        int $limit = self::MESSAGE_LIST_LIMIT,
        ?int $untilUid = null,
        string $direction = 'desc'
    ): array {
        $imapPrereqError = $this->imapRuntimeError();
        if ($imapPrereqError !== null) {
            return ['messages' => [], 'has_more' => false, 'next_until_uid' => null, 'error' => $imapPrereqError];
        }

        $connectionState = $this->resolveTransportConnection($account);
        if ($connectionState['error'] !== null) {
            return ['messages' => [], 'has_more' => false, 'next_until_uid' => null, 'error' => $connectionState['error']];
        }

        try {
            $result = $this->transportBackend->listMessagesBySender(
                (array) $connectionState['connection'],
                $folderName,
                $senderKey,
                $limit,
                $untilUid,
                $direction
            );
            return [
                'messages' => is_array($result['messages'] ?? null) ? $result['messages'] : [],
                'has_more' => (bool) ($result['has_more'] ?? false),
                'next_until_uid' => isset($result['next_until_uid']) ? (int) $result['next_until_uid'] : null,
                'error' => null,
            ];
        } catch (\RuntimeException $exception) {
            return ['messages' => [], 'has_more' => false, 'next_until_uid' => null, 'error' => $this->sanitizeImapError($exception->getMessage())];
        }
    }

    /**
     * @param array<string, mixed> $account
     */
    private function syncFolderMessageIndex(int $userId, int $accountId, array $account, string $folderName): ?string
    {
        $imapPrereqError = $this->imapRuntimeError();
        if ($imapPrereqError !== null) {
            return $imapPrereqError;
        }

        $connectionState = $this->resolveTransportConnection($account);
        if ($connectionState['error'] !== null) {
            return (string) $connectionState['error'];
        }
        $connection = (array) ($connectionState['connection'] ?? []);

        try {
            $remoteUids = $this->transportBackend->listFolderUids($connection, $folderName);
        } catch (\RuntimeException $exception) {
            return $this->sanitizeImapError($exception->getMessage());
        }

        $localUids = $this->mail->listIndexedUidsForFolder($userId, $accountId, $folderName);

        $remoteSet = [];
        foreach ($remoteUids as $uid) {
            if ($uid > 0) {
                $remoteSet[$uid] = true;
            }
        }
        $localSet = [];
        foreach ($localUids as $uid) {
            if ($uid > 0) {
                $localSet[$uid] = true;
            }
        }

        $missingUids = [];
        foreach ($remoteSet as $uid => $_) {
            if (!isset($localSet[$uid])) {
                $missingUids[] = (int) $uid;
            }
        }

        $removedUids = [];
        foreach ($localSet as $uid => $_) {
            if (!isset($remoteSet[$uid])) {
                $removedUids[] = (int) $uid;
            }
        }

        if ($removedUids !== []) {
            foreach (array_chunk($removedUids, 500) as $chunk) {
                $this->mail->deleteIndexedUidsForFolder($userId, $accountId, $folderName, $chunk);
            }
        }

        if ($missingUids !== []) {
            foreach (array_chunk($missingUids, self::INDEX_FETCH_CHUNK_SIZE) as $chunk) {
                try {
                    $metadata = $this->transportBackend->fetchMessageMetadata($connection, $folderName, $chunk);
                } catch (\RuntimeException $exception) {
                    return $this->sanitizeImapError($exception->getMessage());
                }
                if ($metadata === []) {
                    continue;
                }
                $this->mail->upsertMessageIndexBatch($userId, $accountId, $folderName, array_values($metadata));
            }
        }

        if ($remoteUids !== []) {
            $uidsForFlagRefresh = $missingUids;
            $recentWindow = array_slice($remoteUids, -self::FLAG_REFRESH_WINDOW);
            foreach ($recentWindow as $uid) {
                $uidInt = (int) $uid;
                if ($uidInt > 0) {
                    $uidsForFlagRefresh[] = $uidInt;
                }
            }
            $uidsForFlagRefresh = array_values(array_unique($uidsForFlagRefresh));
            if ($uidsForFlagRefresh !== []) {
                $flagsByUid = [];
                foreach (array_chunk($uidsForFlagRefresh, 500) as $chunk) {
                    try {
                        $partialFlags = $this->transportBackend->fetchMessageReadFlags($connection, $folderName, $chunk);
                    } catch (\RuntimeException $exception) {
                        return $this->sanitizeImapError($exception->getMessage());
                    }
                    foreach ($partialFlags as $uid => $isRead) {
                        $flagsByUid[(int) $uid] = (bool) $isRead;
                    }
                }
                $this->mail->updateIndexedReadFlagsForFolder($userId, $accountId, $folderName, $flagsByUid);
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    private function sortMessages(array $messages, string $sortBy, string $direction): array
    {
        if ($sortBy !== 'date') {
            return $messages;
        }

        usort($messages, static function (array $a, array $b) use ($direction): int {
            $cmp = ((int) ($a['timestamp'] ?? 0)) <=> ((int) ($b['timestamp'] ?? 0));
            if ($cmp === 0) {
                $cmp = ((int) ($a['uid'] ?? 0)) <=> ((int) ($b['uid'] ?? 0));
            }
            return $direction === 'asc' ? $cmp : -$cmp;
        });

        return $messages;
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    private function groupMessagesBySender(array $messages, string $direction): array
    {
        $groups = [];
        foreach ($messages as $message) {
            $key = (string) ($message['sender_key'] ?? 'unknown');
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'label' => (string) ($message['sender_label'] ?? 'Unbekannt'),
                    'latest_timestamp' => (int) ($message['timestamp'] ?? 0),
                    'messages' => [],
                ];
            }
            $groups[$key]['messages'][] = $message;
            $timestamp = (int) ($message['timestamp'] ?? 0);
            if ($timestamp > (int) $groups[$key]['latest_timestamp']) {
                $groups[$key]['latest_timestamp'] = $timestamp;
            }
        }

        $groupList = array_values($groups);
        usort($groupList, static function (array $a, array $b) use ($direction): int {
            $cmp = ((int) ($a['latest_timestamp'] ?? 0)) <=> ((int) ($b['latest_timestamp'] ?? 0));
            if ($cmp === 0) {
                $cmp = strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
            }
            return $direction === 'asc' ? $cmp : -$cmp;
        });

        foreach ($groupList as &$group) {
            $group['count'] = count((array) ($group['messages'] ?? []));
        }
        unset($group);

        return $groupList;
    }

    /**
     * @param array<string, mixed> $account
     * @return array{message: array<string, mixed>, error: ?string}
     */
    private function loadMessageDetailForUid(array $account, string $folderName, int $uid): array
    {
        $imapPrereqError = $this->imapRuntimeError();
        if ($imapPrereqError !== null) {
            return ['message' => [], 'error' => $imapPrereqError];
        }

        $connectionState = $this->resolveTransportConnection($account);
        if ($connectionState['error'] !== null) {
            return ['message' => [], 'error' => $connectionState['error']];
        }

        try {
            return [
                'message' => $this->transportBackend->getMessageDetail((array) $connectionState['connection'], $folderName, $uid),
                'error' => null,
            ];
        } catch (\RuntimeException $exception) {
            return ['message' => [], 'error' => $this->sanitizeImapError($exception->getMessage())];
        }
    }

    /**
     * @param array<string, mixed> $account
     * @return array{connection: array<string, mixed>|null, error: ?string}
     */
    private function resolveTransportConnection(array $account): array
    {
        $key = Env::get('MAIL_CREDENTIAL_KEY', '');
        if ($key === '') {
            return ['connection' => null, 'error' => 'MAIL_CREDENTIAL_KEY ist nicht gesetzt.'];
        }

        $encrypted = (string) ($account['encrypted_password'] ?? '');
        if ($encrypted === '') {
            return ['connection' => null, 'error' => 'Kein gespeichertes Passwort vorhanden.'];
        }

        try {
            $password = SecretBox::decrypt($encrypted, $key);
        } catch (\RuntimeException) {
            return ['connection' => null, 'error' => 'Gespeichertes Passwort konnte nicht entschlüsselt werden.'];
        }

        return [
            'connection' => [
                'imap_host' => (string) ($account['imap_host'] ?? ''),
                'imap_port' => (int) ($account['imap_port'] ?? 993),
                'imap_encryption' => strtolower((string) ($account['imap_encryption'] ?? 'tls')),
                'imap_username' => (string) ($account['imap_username'] ?? ''),
                'imap_password' => $password,
                'smtp_host' => (string) ($account['smtp_host'] ?? ''),
                'smtp_port' => (int) ($account['smtp_port'] ?? 587),
                'smtp_encryption' => strtolower((string) ($account['smtp_encryption'] ?? 'tls')),
                'smtp_username' => (string) ($account['smtp_username'] ?? ''),
                'smtp_password' => $password,
                'account_email' => (string) ($account['email_address'] ?? ''),
            ],
            'error' => null,
        ];
    }

    /**
     * @return array{mode: 'html'|'text', html: string, blocked_external_images: int}
     */
    private function prepareSafeMessageHtml(string $htmlBody, string $plainBody, bool $allowExternalImages): array
    {
        $htmlBody = trim($htmlBody);
        if ($htmlBody === '') {
            $safePlain = htmlspecialchars(trim($plainBody), ENT_QUOTES, 'UTF-8');
            if ($safePlain === '') {
                $safePlain = '(Kein darstellbarer Nachrichteninhalt)';
            }
            return [
                'mode' => 'text',
                'html' => '<div class="modulon-mail-content">' . nl2br($safePlain) . '</div>',
                'blocked_external_images' => 0,
            ];
        }

        $previousErrors = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $htmlBody);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        if (!$loaded) {
            $safePlain = htmlspecialchars(trim($plainBody), ENT_QUOTES, 'UTF-8');
            if ($safePlain === '') {
                $safePlain = htmlspecialchars($htmlBody, ENT_QUOTES, 'UTF-8');
            }
            return [
                'mode' => 'text',
                'html' => '<div class="modulon-mail-content">' . nl2br($safePlain) . '</div>',
                'blocked_external_images' => 0,
            ];
        }

        $blockedExternalImages = $this->sanitizeMailDocumentDom($dom, $allowExternalImages);
        $safeDocument = $this->buildIframeDocumentFromDom($dom, $allowExternalImages);

        return [
            'mode' => 'html',
            'html' => $safeDocument,
            'blocked_external_images' => $blockedExternalImages,
        ];
    }

    private function sanitizeMailDocumentDom(\DOMDocument $dom, bool $allowExternalImages): int
    {
        $blockedExternalImages = 0;
        $dangerousTags = [
            'script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet',
            'base', 'canvas', 'audio', 'video', 'source', 'track',
        ];
        $unwrapTags = ['noscript'];

        $nodes = [];
        foreach ($dom->getElementsByTagName('*') as $node) {
            $nodes[] = $node;
        }

        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($node->tagName);
            if (in_array($tag, $dangerousTags, true)) {
                if ($node->parentNode !== null) {
                    $node->parentNode->removeChild($node);
                }
                continue;
            }
            if (in_array($tag, $unwrapTags, true)) {
                if ($node->parentNode !== null) {
                    while ($node->firstChild !== null) {
                        $node->parentNode->insertBefore($node->firstChild, $node);
                    }
                    $node->parentNode->removeChild($node);
                }
                continue;
            }

            $attributes = [];
            foreach ($node->attributes as $attribute) {
                $attributes[] = $attribute;
            }
            foreach ($attributes as $attribute) {
                if (!$attribute instanceof \DOMAttr) {
                    continue;
                }
                $name = strtolower($attribute->name);
                if (str_starts_with($name, 'on')) {
                    $node->removeAttributeNode($attribute);
                    continue;
                }
                if (in_array($name, ['integrity', 'nonce'], true)) {
                    $node->removeAttributeNode($attribute);
                }
            }

            if ($tag === 'meta') {
                $httpEquiv = strtolower(trim((string) $node->getAttribute('http-equiv')));
                if ($httpEquiv === 'refresh' || $httpEquiv === 'content-security-policy') {
                    if ($node->parentNode !== null) {
                        $node->parentNode->removeChild($node);
                    }
                }
                continue;
            }

            if ($tag === 'style') {
                $css = (string) $node->textContent;
                $css = preg_replace('/@import\s+[^;]+;/i', '', $css) ?? $css;
                while ($node->firstChild !== null) {
                    $node->removeChild($node->firstChild);
                }
                $node->appendChild($dom->createTextNode($css));
                continue;
            }

            if ($tag === 'link') {
                $rel = strtolower(trim((string) $node->getAttribute('rel')));
                if (str_contains($rel, 'stylesheet') || str_contains($rel, 'preload') || str_contains($rel, 'modulepreload')) {
                    if ($node->parentNode !== null) {
                        $node->parentNode->removeChild($node);
                    }
                }
                continue;
            }

            if ($tag === 'a') {
                $href = trim((string) $node->getAttribute('href'));
                if (!$this->isSafeLinkHref($href)) {
                    $node->removeAttribute('href');
                } else {
                    $node->setAttribute('rel', 'noopener noreferrer ugc');
                    $node->setAttribute('target', '_blank');
                }
                continue;
            }

            if ($tag === 'form') {
                $action = trim((string) $node->getAttribute('action'));
                if ($action !== '' && !$this->isSafeLinkHref($action)) {
                    $node->removeAttribute('action');
                }
                $node->setAttribute('method', 'get');
                continue;
            }

            if ($tag === 'img') {
                $src = trim((string) $node->getAttribute('src'));
                if ($src !== '' && $this->isExternalUrl($src) && !$allowExternalImages) {
                    $blockedExternalImages++;
                }
            }
        }

        return $blockedExternalImages;
    }

    private function buildIframeDocumentFromDom(\DOMDocument $dom, bool $allowExternalImages): string
    {
        $html = $dom->getElementsByTagName('html')->item(0);
        if (!$html instanceof \DOMElement) {
            return $this->buildMinimalIframeFallback($dom->saveHTML() ?: '', $allowExternalImages);
        }

        $head = $dom->getElementsByTagName('head')->item(0);
        if (!$head instanceof \DOMElement) {
            $head = $dom->createElement('head');
            if ($html->firstChild !== null) {
                $html->insertBefore($head, $html->firstChild);
            } else {
                $html->appendChild($head);
            }
        }

        $charsetMeta = $dom->createElement('meta');
        $charsetMeta->setAttribute('charset', 'utf-8');
        $head->insertBefore($charsetMeta, $head->firstChild);

        $viewportMeta = $dom->createElement('meta');
        $viewportMeta->setAttribute('name', 'viewport');
        $viewportMeta->setAttribute('content', 'width=device-width, initial-scale=1');
        $head->appendChild($viewportMeta);

        $cspMeta = $dom->createElement('meta');
        $cspMeta->setAttribute('http-equiv', 'Content-Security-Policy');
        $cspMeta->setAttribute('content', $this->mailIframeCsp($allowExternalImages));
        $head->appendChild($cspMeta);

        $document = $dom->saveHTML() ?: '';
        $document = str_replace('<!--?xml encoding="utf-8" ?-->', '', $document);
        $document = ltrim($document);
        if (!str_starts_with(strtolower($document), '<!doctype')) {
            $document = "<!doctype html>\n" . $document;
        }

        return $document;
    }

    private function buildMinimalIframeFallback(string $htmlFragment, bool $allowExternalImages): string
    {
        return '<!doctype html>'
            . '<html><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta http-equiv="Content-Security-Policy" content="' . $this->mailIframeCsp($allowExternalImages) . '">'
            . '<style>html,body{margin:0;padding:0;}</style>'
            . '</head><body>'
            . $htmlFragment
            . '</body></html>';
    }

    private function mailIframeCsp(bool $allowExternalImages): string
    {
        $imgSrc = $allowExternalImages ? 'img-src http: https: data: cid:;' : 'img-src data: cid:;';
        return "default-src 'none'; script-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'; connect-src 'none'; style-src 'unsafe-inline'; font-src http: https: data:; " . $imgSrc;
    }

    private function isSafeLinkHref(string $href): bool
    {
        if ($href === '') {
            return false;
        }
        $parts = @parse_url($href);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        return in_array($scheme, ['http', 'https', 'mailto'], true);
    }

    private function isExternalUrl(string $value): bool
    {
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = @parse_url($value);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        return isset($parts['host']) && (string) $parts['host'] !== '';
    }

    private function messageUrl(
        int $accountId,
        string $folder,
        int $uid,
        string $sort,
        string $direction,
        string $group,
        string $viewMode = 'messages',
        string $senderKey = '',
        bool $loadImages = false,
        int $untilUid = 0
    ): string {
        $query = [
            'account' => $accountId,
            'folder' => $folder,
            'uid' => $uid,
            'sort' => $sort,
            'direction' => $direction,
            'group' => $group,
            'view' => in_array($viewMode, ['messages', 'senders'], true) ? $viewMode : 'messages',
        ];
        if ($senderKey !== '') {
            $query['sender'] = $senderKey;
        }
        if ($untilUid > 0) {
            $query['until_uid'] = $untilUid;
        }
        if ($loadImages) {
            $query['load_images'] = '1';
        }

        return '/mail/message?' . http_build_query($query);
    }

    private function accountsUrl(
        int $accountId,
        string $folder,
        string $sort = 'date',
        string $direction = 'desc',
        string $group = 'none',
        string $viewMode = 'messages',
        string $senderKey = '',
        int $untilUid = 0,
        string $section = 'workspace'
    ): string {
        $section = in_array($section, ['workspace', 'accounts'], true) ? $section : 'workspace';
        $basePath = $section === 'accounts' ? '/mail/accounts' : '/mail';
        $query = [
            'account' => $accountId > 0 ? $accountId : null,
            'folder' => $folder !== '' ? $folder : null,
            'sort' => $sort,
            'direction' => $direction,
            'group' => $group,
            'view' => in_array($viewMode, ['messages', 'senders'], true) ? $viewMode : 'messages',
        ];
        if ($senderKey !== '') {
            $query['sender'] = $senderKey;
        }
        if ($untilUid > 0) {
            $query['until_uid'] = $untilUid;
        }

        return $basePath . '?' . http_build_query($query);
    }

    private function imapRuntimeError(): ?string
    {
        if (extension_loaded('iconv')) {
            return null;
        }

        return 'IMAP-Funktionen sind nicht verfügbar: PHP-Extension iconv fehlt. Kontoverwaltung und SMTP-Versand bleiben nutzbar.';
    }

    private function firstNonEmptyString(string ...$values): string
    {
        foreach ($values as $value) {
            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $account
     */
    private function logListFetchTiming(array $account, string $folderName, float $durationMs, int $messageCount): void
    {
        if (!Env::getBool('APP_DEBUG', false)) {
            return;
        }

        $accountId = (int) ($account['id'] ?? 0);
        error_log(sprintf(
            '[mail-list] account=%d folder="%s" limit=%d count=%d duration_ms=%.1f',
            $accountId,
            $folderName,
            self::MESSAGE_LIST_LIMIT,
            $messageCount,
            $durationMs
        ));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderTemplate(string $template, array $data = []): string
    {
        $clean = trim($template, "/");
        if (str_starts_with($clean, "mail/")) {
            $clean = substr($clean, 5);
        }
        $templatePath = dirname(__DIR__) . "/views/" . $clean . ".php";
        if (!is_file($templatePath)) {
            return "";
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $templatePath;
        $output = ob_get_clean();

        return is_string($output) ? $output : "";
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(array $payload, int $status = 200): Response
    {
        return new Response(
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function viewData(Request $request, array $extra = []): array
    {
        $user = $this->auth?->currentUser();

        return array_merge([
            'current_path' => $request->path(),
            'auth' => [
                'is_authenticated' => $user !== null,
                'is_admin' => $this->auth?->isAdmin() ?? false,
                'user_name' => (string) ($user['name'] ?? ''),
            ],
        ], $extra);
    }
}
