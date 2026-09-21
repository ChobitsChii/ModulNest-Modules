<?php

declare(strict_types=1);

namespace ModulNest\MailClient;

use Modulon\Core\AdminNavigationRegistry;
use Modulon\Core\ModuleContext;
use Modulon\Core\ModuleSubnavigationRegistry;
use Modulon\Core\NativeModuleInterface;
use Modulon\Core\Router;
use Modulon\Core\UserNavigationRegistry;
use ModulNest\MailClient\Autoconfig\MailAutoconfigService;
use ModulNest\MailClient\Imap\ImapMessageFetcher;
use ModulNest\MailClient\Imap\ImapSyncService;
use ModulNest\MailClient\Repository\AccountRepository;
use ModulNest\MailClient\Repository\AliasRepository;

use ModulNest\MailClient\Repository\FolderCacheRepository;
use ModulNest\MailClient\Repository\MessageIndexRepository;
use ModulNest\MailClient\Security\MailBodySanitizer;
use ModulNest\MailClient\Smtp\SmtpSender;
use ModulNest\MailClient\Sync\SyncProgressRepository;

final class MailClientModule implements NativeModuleInterface
{
    public static function metadata(): array
    {
        return [
            'key'            => 'mail-client',
            'name'           => 'Mail-Client',
            'route_prefix'   => 'mail-client',
            'access_level'   => 'user',
            'description'    => 'Thunderbird-artiger Webmail-Client mit Multi-Account-IMAP, 3-Pane-Layout und SMTP-Versand.',
            'show_in_header' => false,
            'show_on_home'   => true,
        ];
    }

    public static function create(ModuleContext $context): ?NativeModuleInterface
    {
        if ($context->pdo === null) {
            return null;
        }

        $accountRepo      = new AccountRepository($context->pdo);
        $messageRepo      = new MessageIndexRepository($context->pdo);
        $folderCacheRepo  = new FolderCacheRepository($context->pdo);
        $syncProgressRepo = new SyncProgressRepository($context->pdo);
        $syncService      = new ImapSyncService($messageRepo, $folderCacheRepo, $syncProgressRepo);
        $messageFetcher   = new ImapMessageFetcher();
        $sanitizer        = new MailBodySanitizer();
        $smtpSender       = new SmtpSender();
        $autoconfigSvc    = new MailAutoconfigService();
        $aliasRepo        = new AliasRepository($context->pdo);

        $controller = new MailClientController(
            $accountRepo,
            $messageRepo,
            $folderCacheRepo,
            $syncService,
            $messageFetcher,
            $sanitizer,
            $smtpSender,
            $context->session,
            $autoconfigSvc,
            $context->service('authService'),
            $context->pdo,
            $aliasRepo,
        );

        return new self($controller, $context->moduleAccess('mail-client', 'user'));
    }

    public function __construct(
        private readonly MailClientController $controller,
        private readonly string $access,
    ) {
    }

    public function key(): string
    {
        return 'mail-client';
    }

    public function routePrefix(): string
    {
        return 'mail-client';
    }

    public function registerNavigation(
        ModuleSubnavigationRegistry $moduleNavigation,
        AdminNavigationRegistry     $adminNavigation,
        UserNavigationRegistry      $userNavigation
    ): void {
        // Keine Subnavigation – die gesamte Navigation läuft im 3-Pane-Layout
    }

    public function registerRoutes(Router $router): void
    {
        // ── HTML-Seiten ──────────────────────────────────────────────────────
        $router->get('/mail-client',                        [$this->controller, 'index'],            $this->access);
        $router->get('/mail-client/accounts/create',        [$this->controller, 'accountCreateForm'], $this->access);
        $router->get('/mail-client/accounts/*',             [$this->controller, 'accountEditForm'],   $this->access);
        $router->get('/mail-client/message',                [$this->controller, 'messageFullView'],   $this->access);
        $router->get('/mail-client/compose',                [$this->controller, 'composeForm'],       $this->access);

        // ── POST Formular-Aktionen ────────────────────────────────────────────
        $router->post('/mail-client/accounts',              [$this->controller, 'accountCreate'],     $this->access);
        $router->post('/mail-client/accounts/*',            [$this->controller, 'accountPost'],       $this->access);
        $router->post('/mail-client/compose',               [$this->controller, 'composeSend'],       $this->access);

        // ── AJAX-API (JSON) ──────────────────────────────────────────────────
        $router->get('/mail-client/api/folders',            [$this->controller, 'apiFolders'],        $this->access);
        $router->get('/mail-client/api/messages',           [$this->controller, 'apiMessages'],       $this->access);
        $router->get('/mail-client/api/message-body',       [$this->controller, 'apiMessageBody'],    $this->access);
        $router->get('/mail-client/api/attachment',         [$this->controller, 'apiAttachment'],     $this->access);
        $router->get('/mail-client/api/autoconfig',         [$this->controller, 'apiAutoconfig'],     $this->access);
        $router->get('/mail-client/api/calendar/calendars', [$this->controller, 'apiCalendarList'], $this->access);
        $router->get('/mail-client/api/calendar/upcoming', [$this->controller, 'apiCalendarUpcoming'], $this->access);
        $router->post('/mail-client/api/calendar/add-event', [$this->controller, 'apiCalendarAddEvent'], $this->access, 'exempt');
        $router->get('/mail-client/api/sync/status',        [$this->controller, 'apiSyncStatus'],     $this->access);
        $router->post('/mail-client/api/sync/continue',     [$this->controller, 'apiSyncContinue'],   $this->access, 'exempt');
        $router->post('/mail-client/api/sync/refresh',      [$this->controller, 'apiSyncRefresh'],    $this->access, 'exempt');
        $router->post('/mail-client/api/messages/flag',     [$this->controller, 'apiFlag'],           $this->access, 'exempt');
        $router->post('/mail-client/api/messages/delete',   [$this->controller, 'apiDelete'],         $this->access, 'exempt');
        $router->get('/mail-client/api/whitelist',          [$this->controller, 'apiWhitelistList'],    $this->access);
        $router->post('/mail-client/api/whitelist',         [$this->controller, 'apiWhitelistAdd'],     $this->access, 'exempt');
        $router->post('/mail-client/api/whitelist/delete',  [$this->controller, 'apiWhitelistDelete'],  $this->access, 'exempt');
        $router->post('/mail-client/api/accounts/reorder',  [$this->controller, 'apiAccountsReorder'], $this->access, 'exempt');
        $router->get('/mail-client/api/accounts/*/aliases', [$this->controller, 'apiAccountAliases'], $this->access);

        // ── Progressive Web App (PWA) ────────────────────────────────────────
        $router->get('/mail-client/manifest.json', [$this->controller, 'pwaManifest'],     'public');
        $router->get('/mail-client/sw.js',         [$this->controller, 'pwaServiceWorker'], 'public');
        $router->get('/mail-client/icon.svg',      [$this->controller, 'pwaIcon'],          'public');
        $router->get('/mail-client/icon-192.png',  [$this->controller, 'pwaIcon192'],       'public');
        $router->get('/mail-client/icon-512.png',  [$this->controller, 'pwaIcon512'],       'public');
    }

    public function registerAdminRoutes(Router $router): void
    {
    }

    public function nativeBinding(): array
    {
        return [
            'module_key'          => 'mail-client',
            'internal_name'       => 'Mail-Client',
            'controller'          => MailClientController::class,
            'implementation_path' => 'src/MailClientController.php',
            'route_binding'       => 'GET /mail-client, GET /mail-client/compose, GET /mail-client/message, GET/POST /mail-client/accounts/*, GET /mail-client/api/*, POST /mail-client/api/sync/continue, POST /mail-client/api/sync/refresh',
        ];
    }
}
