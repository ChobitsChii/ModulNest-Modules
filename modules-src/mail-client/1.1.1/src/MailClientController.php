<?php

declare(strict_types=1);

namespace ModulNest\MailClient;

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
    ) {
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
        $success  = $this->session->pullFlash('mc_success');

        return new Response(View::render('@modulnest.mail-client/client', [
            'title'        => 'Mail-Client',
            'current_path' => '/mail-client',
            'accounts'     => $accounts,
            'mail_success' => $success,
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

        return new Response(View::render('@modulnest.mail-client/account-form', [
            'title'        => 'Mailkonto hinzufügen',
            'current_path' => '/mail-client/accounts/create',
            'account'      => null,
            'old'          => $old,
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

        return new Response(View::render('@modulnest.mail-client/account-form', [
            'title'        => 'Mailkonto bearbeiten',
            'current_path' => '/mail-client/accounts/' . $accountId . '/edit',
            'account'      => $account,
            'old'          => [],
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
        $accountId = (int) $request->query('account', '0');
        $folder    = (string) $request->query('folder', 'INBOX');
        $uid       = (int) $request->query('uid', '0');

        if ($accountId === 0 || $uid === 0) {
            return $this->notFound($request);
        }

        $account = $this->accounts->findForUser($accountId, $userId);
        if ($account === null) {
            return $this->notFound($request);
        }

        try {
            $password    = $this->decryptPassword((string) $account['encrypted_password']);
            $accountConn = array_merge($account, ['password' => $password]);
            $messageData = $this->messageFetcher->fetchByUid($accountConn, $folder, $uid);
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

        return new Response(View::render('@modulnest.mail-client/compose', [
            'title'            => 'Neue E-Mail',
            'current_path'     => '/mail-client/compose',
            'accounts'         => $accounts,
            'error'            => $error,
            'success'          => $success,
            'reply_to'         => (string) $request->query('reply_to', ''),
            'reply_subject'    => (string) $request->query('subject', ''),
            'reply_account_id' => (int) $request->query('account', '0'),
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
            return Response::redirect('/mail-client/accounts/create');
        }

        try {
            $this->accounts->create($userId, $data);
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
        $userId    = (int) $user['id'];
        $accountId = (int) $request->input('account_id', '0');
        $account   = $accountId > 0 ? $this->accounts->findForUser($accountId, $userId) : null;

        if ($account === null) {
            $this->session->flash('mc_error', 'Ungültiges Absenderkonto.');
            return Response::redirect('/mail-client/compose');
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
                ['to' => [$to], 'cc' => $cc !== '' ? [$cc] : []],
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
        $user = $this->requireUser();
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
            $folders     = $this->syncService->listFolders($accountConn);

            $cached   = $this->folderCache->listFoldersForAccount($accountId);
            $cacheMap = [];
            foreach ($cached as $cf) {
                $cacheMap[(string) $cf['folder_name']] = $cf;
            }

            $result = array_map(static function (array $f) use ($cacheMap): array {
                $name   = $f['name'];
                $cached = $cacheMap[$name] ?? [];
                return [
                    'name'    => $name,
                    'unseen'  => (int) ($cached['unseen_messages'] ?? 0),
                    'total'   => (int) ($cached['total_messages'] ?? 0),
                    'special' => self::getFolderSpecialType($name),
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
        $user = $this->requireUser();
        if ($user === null) {
            return $this->jsonError('Nicht angemeldet.', 401);
        }
        $userId    = (int) $user['id'];
        $accountId = (int) $request->query('account', '0');
        $folder    = (string) $request->query('folder', 'INBOX');
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

            foreach ($result['messages'] as &$msg) {
                $msg['date_formatted'] = $this->formatMessageDate((int) $msg['message_date']);
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
        $user = $this->requireUser();
        if ($user === null) {
            return $this->jsonError('Nicht angemeldet.', 401);
        }
        $userId    = (int) $user['id'];
        $accountId = (int) $request->query('account', '0');
        $folder    = (string) $request->query('folder', 'INBOX');
        $uid       = (int) $request->query('uid', '0');
        $account   = $accountId > 0 ? $this->accounts->findForUser($accountId, $userId) : null;

        if ($account === null || $uid === 0) {
            return $this->jsonError('Ungültige Parameter.', 400);
        }

        try {
            $password    = $this->decryptPassword((string) $account['encrypted_password']);
            $accountConn = array_merge($account, ['password' => $password]);
            $msgData     = $this->messageFetcher->fetchByUid($accountConn, $folder, $uid);
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

        return $this->json([
            'uid'            => $uid,
            'subject'        => $msgData['subject'],
            'from'           => $msgData['from'],
            'from_name'      => $msgData['from_name'],
            'to'             => $msgData['to'],
            'cc'             => $msgData['cc'],
            'date'           => $msgData['date'],
            'date_ts'        => $msgData['date_ts'],
            'html'           => $sanitized['html'],
            'has_html'       => $msgData['has_html'],
            'blocked_images' => $sanitized['blocked_images'],
            'allow_external' => $allowExternal,
            'attachments'    => $msgData['attachments'],
            'account_id'     => $accountId,
            'folder'         => $folder,
        ]);
    }

    /** POST /mail-client/api/messages/flag */
    public function apiFlag(Request $request): Response
    {
        $user = $this->requireUser();
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
                foreach ($client->getFolders(false) as $f) {
                    if (is_object($f) && strtolower(trim((string) ($f->path ?? $f->name ?? ''))) === strtolower($folder)) {
                        $f->query();
                        $proto  = $client->getConnection();
                        $prefix = $setFlag ? '+FLAGS' : '-FLAGS';
                        $proto->store([(string) $uid], $prefix, $imapFlag, true);
                        break;
                    }
                }
            } finally {
                $client->disconnect();
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
        $user = $this->requireUser();
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
        $user = $this->requireUser();
        if ($user === null) {
            return Response::redirect('/login');
        }
        $userId    = (int) $user['id'];
        $accountId = (int) $request->query('account', '0');
        $folder    = (string) $request->query('folder', 'INBOX');
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
        $user = $this->requireUser();
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
    private function requireUser(): ?array
    {
        $user = $this->auth?->currentUser();
        return is_array($user) ? $user : null;
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
                foreach ($client->getFolders(false) as $f) {
                    if (is_object($f) && strtolower(trim((string) ($f->path ?? $f->name ?? ''))) === strtolower($folder)) {
                        $f->query();
                        $client->getConnection()->store([(string) $uid], '+FLAGS', '\\Seen', true);
                        break;
                    }
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

    private function formatMessageDate(int $timestamp): string
    {
        if ($timestamp === 0) {
            return '';
        }
        $today = mktime(0, 0, 0, (int) date('n'), (int) date('j'), (int) date('Y'));
        $diff  = time() - $timestamp;

        if ($timestamp >= $today) {
            return date('H:i', $timestamp);
        }
        if ($diff < 7 * 86400) {
            $days = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
            return $days[(int) date('w', $timestamp)];
        }
        if (date('Y', $timestamp) === date('Y')) {
            return date('d.m.', $timestamp);
        }
        return date('d.m.Y', $timestamp);
    }

    private static function getFolderSpecialType(string $name): string
    {
        $lower = strtolower($name);
        return match (true) {
            $lower === 'inbox'                                                        => 'inbox',
            in_array($lower, ['sent', 'sent items', 'gesendet', 'sent-mail'])        => 'sent',
            in_array($lower, ['drafts', 'draft', 'entwürfe'])                        => 'drafts',
            in_array($lower, ['trash', 'deleted', 'papierkorb', 'deleted items', 'bin']) => 'trash',
            in_array($lower, ['spam', 'junk', 'junk e-mail'])                        => 'spam',
            in_array($lower, ['archive', 'archiv'])                                  => 'archive',
            default                                                                   => 'folder',
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
        $user = $this->requireUser();
        if ($user === null) {
            return $this->jsonError('Nicht angemeldet.', 401);
        }
        $userId    = (int) $user['id'];
        $accountId = (int) $request->query('account', '0');
        $folder    = (string) $request->query('folder', 'INBOX');
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
        $user = $this->requireUser();
        if ($user === null) {
            return $this->jsonError('Nicht angemeldet.', 401);
        }
        $userId    = (int) $user['id'];
        $accountId = (int) $request->query('account', '0');
        $folder    = (string) $request->query('folder', 'INBOX');
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
}
