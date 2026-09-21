<?php

declare(strict_types=1);

namespace ModulNest\MailClient;

use Modulon\Core\DateTimeFormatter;
use Modulon\Core\Env;
use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\SecretBox;
use Modulon\Core\Session;
use Modulon\Core\View;
use Modulon\Modules\Auth\AuthService;
use ModulNest\MailClient\Autoconfig\MailAutoconfigService;
use ModulNest\MailClient\Imap\ImapConnectionFactory;
use ModulNest\MailClient\Imap\ImapMessageFetcher;
use ModulNest\MailClient\Imap\ImapSyncService;
use ModulNest\MailClient\Repository\AccountRepository;
use ModulNest\MailClient\Repository\AliasRepository;

use ModulNest\MailClient\Repository\FolderCacheRepository;
use ModulNest\MailClient\Repository\MessageIndexRepository;
use ModulNest\MailClient\Security\MailBodySanitizer;
use ModulNest\MailClient\Smtp\SmtpSender;

/**
 * HTTP-Controller für den Mail-Client.
 *
 * Wichtige Designentscheidungen:
 * - CSRF-Token: kommt automatisch via View-Composer in alle Views ($csrf_token)
 * - Session::flash() = schreiben, Session::pullFlash() = lesen+löschen
 * - Request::input() = POST/JSON-Body, Request::query() = URL-Parameter
 * - JSON-Body wird automatisch by Request::fromGlobals() geparsed wenn Content-Type application/json
 */
final class MailClientController
{
    private const CRED_KEY_ENV = 'MAIL_CREDENTIAL_KEY';

    public function __construct(
        private readonly AccountRepository      $accounts,
        private readonly MessageIndexRepository $messageIndex,
        private readonly FolderCacheRepository  $folderCache,
        private readonly ImapSyncService        $syncService,
        private readonly ImapMessageFetcher     $messageFetcher,
        private readonly MailBodySanitizer      $sanitizer,
        private readonly SmtpSender             $smtpSender,
        private readonly Session                $session,
        private readonly MailAutoconfigService  $autoconfigService,
        private readonly ?AuthService           $auth = null,
        private ?\PDO                           $pdo = null,
        private ?AliasRepository                $aliasRepo = null,
    ) {
        if ($this->aliasRepo === null && $this->pdo !== null) {
            $this->aliasRepo = new AliasRepository($this->pdo);
        }
    }


    // ═════════════════════════════════════════════════════════════════════════
    // HTML-Routen
    // ═════════════════════════════════════════════════════════════════════════

    /** GET /mail-client */
    public function index(Request $request): Response
    {
        $user = $this->requireUser();
        if ($user === null) {
            return Response::redirect('/login');
        }
        $userId   = (int) $user['id'];
        $accounts = $this->accounts->listForUser($userId);
        foreach ($accounts as &$acc) {
            $inboxCache = $this->folderCache->findFolder((int) $acc['id'], 'INBOX');
            $acc['inbox_unseen'] = (int) ($inboxCache['unseen_messages'] ?? 0);
        }
        unset($acc);
        $success  = $this->session->pullFlash('mc_success');

        return new Response(View::render('@modulnest.mail-client/client', [
            'title'        => 'Mail-Client',
            'current_path' => '/mail-client',
            'accounts'     => $accounts,
            'mail_success' => $success,
            'csrf_token'   => (string) ($this->session->get('_csrf_token') ?? ''),
        ]));
    }

    /** GET /mail-client/accounts/create */
    public function accountCreateForm(Request $request): Response
    {
        $user = $this->requireUser();
        if ($user === null) {
            return Response::redirect('/login');
        }
        $error = $this->session->pullFlash('mc_error');
        $old   = (array) ($this->session->get('mc_old') ?? []);
        $this->session->remove('mc_old');

        $aliases = (array) ($this->session->get('mc_old_aliases') ?? []);
        $this->session->remove('mc_old_aliases');

        return new Response(View::render('@modulnest.mail-client/account-form', [
            'title'        => 'Mailkonto hinzufügen',
            'current_path' => '/mail-client/accounts/create',
            'account'      => null,
            'old'          => $old,
            'aliases'      => $aliases,
            'error'        => $error,
        ]));
    }

    /** GET /mail-client/accounts/* → /mail-client/accounts/{id}/edit */
    public function accountEditForm(Request $request): Response
    {
        $user = $this->requireUser();
        if ($user === null) {
            return Response::redirect('/login');
        }
        $userId    = (int) $user['id'];
        $accountId = $this->extractId($request->path(), '/mail-client/accounts/', '/edit');
        if ($accountId === null) {
            return $this->notFound($request);
        }
        $account = $this->accounts->findForUser($accountId, $userId);
        if ($account === null) {
            return $this->notFound($request);
        }

        $error = $this->session->pullFlash('mc_error');
        $aliases = $this->aliasRepo !== null
            ? $this->aliasRepo->listForAccount($accountId, $userId)
            : [];

        return new Response(View::render('@modulnest.mail-client/account-form', [
            'title'        => 'Mailkonto bearbeiten',
            'current_path' => '/mail-client/accounts/' . $accountId . '/edit',
            'account'      => $account,
            'old'          => [],
            'aliases'      => $aliases,
            'error'        => $error,
        ]));
    }

    /** GET /mail-client/message – Vollbild-Ansicht (neuer Tab) */
    public function messageFullView(Request $request): Response
    {
        $user = $this->requireUser();
        if ($user === null) {
            return Response::redirect('/login');
        }
        $userId    = (int) $user['id'];
        $accountId = (int) ($request->query('account') ?? $request->input('account') ?? '0');
        $folder    = (string) ($request->query('folder') ?? $request->input('folder') ?? 'INBOX');
        $uid       = (int) $request->query('uid', '0');

        if ($accountId === 0 || $uid === 0) {
            return $this->notFound($request);
        }

        $account = $this->accounts->findForUser($accountId, $userId);
        if ($account === null) {
            return $this->notFound($request);
        }

        $userTimezone = (string) ($user['timezone'] ?? 'Europe/Berlin');

        try {
            $password    = $this->decryptPassword((string) $account['encrypted_password']);
            $accountConn = array_merge($account, ['password' => $password]);
            $messageData = $this->messageFetcher->fetchByUid($accountConn, $folder, $uid, $userTimezone);
        } catch (\Throwable $e) {
            return new Response(View::render('@modulnest.mail-client/message-full', [
                'title'          => 'Fehler',
                'current_path'   => '/mail-client/message',
                'error'          => 'Nachricht konnte nicht geladen werden: ' . $e->getMessage(),
                'message'        => null,
                'account'        => $account,
                'allow_external' => false,
                'blocked_images' => 0,
            ]));
        }

        $userTimezone = (string) ($user['timezone'] ?? '');
        if (!empty($messageData['date_ts'])) {
            $messageData['date'] = DateTimeFormatter::formatUserDateTime('@' . $messageData['date_ts'], $userTimezone);
        } elseif (!empty($messageData['date'])) {
            $messageData['date'] = DateTimeFormatter::formatUserDateTime($messageData['date'], $userTimezone);
        }

        if (!empty($messageData['calendar_events'])) {
            $pdo = $this->getPdo();
            if ($pdo !== null) {
                try {
                    $checkTable = $pdo->query("SHOW TABLES LIKE 'calendar_appointments'")->fetchColumn();
                    if ($checkTable) {
                        $checkStmt = $pdo->prepare('SELECT id FROM calendar_appointments WHERE user_id = ? AND title = ? AND start_at = ? LIMIT 1');
                        foreach ($messageData['calendar_events'] as &$ev) {
                            $checkStmt->execute([$userId, $ev['title'], $ev['start_at']]);
                            $existingId = $checkStmt->fetchColumn();
                            $ev['already_in_calendar']     = (bool) $existingId;
                            $ev['calendar_appointment_id'] = $existingId ? (int) $existingId : null;
                        }
                        unset($ev);
                    }
                } catch (\Throwable) {}
            }
        }

        $allowExternal = $this->folderCache->isWhitelisted($userId, $messageData['from']);
        $rawHtml       = $messageData['html_body'] !== ''
            ? $messageData['html_body']
            : nl2br(htmlspecialchars($messageData['text_body'], ENT_QUOTES, 'UTF-8'));
        $sanitized                 = $this->sanitizer->sanitize($rawHtml, $allowExternal);
        $messageData['safe_html'] = $sanitized['html'];
        $messageData['folder']    = $folder;

        return new Response(View::render('@modulnest.mail-client/message-full', [
            'title'          => htmlspecialchars($messageData['subject'] ?: '(kein Betreff)', ENT_QUOTES, 'UTF-8') . ' – Mail-Client',
            'current_path'   => '/mail-client/message',
            'error'          => '',
            'message'        => $messageData,
            'account'        => $account,
            'allow_external' => $allowExternal,
            'blocked_images' => $sanitized['blocked_images'],
        ]));
    }

    /** GET /mail-client/compose */
    public function composeForm(Request $request): Response
    {
        $user = $this->requireUser();
        if ($user === null) {
            return Response::redirect('/login');
        }
        $userId   = (int) $user['id'];
        $accounts = $this->accounts->listForUser($userId);

        // Kein Konto vorhanden → Empty-State mit Hinweis und Konto-Erstell-Button
        if ($accounts === [] || count($accounts) === 0) {
            return new Response(View::render('@modulnest.mail-client/compose-empty', [
                'title'        => 'Kein Mail-Konto',
                'current_path' => '/mail-client/compose',
            ]));
        }

        $error    = $this->session->pullFlash('mc_error');
        $success  = $this->session->pullFlash('mc_success');

        $replyFromEmail = (string) ($request->query('from') ?? $request->query('to_addr') ?? '');
        $replySenderVal = (string) ($request->query('sender') ?? '');
        if ($this->aliasRepo !== null) {
            foreach ($accounts as &$acc) {
                $acc['aliases'] = $this->aliasRepo->listForAccount((int) $acc['id'], $userId);
            }
            unset($acc);
        }

        return new Response(View::render('@modulnest.mail-client/compose', [
            'title'            => 'Neue E-Mail',
            'current_path'     => '/mail-client/compose',
            'accounts'         => $accounts,
            'error'            => $error,
            'success'          => $success,
            'reply_to'         => (string) $request->query('reply_to', ''),
            'reply_subject'    => (string) $request->query('subject', ''),
            'reply_account_id' => (int) $request->query('account', '0'),
            'reply_from_email' => $replyFromEmail,
            'reply_sender_val' => $replySenderVal,
        ]));
    }

    // ═════════════════════════════════════════════════════════════════════════
    // POST-Routen (Formular-Aktionen)
    // ═════════════════════════════════════════════════════════════════════════

    /** POST /mail-client/accounts */
    public function accountCreate(Request $request): Response
    {
        $user = $this->requireUser();
        if ($user === null) {
            return Response::redirect('/login');
        }
        $userId = (int) $user['id'];
        $data   = $this->validateAccountPayload($request, true);

        if (isset($data['_error'])) {
            $this->session->flash('mc_error', $data['_error']);
            $this->session->set('mc_old', [
                'display_name'    => $request->input('display_name', ''),
                'email_address'   => $request->input('email_address', ''),
                'imap_host'       => $request->input('imap_host', ''),
                'imap_port'       => $request->input('imap_port', '993'),
                'imap_encryption' => $request->input('imap_encryption', 'ssl'),
                'imap_username'   => $request->input('imap_username', ''),
                'smtp_host'       => $request->input('smtp_host', ''),
                'smtp_port'       => $request->input('smtp_port', '587'),
                'smtp_encryption' => $request->input('smtp_encryption', 'tls'),
                'smtp_username'   => $request->input('smtp_username', ''),
            ]);
            $rawOldAliases = $request->inputRaw('aliases') ?? ($_POST['aliases'] ?? []);
            $this->session->set('mc_old_aliases', is_array($rawOldAliases) ? $rawOldAliases : []);
            return Response::redirect('/mail-client/accounts/create');
        }

        try {
            $newAccountId = $this->accounts->create($userId, $data);
            $rawAliases = $request->inputRaw('aliases') ?? ($_POST['aliases'] ?? []);
            $aliasesInput = is_array($rawAliases) ? $rawAliases : [];
            if ($this->aliasRepo !== null) {
                $this->aliasRepo->syncForAccount($newAccountId, $userId, $aliasesInput);
            }
        } catch (\PDOException $e) {
            $msg = (string) $e->getCode() === '23000'
                ? 'Dieses Mailkonto existiert bereits für diesen Benutzer.'
                : 'Konto konnte nicht gespeichert werden.';
            $this->session->flash('mc_error', $msg);
            return Response::redirect('/mail-client/accounts/create');
        }

        $this->session->flash('mc_success', 'Mailkonto erfolgreich angelegt.');
        return Response::redirect('/mail-client');
    }

    /** POST /mail-client/accounts/* – Update oder Delete */
    public function accountPost(Request $request): Response
    {
        $user = $this->requireUser();
        if ($user === null) {
            return Response::redirect('/login');
        }
        $userId = (int) $user['id'];
        $path   = trim($request->path(), '/');

        $accountId = null;
        $action    = 'update';

        if (preg_match('#^mail-client/accounts/(\d+)$#', $path, $m)) {
            $accountId = (int) $m[1];
        }
        if (preg_match('#^mail-client/accounts/(\d+)/delete$#', $path, $m)) {
            $accountId = (int) $m[1];
            $action    = 'delete';
        }

        if ($accountId === null) {
            return $this->notFound($request);
        }

        $account = $this->accounts->findForUser($accountId, $userId);
        if ($account === null) {
            return $this->notFound($request);
        }

        if ($action === 'delete') {
            $this->accounts->delete($accountId, $userId);
            $this->session->flash('mc_success', 'Mailkonto gelöscht.');
            return Response::redirect('/mail-client');
        }

        $data = $this->validateAccountPayload($request, false);
        if (isset($data['_error'])) {
            $this->session->flash('mc_error', $data['_error']);
            return Response::redirect('/mail-client/accounts/' . $accountId . '/edit');
        }
        $this->accounts->update($accountId, $userId, $data);
        $rawAliases = $request->inputRaw('aliases') ?? ($_POST['aliases'] ?? []);
        $aliasesInput = is_array($rawAliases) ? $rawAliases : [];
        if ($this->aliasRepo !== null) {
            $this->aliasRepo->syncForAccount($accountId, $userId, $aliasesInput);
        }
        $this->session->flash('mc_success', 'Mailkonto aktualisiert.');
        return Response::redirect('/mail-client');
    }

    /** POST /mail-client/compose */
    public function composeSend(Request $request): Response
    {
        $user = $this->requireUser();
        if ($user === null) {
            return Response::redirect('/login');
        }
        $userId         = (int) $user['id'];
        $senderIdentity = trim((string) ($request->input('sender_identity') ?? ''));
        $accountId      = (int) ($request->input('account_id') ?? 0);
        $fromEmail      = '';
        $fromName       = '';

        if (str_starts_with($senderIdentity, 'alias:')) {
            $aliasId = (int) substr($senderIdentity, 6);
            $alias   = $this->aliasRepo?->findForUser($aliasId, $userId);
            if ($alias === null) {
                $this->session->flash('mc_error', 'Ungültiger Absender-Alias.');
                return Response::redirect('/mail-client/compose');
            }
            $accountId = (int) $alias['account_id'];
            $account   = $this->accounts->findForUser($accountId, $userId);
            if ($account === null) {
                $this->session->flash('mc_error', 'Zugehöriges Mailkonto nicht gefunden.');
                return Response::redirect('/mail-client/compose');
            }
            $fromEmail = (string) $alias['email_address'];
            $fromName  = (string) ($alias['display_name'] !== '' ? $alias['display_name'] : $account['display_name']);
        } elseif (str_starts_with($senderIdentity, 'acc:')) {
            $accountId = (int) substr($senderIdentity, 4);
            $account   = $this->accounts->findForUser($accountId, $userId);
            if ($account === null) {
                $this->session->flash('mc_error', 'Ungültiges Absenderkonto.');
                return Response::redirect('/mail-client/compose');
            }
            $fromEmail = (string) $account['email_address'];
            $fromName  = (string) $account['display_name'];
        } else {
            $account = $accountId > 0 ? $this->accounts->findForUser($accountId, $userId) : null;
            if ($account === null) {
                $this->session->flash('mc_error', 'Ungültiges Absenderkonto.');
                return Response::redirect('/mail-client/compose');
            }
            $fromEmail = (string) $account['email_address'];
            $fromName  = (string) $account['display_name'];
        }

        $to      = trim((string) $request->input('to', ''));
        $cc      = trim((string) $request->input('cc', ''));
        $subject = trim((string) $request->input('subject', ''));
        $body    = (string) $request->input('body', '');

        if ($to === '') {
            $this->session->flash('mc_error', 'Kein Empfänger angegeben.');
            return Response::redirect('/mail-client/compose');
        }
        if ($subject === '') {
            $this->session->flash('mc_error', 'Betreff fehlt.');
            return Response::redirect('/mail-client/compose');
        }
        if (trim(strip_tags($body)) === '') {
            $this->session->flash('mc_error', 'Nachrichtentext fehlt.');
            return Response::redirect('/mail-client/compose');
        }

        $sanitizedHtml = $this->sanitizer->sanitizeOutgoing($body);
        $textBody      = strip_tags($sanitizedHtml);

        try {
            $password    = $this->decryptPassword((string) $account['encrypted_password']);
            $accountConn = array_merge($account, ['password' => $password]);
            $this->smtpSender->send(
                $accountConn,
                [
                    'to'         => [$to],
                    'cc'         => $cc !== '' ? [$cc] : [],
                    'from_email' => $fromEmail,
                    'from_name'  => $fromName,
                ],
                $subject,
                $sanitizedHtml,
                $textBody
            );
        } catch (\Throwable $e) {
            $this->session->flash('mc_error', 'Mail konnte nicht gesendet werden: ' . $e->getMessage());
            return Response::redirect('/mail-client/compose');
        }

        $this->session->flash('mc_success', 'Mail erfolgreich gesendet.');
        return Response::redirect('/mail-client/compose');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // AJAX-API-Endpunkte (JSON)
    // ═════════════════════════════════════════════════════════════════════════

    /** GET /mail-client/api/folders?account={id} */
    public function apiFolders(Request $request): Response
    {
        $user = $this->requireUser(true);
        if ($user === null) {
            return $this->jsonError('Nicht angemeldet.', 401);
        }
        $userId    = (int) $user['id'];
        $accountId = (int) $request->query('account', '0');
        $account   = $accountId > 0 ? $this->accounts->findForUser($accountId, $userId) : null;

        if ($account === null) {
            return $this->jsonError('Konto nicht gefunden.', 404);
        }

        try {
            $password    = $this->decryptPassword((string) $account['encrypted_password']);
            $accountConn = array_merge($account, ['password' => $password]);
            $folders     = $this->syncService->listFolders($accountId, $accountConn);

            $result = array_map(static function (array $f): array {
                $name = $f['name'];
                return [
                    'name'         => $name,
                    'display_name' => self::getFolderDisplayName($name),
                    'unseen'       => (int) ($f['unseen'] ?? 0),
                    'total'        => (int) ($f['total'] ?? 0),
                    'special'      => self::getFolderSpecialType($name),
                    'delimiter'    => $f['delimiter'] ?? '/',
                ];
            }, $folders);

            return $this->json(['folders' => $result]);
        } catch (\Throwable $e) {
            return $this->jsonError('Ordner konnten nicht geladen werden: ' . $e->getMessage());
        }
    }

    /** GET /mail-client/api/messages?account={id}&folder={name}&limit=50&offset=0 */
    public function apiMessages(Request $request): Response
    {
        $user = $this->requireUser(true);
        if ($user === null) {
            return $this->jsonError('Nicht angemeldet.', 401);
        }
        $userId    = (int) $user['id'];
        $accountId = (int) ($request->query('account') ?? $request->input('account') ?? '0');
        $folder    = (string) ($request->query('folder') ?? $request->input('folder') ?? 'INBOX');
        $limit     = min(100, max(10, (int) $request->query('limit', '50')));
        $offset    = max(0, (int) $request->query('offset', '0'));
        $account   = $accountId > 0 ? $this->accounts->findForUser($accountId, $userId) : null;

        if ($account === null) {
            return $this->jsonError('Konto nicht gefunden.', 404);
        }

        try {
            $password    = $this->decryptPassword((string) $account['encrypted_password']);
            $accountConn = array_merge($account, ['password' => $password]);
            $result      = $this->syncService->getMessages($userId, $accountId, $accountConn, $folder, $limit, $offset);

            $userTimezone = (string) ($user['timezone'] ?? '');
            foreach ($result['messages'] as &$msg) {
                $msg['date_formatted'] = $this->formatMessageDate((int) $msg['message_date'], $userTimezone);
                $rName = trim((string) ($msg['recipient_name'] ?? ''));
                $rAddr = trim((string) ($msg['recipient_address'] ?? ''));
                $msg['recipient'] = $rName !== '' ? $rName : ($rAddr !== '' ? $rAddr : '-');
                $msg['is_seen']        = ((int) $msg['flags'] & MessageIndexRepository::FLAG_SEEN) !== 0;
                $msg['is_flagged']     = ((int) $msg['flags'] & MessageIndexRepository::FLAG_FLAGGED) !== 0;
                $msg['is_answered']    = ((int) $msg['flags'] & MessageIndexRepository::FLAG_ANSWERED) !== 0;
            }
            unset($msg);

            return $this->json($result);
        } catch (\Throwable $e) {
            return $this->jsonError('Nachrichten konnten nicht geladen werden: ' . $e->getMessage());
        }
    }

    /** GET /mail-client/api/message-body?account={id}&folder={name}&uid={uid} */
    public function apiMessageBody(Request $request): Response
    {
        $user = $this->requireUser(true);
        if ($user === null) {
            return $this->jsonError('Nicht angemeldet.', 401);
        }
        $userId    = (int) $user['id'];
        $accountId = (int) ($request->query('account') ?? $request->input('account') ?? '0');
        $folder    = (string) ($request->query('folder') ?? $request->input('folder') ?? 'INBOX');
        $uid       = (int) $request->query('uid', '0');
        $account   = $accountId > 0 ? $this->accounts->findForUser($accountId, $userId) : null;

        if ($account === null || $uid === 0) {
            return $this->jsonError('Ungültige Parameter.', 400);
        }

        $userTimezone = (string) ($user['timezone'] ?? 'Europe/Berlin');

        try {
            $password    = $this->decryptPassword((string) $account['encrypted_password']);
            $accountConn = array_merge($account, ['password' => $password]);
            $msgData     = $this->messageFetcher->fetchByUid($accountConn, $folder, $uid, $userTimezone);
        } catch (\Throwable $e) {
            return $this->jsonError('Nachricht konnte nicht geladen werden: ' . $e->getMessage());
        }

        $allowExternal = $this->folderCache->isWhitelisted($userId, $msgData['from']);
        $rawHtml       = $msgData['html_body'] !== ''
            ? $msgData['html_body']
            : '<pre>' . htmlspecialchars($msgData['text_body'], ENT_QUOTES, 'UTF-8') . '</pre>';
        $sanitized = $this->sanitizer->sanitize($rawHtml, $allowExternal);

        // Als gelesen markieren
        $this->markAsSeen($accountConn, $accountId, $folder, $uid);

        $userTimezone = (string) ($user['timezone'] ?? '');
        $formattedDate = '';
        if (!empty($msgData['date_ts'])) {
            $formattedDate = DateTimeFormatter::formatUserDateTime('@' . $msgData['date_ts'], $userTimezone);
        } elseif (!empty($msgData['date'])) {
            $formattedDate = DateTimeFormatter::formatUserDateTime($msgData['date'], $userTimezone);
        }

        // Kalender-Events prüfen, ob bereits im Kalender eingetragen
        $calendarEvents = $msgData['calendar_events'] ?? [];
        if (!empty($calendarEvents)) {
            $pdo = $this->getPdo();
            if ($pdo !== null) {
                try {
                    $checkTable = $pdo->query("SHOW TABLES LIKE 'calendar_appointments'")->fetchColumn();
                    if ($checkTable) {
                        $checkStmt = $pdo->prepare('SELECT id FROM calendar_appointments WHERE user_id = ? AND title = ? AND start_at = ? LIMIT 1');
                        foreach ($calendarEvents as &$ev) {
                            $checkStmt->execute([$userId, $ev['title'], $ev['start_at']]);
                            $existingId = $checkStmt->fetchColumn();
                            $ev['already_in_calendar']     = (bool) $existingId;
                            $ev['calendar_appointment_id'] = $existingId ? (int) $existingId : null;
                        }
                        unset($ev);
                    }
                } catch (\Throwable) {}
            }
        }

        $fromName  = ImapMessageFetcher::decodeHeader((string) ($msgData['from_name'] ?? ''));
        $fromEmail = ImapMessageFetcher::decodeHeader((string) ($msgData['from'] ?? ''));
        if (preg_match('/^(.*?)\s*<([^>]+)>?$/', $fromEmail, $mFrom)) {
            if ($fromName === '') {
                $fromName = trim($mFrom[1], "\"\x27\t\n\r ");
            }
            $fromEmail = trim($mFrom[2]);
        }
        if (preg_match('/^(.*?)\s*<([^>]+)>?$/', $fromName, $mFromName)) {
            $fromName = trim($mFromName[1], "\"\x27\t\n\r ");
            if ($fromEmail === '') {
                $fromEmail = trim($mFromName[2]);
            }
        }
        $fromFormatted = $fromName !== '' ? $fromName . ' <' . $fromEmail . '>' : $fromEmail;

        return $this->json([
            'uid'             => $uid,
            'subject'         => ImapMessageFetcher::decodeHeader((string) ($msgData['subject'] ?? '')),
            'from'            => $fromFormatted,
            'from_name'       => $fromName,
            'from_email'      => $fromEmail,
            'to'              => ImapMessageFetcher::decodeHeader((string) ($msgData['to'] ?? '')),
            'cc'              => ImapMessageFetcher::decodeHeader((string) ($msgData['cc'] ?? '')),
            'date'            => $formattedDate ?: $msgData['date'],
            'date_raw'        => $msgData['date'],
            'date_ts'         => $msgData['date_ts'],
            'html'            => $sanitized['html'],
            'has_html'        => $msgData['has_html'],
            'blocked_images'  => $sanitized['blocked_images'],
            'allow_external'  => $allowExternal,
            'attachments'     => $msgData['attachments'],
            'calendar_events' => $calendarEvents,
            'account_id'      => $accountId,
            'folder'          => $folder,
        ]);
    }

    /** POST /mail-client/api/messages/flag */
    public function apiFlag(Request $request): Response
    {
        $user = $this->requireUser(true);
        if ($user === null) {
            return $this->jsonError('Nicht angemeldet.', 401);
        }
        $userId    = (int) $user['id'];
        // JSON wird automatisch in input() geparsed
        $accountId = (int) ($request->inputRaw('account') ?? 0);
        $folder    = (string) ($request->inputRaw('folder') ?? '');
        $uid       = (int) ($request->inputRaw('uid') ?? 0);
        $flag      = (string) ($request->inputRaw('flag') ?? '');
        $setFlag   = (bool) ($request->inputRaw('set') ?? true);
        $account   = $accountId > 0 ? $this->accounts->findForUser($accountId, $userId) : null;

        if ($account === null || $uid === 0 || !in_array($flag, ['seen', 'flagged', 'answered'], true)) {
            return $this->jsonError('Ungültige Parameter.', 400);
        }

        $imapFlag = match ($flag) {
            'seen'     => '\\Seen',
            'flagged'  => '\\Flagged',
            'answered' => '\\Answered',
            default    => null,
        };
        $flagBit = match ($flag) {
            'seen'     => MessageIndexRepository::FLAG_SEEN,
            'flagged'  => MessageIndexRepository::FLAG_FLAGGED,
            'answered' => MessageIndexRepository::FLAG_ANSWERED,
            default    => 0,
        };

        try {
            $password    = $this->decryptPassword((string) $account['encrypted_password']);
            $accountConn = array_merge($account, ['password' => $password]);
            $client      = ImapConnectionFactory::connect($accountConn);
            try {
                $imapFolder = null;
                foreach ($client->getFolders(false) as $f) {
                    if (is_object($f) && strtolower(trim((string) ($f->path ?? $f->name ?? ''))) === strtolower($folder)) {
                        $imapFolder = $f;
                        break;
                    }
                }
                if ($imapFolder !== null) {
                    $client->openFolder($imapFolder->path);
                    $proto = $client->getConnection();
                    $mode  = $setFlag ? '+' : '-';
                    $proto->store([$imapFlag], $uid, $uid, $mode, true, \Webklex\PHPIMAP\IMAP::ST_UID);
                }
            } finally {
                $client->disconnect();
                ImapConnectionFactory::flushImapErrors();
            }

            $existing = $this->messageIndex->findByUid($accountId, $folder, $uid);
            if ($existing !== null) {
                $currentBits = (int) $existing['flags'];
                $newBits     = $setFlag ? ($currentBits | $flagBit) : ($currentBits & ~$flagBit);
                $this->messageIndex->updateFlags($accountId, $folder, $uid, $newBits);
            }

            return $this->json(['ok' => true]);
        } catch (\Throwable $e) {
            return $this->jsonError('Flag konnte nicht gesetzt werden: ' . $e->getMessage());
        }
    }

    /** POST /mail-client/api/messages/delete */
    public function apiDelete(Request $request): Response
    {
        $user = $this->requireUser(true);
        if ($user === null) {
            return $this->jsonError('Nicht angemeldet.', 401);
        }
        $userId    = (int) $user['id'];
        $accountId = (int) ($request->inputRaw('account') ?? 0);
        $folder    = (string) ($request->inputRaw('folder') ?? '');
        $uid       = (int) ($request->inputRaw('uid') ?? 0);
        $account   = $accountId > 0 ? $this->accounts->findForUser($accountId, $userId) : null;

        if ($account === null || $uid === 0 || $folder === '') {
            return $this->jsonError('Ungültige Parameter.', 400);
        }

        try {
            $password    = $this->decryptPassword((string) $account['encrypted_password']);
            $accountConn = array_merge($account, ['password' => $password]);
            $client      = ImapConnectionFactory::connect($accountConn);
            try {
                foreach ($client->getFolders(false) as $f) {
                    if (is_object($f) && strtolower(trim((string) ($f->path ?? $f->name ?? ''))) === strtolower($folder)) {
                        $f->query()->getMessageByUid($uid)?->delete(true);
                        break;
                    }
                }
            } finally {
                $client->disconnect();
                ImapConnectionFactory::flushImapErrors();
            }

            // Aus Cache entfernen: alle UIDs außer der gelöschten behalten
            $remainingUids = array_filter(
                $this->messageIndex->listUidsForFolder($accountId, $folder),
                static fn (int $u): bool => $u !== $uid
            );
            $this->messageIndex->removeStaleUids($accountId, $folder, array_values($remainingUids));

            return $this->json(['ok' => true]);
        } catch (\Throwable $e) {
            return $this->jsonError('Mail konnte nicht gelöscht werden: ' . $e->getMessage());
        }
    }

    /** GET /mail-client/api/attachment?account={id}&folder={name}&uid={uid}&part={partId} */
    public function apiAttachment(Request $request): Response
    {
        $user = $this->requireUser(true);
        if ($user === null) {
            return Response::redirect('/login');
        }
        $userId    = (int) $user['id'];
        $accountId = (int) ($request->query('account') ?? $request->input('account') ?? '0');
        $folder    = (string) ($request->query('folder') ?? $request->input('folder') ?? 'INBOX');
        $uid       = (int) $request->query('uid', '0');
        $partId    = (string) $request->query('part', '');
        $account   = $accountId > 0 ? $this->accounts->findForUser($accountId, $userId) : null;

        if ($account === null || $uid === 0 || $partId === '') {
            return $this->notFound($request);
        }

        try {
            $password    = $this->decryptPassword((string) $account['encrypted_password']);
            $accountConn = array_merge($account, ['password' => $password]);
            $att         = $this->messageFetcher->fetchAttachment($accountConn, $folder, $uid, $partId);
        } catch (\Throwable $e) {
            return new Response('Anhang nicht gefunden: ' . $e->getMessage(), 404);
        }

        return new Response($att['data'], 200, [
            'Content-Type'           => $att['mimetype'],
            'Content-Disposition'    => 'attachment; filename="' . addslashes($att['filename']) . '"',
            'Content-Length'         => (string) strlen($att['data']),
            'Cache-Control'          => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** POST /mail-client/api/whitelist */
    public function apiWhitelistAdd(Request $request): Response
    {
        $user = $this->requireUser(true);
        if ($user === null) {
            return $this->jsonError('Nicht angemeldet.', 401);
        }
        $userId     = (int) $user['id'];
        $scopeType  = (string) ($request->inputRaw('scope_type') ?? 'sender');
        $scopeValue = trim((string) ($request->inputRaw('scope_value') ?? ''));

        if (!in_array($scopeType, ['sender', 'domain'], true) || $scopeValue === '') {
            return $this->jsonError('Ungültige Parameter.', 400);
        }

        $this->folderCache->addToWhitelist($userId, $scopeType, $scopeValue);
        return $this->json(['ok' => true]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Private Helfer
    // ═════════════════════════════════════════════════════════════════════════

    /** @return array<string, mixed>|null */
    private function requireUser(bool $closeSession = false): ?array
    {
        $user = $this->auth?->currentUser();
        if ($closeSession && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        return is_array($user) ? $user : null;
    }

    private function getPdo(): ?\PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        $dbConfigFile = dirname(__DIR__, 5) . "/app/Config/database.php";
        if (file_exists($dbConfigFile)) {
            $this->pdo = \Modulon\Core\Database::connect(require $dbConfigFile);
        }
        return $this->pdo;
    }

    private function decryptPassword(string $encrypted): string
    {
        $key = Env::get(self::CRED_KEY_ENV, '');
        if ($key === '' || $key === null) {
            throw new \RuntimeException('MAIL_CREDENTIAL_KEY ist nicht konfiguriert.');
        }
        return SecretBox::decrypt($encrypted, $key);
    }

    /**
     * Validiert Konto-Payload aus dem POST-Request.
     * @return array<string, mixed> Entweder Daten-Array oder ['_error' => '...']
     */
    private function validateAccountPayload(Request $request, bool $passwordRequired): array
    {
        $allowedEncs = ['ssl', 'tls', 'starttls'];

        $displayName   = trim((string) $request->input('display_name', ''));
        $emailAddress  = strtolower(trim((string) $request->input('email_address', '')));
        $imapHost      = trim((string) $request->input('imap_host', ''));
        $imapPort      = (int) $request->input('imap_port', '993');
        $imapEnc       = strtolower(trim((string) $request->input('imap_encryption', 'ssl')));
        $imapUser      = trim((string) $request->input('imap_username', ''));
        $smtpHost      = trim((string) $request->input('smtp_host', ''));
        $smtpPort      = (int) $request->input('smtp_port', '587');
        $smtpEnc       = strtolower(trim((string) $request->input('smtp_encryption', 'tls')));
        $smtpUser      = trim((string) $request->input('smtp_username', ''));
        $password      = (string) $request->input('password', '');

        if ($displayName === '') {
            return ['_error' => 'Kontoname fehlt.'];
        }
        if (!filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
            return ['_error' => 'Ungültige E-Mail-Adresse.'];
        }
        if ($imapHost === '') {
            return ['_error' => 'IMAP-Server fehlt.'];
        }
        if ($imapPort < 1 || $imapPort > 65535) {
            return ['_error' => 'Ungültiger IMAP-Port.'];
        }
        if (!in_array($imapEnc, $allowedEncs, true)) {
            return ['_error' => 'Ungültige IMAP-Verschlüsselung.'];
        }
        if ($smtpHost === '') {
            return ['_error' => 'SMTP-Server fehlt.'];
        }
        if ($smtpPort < 1 || $smtpPort > 65535) {
            return ['_error' => 'Ungültiger SMTP-Port.'];
        }
        if (!in_array($smtpEnc, $allowedEncs, true)) {
            return ['_error' => 'Ungültige SMTP-Verschlüsselung.'];
        }
        if ($passwordRequired && $password === '') {
            return ['_error' => 'Passwort fehlt.'];
        }

        $result = [
            'display_name'    => $displayName,
            'email_address'   => $emailAddress,
            'imap_host'       => $imapHost,
            'imap_port'       => $imapPort,
            'imap_encryption' => $imapEnc,
            'imap_username'   => $imapUser !== '' ? $imapUser : $emailAddress,
            'smtp_host'       => $smtpHost,
            'smtp_port'       => $smtpPort,
            'smtp_encryption' => $smtpEnc,
            'smtp_username'   => $smtpUser !== '' ? $smtpUser : $emailAddress,
        ];

        if ($password !== '') {
            $key = Env::get(self::CRED_KEY_ENV, '');
            if ($key === '' || $key === null) {
                return ['_error' => 'MAIL_CREDENTIAL_KEY ist nicht konfiguriert. Bitte in der .env setzen.'];
            }
            try {
                $result['encrypted_password'] = SecretBox::encrypt($password, $key);
            } catch (\RuntimeException) {
                return ['_error' => 'Zugangsdaten konnten nicht gesichert werden.'];
            }
        }

        return $result;
    }

    /** Markiert eine Nachricht als gelesen (IMAP + Cache). Fehler werden ignoriert. */
    private function markAsSeen(array $accountConn, int $accountId, string $folder, int $uid): void
    {
        try {
            $flagBits = (int) ($this->messageIndex->findByUid($accountId, $folder, $uid)['flags'] ?? 0);
            if (($flagBits & MessageIndexRepository::FLAG_SEEN) !== 0) {
                return; // Bereits als gelesen markiert
            }

            $client = ImapConnectionFactory::connect($accountConn);
            try {
                $imapFolder = null;
                foreach ($client->getFolders(false) as $f) {
                    if (is_object($f) && strtolower(trim((string) ($f->path ?? $f->name ?? ''))) === strtolower($folder)) {
                        $imapFolder = $f;
                        break;
                    }
                }
                if ($imapFolder !== null) {
                    $client->openFolder($imapFolder->path);
                    $proto = $client->getConnection();
                    $proto->store(['\\Seen'], $uid, $uid, '+', true, \Webklex\PHPIMAP\IMAP::ST_UID);
                }
            } finally {
                $client->disconnect();
            }

            $this->messageIndex->updateFlags($accountId, $folder, $uid, $flagBits | MessageIndexRepository::FLAG_SEEN);
        } catch (\Throwable) {
            // Nicht kritisch
        }
    }

    private function json(mixed $data, int $status = 200): Response
    {
        return new Response(
            (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }

    private function jsonError(string $message, int $status = 500): Response
    {
        return $this->json(['error' => $message], $status);
    }

    private function notFound(Request $request): Response
    {
        return new Response(View::render('errors/404', [
            'title'        => '404 Not Found',
            'current_path' => $request->path(),
        ]), 404);
    }

    private function extractId(string $path, string $prefix, string $suffix): ?int
    {
        $path   = '/' . trim($path, '/');
        $prefix = '/' . trim($prefix, '/') . '/';
        $suffix = '/' . trim($suffix, '/');
        if (!str_starts_with($path, $prefix)) {
            return null;
        }
        $rest = substr($path, strlen($prefix));
        if ($suffix !== '/' && str_ends_with($rest, ltrim($suffix, '/'))) {
            $rest = substr($rest, 0, -(strlen(ltrim($suffix, '/'))));
            $rest = rtrim($rest, '/');
        }
        return is_numeric($rest) ? (int) $rest : null;
    }

    private function formatMessageDate(int $timestamp, string $timezone = ''): string
    {
        if ($timestamp <= 0) {
            return '';
        }
        try {
            $tz = $timezone !== '' ? new \DateTimeZone($timezone) : new \DateTimeZone(date_default_timezone_get());
        } catch (\Throwable) {
            $tz = new \DateTimeZone('UTC');
        }

        $now = new \DateTimeImmutable('now', $tz);
        $msgDate = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($tz);
        $today = $now->setTime(0, 0, 0);
        $diff = $now->getTimestamp() - $msgDate->getTimestamp();

        if ($msgDate >= $today) {
            return $msgDate->format('H:i');
        }
        if ($diff < 7 * 86400 && $diff > 0) {
            $days = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
            return $days[(int) $msgDate->format('w')];
        }
        if ($msgDate->format('Y') === $now->format('Y')) {
            return $msgDate->format('d.m.');
        }
        return $msgDate->format('d.m.Y');
    }

    public static function decodeImapFolderName(string $name): string
    {
        if (strpos($name, '&') !== false) {
            $decoded = @mb_convert_encoding($name, 'UTF-8', 'UTF7-IMAP');
            if ($decoded !== false && $decoded !== '') {
                return $decoded;
            }
        }
        return $name;
    }

    public static function getFolderSpecialType(string $name): string
    {
        $decoded = self::decodeImapFolderName($name);
        $lower = strtolower(trim($decoded));
        $baseName = basename(str_replace(['.', '\\'], '/', $lower));
        return match (true) {
            $lower === 'inbox' || $baseName === 'inbox'                                                 => 'inbox',
            in_array($baseName, ['sent', 'sent items', 'gesendet', 'sent-mail', 'gesendete objekte'])  => 'sent',
            in_array($baseName, ['drafts', 'draft', 'entwürfe', 'entwuerfe'])                           => 'drafts',
            in_array($baseName, ['trash', 'deleted', 'papierkorb', 'gelöschte elemente', 'deleted items', 'bin']) => 'trash',
            in_array($baseName, ['spam', 'junk', 'junk e-mail', 'unerwünscht', 'spamverdacht'])         => 'spam',
            in_array($baseName, ['archive', 'archiv', 'archives'])                                      => 'archive',
            default                                                                                     => 'folder',
        };
    }

    public static function getFolderDisplayName(string $name): string
    {
        $special = self::getFolderSpecialType($name);
        return match ($special) {
            'inbox'   => 'Posteingang',
            'sent'    => 'Gesendet',
            'drafts'  => 'Entwürfe',
            'trash'   => 'Papierkorb',
            'spam'    => 'Spam',
            'archive' => 'Archiv',
            default   => self::decodeImapFolderName($name),
        };
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Sync-Status & Hintergrund-Sync-API
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * GET /mail-client/api/sync/status?account={id}&folder={name}
     * Gibt den aktuellen Sync-Fortschritt zurück (kein IMAP-Aufruf).
     */
    public function apiSyncStatus(Request $request): Response
    {
        $user = $this->requireUser(true);
        if ($user === null) {
            return $this->jsonError('Nicht angemeldet.', 401);
        }
        $userId    = (int) $user['id'];
        $accountId = (int) ($request->query('account') ?? $request->input('account') ?? '0');
        $folder    = (string) ($request->query('folder') ?? $request->input('folder') ?? 'INBOX');
        $account   = $accountId > 0 ? $this->accounts->findForUser($accountId, $userId) : null;

        if ($account === null) {
            return $this->jsonError('Konto nicht gefunden.', 404);
        }

        $progress = $this->syncService->getSyncProgress($accountId, $folder);
        return $this->json($progress);
    }

    /**
     * POST /mail-client/api/sync/continue?account={id}&folder={name}
     * Lädt den nächsten Batch an Mail-Headern vom IMAP-Server (max. 25 Sek.).
     * Wird vom Frontend per Polling wiederholt aufgerufen bis status=complete.
     */
    public function apiSyncContinue(Request $request): Response
    {
        $user = $this->requireUser(true);
        if ($user === null) {
            return $this->jsonError('Nicht angemeldet.', 401);
        }
        $userId    = (int) $user['id'];
        $accountId = (int) ($request->query('account') ?? $request->input('account') ?? '0');
        $folder    = (string) ($request->query('folder') ?? $request->input('folder') ?? 'INBOX');
        $account   = $accountId > 0 ? $this->accounts->findForUser($accountId, $userId) : null;

        if ($account === null) {
            return $this->jsonError('Konto nicht gefunden.', 404);
        }

        try {
            $password    = $this->decryptPassword((string) $account['encrypted_password']);
            $accountConn = array_merge($account, ['password' => $password]);
            $result      = $this->syncService->continueSync($accountId, $accountConn, $folder);
            return $this->json($result);
        } catch (\Throwable $e) {
            return $this->jsonError('Sync fehlgeschlagen: ' . $e->getMessage());
        } finally {
            ImapConnectionFactory::flushImapErrors();
        }
    }

    /**
     * POST /mail-client/api/sync/refresh?account={id}&folder={name}
     * Führt eine schnelle Aktualisierung des Ordners durch (neue Mails + Unseen Counter).
     */
    public function apiSyncRefresh(Request $request): Response
    {
        $user = $this->requireUser(true);
        if ($user === null) {
            return $this->jsonError('Nicht angemeldet.', 401);
        }
        $userId    = (int) $user['id'];
        $accountId = (int) ($request->query('account') ?? $request->input('account') ?? '0');
        $folder    = (string) ($request->query('folder') ?? $request->input('folder') ?? 'INBOX');
        $account   = $accountId > 0 ? $this->accounts->findForUser($accountId, $userId) : null;

        if ($account === null) {
            return $this->jsonError('Konto nicht gefunden.', 404);
        }

        try {
            $password    = $this->decryptPassword((string) $account['encrypted_password']);
            $accountConn = array_merge($account, ['password' => $password]);
            $stats       = $this->syncService->refreshFolder($accountId, $accountConn, $folder);
            return $this->json(array_merge(['success' => true], $stats));
        } catch (\Throwable $e) {
            return $this->jsonError('Aktualisierung fehlgeschlagen: ' . $e->getMessage());
        } finally {
            ImapConnectionFactory::flushImapErrors();
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Autoconfig-API
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * GET /mail-client/api/autoconfig?email={address}
     * Versucht IMAP/SMTP-Einstellungen für eine E-Mail-Adresse zu ermitteln.
     */
    public function apiAutoconfig(Request $request): Response
    {
        $user = $this->requireUser();
        if ($user === null) {
            return $this->jsonError('Nicht angemeldet.', 401);
        }

        $email = trim((string) $request->query('email', ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->jsonError('Ungültige E-Mail-Adresse.', 400);
        }

        try {
            $result = $this->autoconfigService->detect($email);
            if ($result === null) {
                return $this->json(['found' => false]);
            }
            return $this->json(array_merge(['found' => true], $result));
        } catch (\Throwable $e) {
            return $this->jsonError('Autoconfig fehlgeschlagen: ' . $e->getMessage());
        }
    }


    /** GET /mail-client/api/calendar/calendars */
    public function apiCalendarList(Request $request): Response
    {
        $user = $this->requireUser(true);
        if ($user === null) {
            return $this->jsonError('Nicht angemeldet.', 401);
        }
        $userId = (int) $user['id'];

        try {
            $pdo = $this->getPdo();
            if ($pdo === null) {
                return $this->json(['calendars' => []]);
            }

            $check = $pdo->query("SHOW TABLES LIKE 'calendars'")->fetchColumn();
            if (!$check) {
                return $this->json(['calendars' => []]);
            }

            $stmt = $pdo->prepare('SELECT id, name, color, is_default, source FROM calendars WHERE user_id = ? ORDER BY is_default DESC, name ASC');
            $stmt->execute([$userId]);
            $calendars = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (empty($calendars)) {
                $ins = $pdo->prepare('INSERT INTO calendars (user_id, name, color, is_visible, is_default, source) VALUES (?, ?, ?, 1, 1, "local")');
                $ins->execute([$userId, 'Mein Kalender', '#3B82F6']);
                $calendars[] = [
                    'id'         => (int) $pdo->lastInsertId(),
                    'name'       => 'Mein Kalender',
                    'color'      => '#3B82F6',
                    'is_default' => 1,
                    'source'     => 'local',
                ];
            }

            return $this->json([
                'calendars' => array_map(static fn(array $c) => [
                    'id'         => (int) $c['id'],
                    'name'       => (string) $c['name'],
                    'color'      => (string) ($c['color'] ?? '#3B82F6'),
                    'is_default' => (bool) $c['is_default'],
                    'source'     => (string) ($c['source'] ?? 'local'),
                ], $calendars),
            ]);
        } catch (\Throwable $e) {
            return $this->json(['calendars' => [], 'error' => $e->getMessage()]);
        }
    }

    /** GET /mail-client/api/calendar/upcoming */
    public function apiCalendarUpcoming(Request $request): Response
    {
        $user = $this->requireUser(true);
        if ($user === null) {
            return $this->jsonError('Nicht angemeldet.', 401);
        }
        $userId = (int) $user['id'];

        try {
            $pdo = $this->pdo;
            if ($pdo === null) {
                $dbConfigFile = dirname(__DIR__, 5) . '/app/Config/database.php';
                if (file_exists($dbConfigFile)) {
                    $pdo = \Modulon\Core\Database::connect(require $dbConfigFile);
                }
            }
            if ($pdo === null) {
                return $this->json(['appointments' => []]);
            }

            $check = $pdo->query("SHOW TABLES LIKE 'calendar_appointments'")->fetchColumn();
            if (!$check) {
                return $this->json(['appointments' => []]);
            }

            $windowStart = new \DateTimeImmutable('today 00:00:00');
            $windowEnd   = $windowStart->modify('+30 days 23:59:59');

            $stmt = $pdo->prepare('
                SELECT a.*,
                       COALESCE(NULLIF(a.color, ""), c.color, "#0d6efd") AS color,
                       COALESCE(c.name, "Kalender") AS calendar_name
                FROM calendar_appointments a
                LEFT JOIN calendars c ON c.id = a.calendar_id
                WHERE a.user_id = ?
                  AND (c.id IS NULL OR c.is_visible = 1)
                  AND (
                      (a.start_at < ? AND a.end_at > ?)
                      OR (a.recurrence_rule IS NOT NULL AND a.recurrence_rule != "" AND a.recurrence_parent_id IS NULL AND a.start_at < ?)
                  )
                ORDER BY a.start_at ASC
            ');
            $stmt->execute([
                $userId,
                $windowEnd->format('Y-m-d H:i:s'),
                $windowStart->format('Y-m-d H:i:s'),
                $windowEnd->format('Y-m-d H:i:s'),
            ]);
            $rawAppointments = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $calNames = [];
            foreach ($rawAppointments as $r) {
                if (!empty($r['calendar_id']) && !empty($r['calendar_name'])) {
                    $calNames[(int) $r['calendar_id']] = (string) $r['calendar_name'];
                }
            }

            $appointments = [];
            if (class_exists('\ModulNest\Calendar\Service\RecurrenceService') && class_exists('\ModulNest\Calendar\DTO\AppointmentDTO')) {
                $dtos = array_map(static fn(array $r): \ModulNest\Calendar\DTO\AppointmentDTO => \ModulNest\Calendar\DTO\AppointmentDTO::fromArray($r), $rawAppointments);
                $recurrence = new \ModulNest\Calendar\Service\RecurrenceService();
                $expandedDtos = $recurrence->expandOccurrences($dtos, $windowStart, $windowEnd);
                foreach ($expandedDtos as $dto) {
                    $cid = $dto->calendarId ?? 0;
                    $recText = '';
                    if (!empty($dto->recurrenceRule)) {
                        $rule = \ModulNest\Calendar\Service\RecurrenceService::parseRule($dto->recurrenceRule);
                        $freq = $rule['FREQ'] ?? '';
                        $interval = max(1, (int) ($rule['INTERVAL'] ?? 1));
                        $recText = match ($freq) {
                            'DAILY'    => $interval === 1 ? 'Täglich' : "Alle {$interval} Tage",
                            'WEEKDAYS' => 'Werktags (Montag–Freitag)',
                            'WEEKLY'   => $interval === 1 ? 'Wöchentlich' : "Alle {$interval} Wochen",
                            'MONTHLY'  => $interval === 1 ? 'Monatlich' : "Alle {$interval} Monate",
                            'YEARLY'   => $interval === 1 ? 'Jährlich' : "Alle {$interval} Jahre",
                            default    => 'Wiederholender Termin',
                        };
                    }
                    $appointments[] = [
                        'id'              => $dto->id ?? 0,
                        'calendar_id'     => $cid,
                        'title'           => $dto->title,
                        'description'     => $dto->description ?? '',
                        'location'        => $dto->location ?? '',
                        'start_at'        => $dto->startAt->format('Y-m-d H:i:s'),
                        'end_at'          => $dto->endAt->format('Y-m-d H:i:s'),
                        'all_day'         => $dto->allDay ? 1 : 0,
                        'color'           => $dto->color ?? '#0d6efd',
                        'calendar_name'   => $calNames[$cid] ?? 'Kalender',
                        'recurrence_rule' => $dto->recurrenceRule ?? '',
                        'recurrence_text' => $recText,
                    ];
                }
            } else {
                foreach ($rawAppointments as $apt) {
                    $appointments[] = $apt;
                }
            }

            // Nach Startzeit sortieren
            usort($appointments, static fn($a, $b) => strcmp((string) $a['start_at'], (string) $b['start_at']));
            $appointments = array_slice($appointments, 0, 40);

            $weekdays = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
            $months   = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
            $monthsShort = ['', 'Jan.', 'Feb.', 'März', 'Apr.', 'Mai', 'Juni', 'Juli', 'Aug.', 'Sept.', 'Okt.', 'Nov.', 'Dez.'];

            $formatted = [];
            $todayDate = date('Y-m-d');
            $tomorrowDate = date('Y-m-d', strtotime('+1 day'));

            foreach ($appointments as $apt) {
                $startTs = strtotime((string) $apt['start_at']);
                $endTs   = !empty($apt['end_at']) ? strtotime((string) $apt['end_at']) : $startTs;
                $dateKey = date('Y-m-d', $startTs);

                $groupTitle = match ($dateKey) {
                    $todayDate    => 'Heute',
                    $tomorrowDate => 'Morgen',
                    default       => $weekdays[(int) date('w', $startTs)] . ', ' . (int) date('j', $startTs) . '. ' . $months[(int) date('n', $startTs)],
                };

                $timeStr = ((int) $apt['all_day'] === 1)
                    ? 'Ganztägig'
                    : date('H:i', $startTs) . ($endTs > $startTs && date('Y-m-d', $startTs) === date('Y-m-d', $endTs) ? '–' . date('H:i', $endTs) : '');

                $formatted[] = [
                    'id'              => (int) $apt['id'],
                    'calendar_id'     => (int) ($apt['calendar_id'] ?? 0),
                    'title'           => (string) $apt['title'],
                    'location'        => (string) ($apt['location'] ?? ''),
                    'description'     => (string) ($apt['description'] ?? ''),
                    'start_at'        => (string) $apt['start_at'],
                    'end_at'          => (string) $apt['end_at'],
                    'all_day'         => (bool) $apt['all_day'],
                    'color'           => (string) $apt['color'],
                    'calendar_name'   => (string) ($apt['calendar_name'] ?? 'Kalender'),
                    'recurrence_rule' => (string) ($apt['recurrence_rule'] ?? ''),
                    'recurrence_text' => (string) ($apt['recurrence_text'] ?? ''),
                    'date_key'        => $dateKey,
                    'group_title'     => $groupTitle,
                    'time_str'        => $timeStr,
                ];
            }

            // Kalenderliste für Schnellauswahl ermitteln
            $calStmt = $pdo->prepare('SELECT id, name, color, is_default, source FROM calendars WHERE user_id = ? ORDER BY is_default DESC, name ASC');
            $calStmt->execute([$userId]);
            $userCals = $calStmt->fetchAll(\PDO::FETCH_ASSOC);

            return $this->json([
                'today' => [
                    'day'         => (int) date('j'),
                    'weekday'     => substr($weekdays[(int) date('w')], 0, 2),
                    'month_short' => $monthsShort[(int) date('n')],
                    'year'        => (int) date('Y'),
                    'kw'          => (int) date('W'),
                ],
                'appointments' => $formatted,
                'calendars'    => array_map(static fn(array $c) => [
                    'id'         => (int) $c['id'],
                    'name'       => (string) $c['name'],
                    'color'      => (string) ($c['color'] ?? '#3B82F6'),
                    'is_default' => (bool) $c['is_default'],
                    'source'     => (string) ($c['source'] ?? 'local'),
                ], $userCals),
            ]);
        } catch (\Throwable $e) {
            return $this->json(['appointments' => [], 'error' => $e->getMessage()]);
        }
    }

    /** POST /mail-client/api/calendar/add-event */
    public function apiCalendarAddEvent(Request $request): Response
    {
        $user = $this->requireUser(true);
        if ($user === null) {
            return $this->jsonError('Nicht angemeldet.', 401);
        }
        $userId = (int) $user['id'];

        $title       = trim((string) ($request->input('title') ?? $request->inputRaw('title') ?? 'Neuer Termin'));
        $description = trim((string) ($request->input('description') ?? $request->inputRaw('description') ?? ''));
        $location    = trim((string) ($request->input('location') ?? $request->inputRaw('location') ?? ''));
        $startAtStr  = trim((string) ($request->input('start_at') ?? $request->inputRaw('start_at') ?? ''));
        $endAtStr    = trim((string) ($request->input('end_at') ?? $request->inputRaw('end_at') ?? ''));
        $allDay      = (bool) ($request->input('all_day') ?? $request->inputRaw('all_day') ?? false);
        $rrule       = trim((string) ($request->input('recurrence_rule') ?? $request->inputRaw('recurrence_rule') ?? ''));
        $calendarId  = (int) ($request->input('calendar_id') ?? $request->inputRaw('calendar_id') ?? 0);

        if ($startAtStr === '') {
            return $this->jsonError('Startdatum fehlt.', 400);
        }

        $startTs = strtotime($startAtStr);
        if ($startTs === false) {
            return $this->jsonError('Ungültiges Startdatum.', 400);
        }

        if ($endAtStr === '') {
            $endAtStr = $allDay
                ? date('Y-m-d 23:59:59', $startTs)
                : date('Y-m-d H:i:s', $startTs + 3600);
        }

        $endTs = strtotime($endAtStr);
        if ($endTs === false) {
            $endTs = $allDay ? strtotime(date('Y-m-d 23:59:59', $startTs)) : $startTs + 3600;
        }

        $startAtNorm = $allDay ? date('Y-m-d 00:00:00', $startTs) : date('Y-m-d H:i:s', $startTs);
        $endAtNorm   = $allDay ? date('Y-m-d 23:59:59', $endTs) : date('Y-m-d H:i:s', $endTs);

        try {
            $pdo = $this->getPdo();
            if ($pdo === null) {
                return $this->jsonError('Datenbankverbindung nicht verfügbar.', 500);
            }

            $checkTable = $pdo->query("SHOW TABLES LIKE 'calendar_appointments'")->fetchColumn();
            if (!$checkTable) {
                return $this->jsonError('Kalender-Modul ist nicht installiert oder Tabellen fehlen.', 400);
            }

            // Kalender ermitteln (entweder angegebener, oder Standard-Kalender)
            $calStmt = $pdo->prepare('SELECT id, name, color, source FROM calendars WHERE user_id = ? AND (id = ? OR is_default = 1) ORDER BY (id = ?) DESC, is_default DESC LIMIT 1');
            $calStmt->execute([$userId, $calendarId, $calendarId]);
            $calRow = $calStmt->fetch(\PDO::FETCH_ASSOC);

            if (!$calRow) {
                // Kalender anlegen falls keiner existiert
                $insCal = $pdo->prepare('INSERT INTO calendars (user_id, name, color, is_visible, is_default, source) VALUES (?, ?, ?, 1, 1, "local")');
                $insCal->execute([$userId, 'Mein Kalender', '#3B82F6']);
                $calId    = (int) $pdo->lastInsertId();
                $calName  = 'Mein Kalender';
                $calColor = '#3B82F6';
            } else {
                $calId    = (int) $calRow['id'];
                $calName  = (string) $calRow['name'];
                $calColor = (string) ($calRow['color'] ?? '#3B82F6');
            }

            // Prüfen, ob Termin bereits existiert
            $dupStmt = $pdo->prepare('SELECT id FROM calendar_appointments WHERE user_id = ? AND calendar_id = ? AND title = ? AND start_at = ? LIMIT 1');
            $dupStmt->execute([$userId, $calId, $title, $startAtNorm]);
            $existingId = $dupStmt->fetchColumn();
            if ($existingId) {
                return $this->json([
                    'success'        => true,
                    'appointment_id' => (int) $existingId,
                    'calendar_id'    => $calId,
                    'calendar_name'  => $calName,
                    'already_existed'=> true,
                    'message'        => 'Termin war in diesem Kalender bereits vorhanden.',
                ]);
            }

            $insertStmt = $pdo->prepare('
                INSERT INTO calendar_appointments 
                (user_id, calendar_id, title, description, location, start_at, end_at, all_day, color, recurrence_rule)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $insertStmt->execute([
                $userId,
                $calId,
                $title,
                $description !== '' ? $description : null,
                $location !== '' ? $location : null,
                $startAtNorm,
                $endAtNorm,
                $allDay ? 1 : 0,
                $calColor,
                $rrule !== '' ? $rrule : null,
            ]);

            $aptId = (int) $pdo->lastInsertId();

            return $this->json([
                'success'        => true,
                'appointment_id' => $aptId,
                'calendar_id'    => $calId,
                'calendar_name'  => $calName,
                'already_existed'=> false,
                'message'        => 'Termin erfolgreich in den Kalender "' . $calName . '" eingetragen.',
            ]);
        } catch (\Throwable $e) {
            return $this->jsonError('Termin konnte nicht gespeichert werden: ' . $e->getMessage(), 500);
        }
    }

    // =========================================================================
    // Progressive Web App (PWA)
    // =========================================================================

    /** GET /mail-client/manifest.json */
    public function pwaManifest(Request $request): Response
    {
        $manifest = [
            "name"             => "Modulon Mail",
            "short_name"       => "Mail",
            "description"      => "Mail-Client für Modulon",
            "start_url"        => "/mail-client",
            "scope"            => "/mail-client",
            "id"               => "/mail-client",
            "display"          => "standalone",
            "orientation"      => "any",
            "background_color" => "#ffffff",
            "theme_color"      => "#0d6efd",
            "icons"            => [
                [
                    "src"     => "/mail-client/icon.svg",
                    "sizes"   => "any",
                    "type"    => "image/svg+xml",
                    "purpose" => "any",
                ],
                [
                    "src"     => "/mail-client/icon-192.png",
                    "sizes"   => "192x192",
                    "type"    => "image/png",
                    "purpose" => "any",
                ],
                [
                    "src"     => "/mail-client/icon-512.png",
                    "sizes"   => "512x512",
                    "type"    => "image/png",
                    "purpose" => "any",
                ],
                [
                    "src"     => "/mail-client/icon-512.png",
                    "sizes"   => "512x512",
                    "type"    => "image/png",
                    "purpose" => "maskable",
                ],
            ],
        ];

        return new Response(
            json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            200,
            ["Content-Type" => "application/manifest+json; charset=utf-8", "Cache-Control" => "no-cache"]
        );
    }

    /** GET /mail-client/sw.js */
    public function pwaServiceWorker(Request $request): Response
    {
        $swPath = dirname(__DIR__) . "/assets/js/sw.js";
        $content = file_exists($swPath) ? (string) file_get_contents($swPath) : "";

        return new Response(
            $content,
            200,
            [
                "Content-Type"           => "application/javascript; charset=utf-8",
                "Service-Worker-Allowed" => "/",
                "Cache-Control"          => "no-cache",
            ]
        );
    }

    /** GET /mail-client/icon.svg */
    public function pwaIcon(Request $request): Response
    {
        $path = dirname(__DIR__) . "/assets/icons/mail-icon.svg";
        $content = file_exists($path) ? (string) file_get_contents($path) : "";

        return new Response($content, 200, ["Content-Type" => "image/svg+xml", "Cache-Control" => "public, max-age=86400"]);
    }

    /** GET /mail-client/icon-192.png */
    public function pwaIcon192(Request $request): Response
    {
        $path = dirname(__DIR__) . "/assets/icons/mail-icon-192.png";
        $content = file_exists($path) ? (string) file_get_contents($path) : "";

        return new Response($content, 200, ["Content-Type" => "image/png", "Cache-Control" => "public, max-age=86400"]);
    }

    /** GET /mail-client/icon-512.png */
    public function pwaIcon512(Request $request): Response
    {
        $path = dirname(__DIR__) . "/assets/icons/mail-icon-512.png";
        $content = file_exists($path) ? (string) file_get_contents($path) : "";

        return new Response($content, 200, ["Content-Type" => "image/png", "Cache-Control" => "public, max-age=86400"]);
    }

    /** POST /mail-client/api/accounts/reorder */
    public function apiAccountsReorder(Request $request): Response
    {
        $user = $this->requireUser(true);
        if ($user === null) {
            return $this->jsonError('Nicht angemeldet.', 401);
        }
        $userId = (int) $user['id'];
        $rawOrder = $request->inputRaw('order');
        if (!is_array($rawOrder) || empty($rawOrder)) {
            $raw = file_get_contents('php://input');
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded) && !empty($decoded['order'])) {
                $rawOrder = $decoded['order'];
            }
        }
        $order = is_array($rawOrder) ? $rawOrder : [];
        if (empty($order)) {
            return $this->jsonError('Keine Konten-Sortierung angegeben.', 400);
        }
        $accountIds = array_values(array_filter(array_map('intval', $order), static fn (int $id) => $id > 0));
        if (empty($accountIds)) {
            return $this->jsonError('Ungültige Konten-IDs angegeben.', 400);
        }
        $this->accounts->updateSortOrder($userId, $accountIds);
        return $this->json(['success' => true]);
    }

    /** GET /mail-client/api/accounts/{id}/aliases */
    public function apiAccountAliases(Request $request): Response
    {
        $user = $this->requireUser(true);
        if ($user === null) {
            return $this->jsonError('Nicht angemeldet.', 401);
        }
        $userId    = (int) $user['id'];
        $accountId = (int) $this->extractId($request->path(), '/mail-client/api/accounts/', '/aliases');
        if ($accountId === 0 || $this->aliasRepo === null) {
            return $this->json(['aliases' => []]);
        }
        $aliases = $this->aliasRepo->listForAccount($accountId, $userId);
        return $this->json(['aliases' => $aliases]);
    }

}
