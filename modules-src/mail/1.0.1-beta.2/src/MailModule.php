<?php

declare(strict_types=1);

namespace ModulNest\Mail;

use Modulon\Core\AdminNavigationRegistry;
use Modulon\Core\ModuleContext;
use Modulon\Core\ModuleSubnavigationRegistry;
use Modulon\Core\NativeModuleInterface;
use Modulon\Core\Router;
use Modulon\Core\UserNavigationRegistry;

final class MailModule implements NativeModuleInterface
{
    public static function metadata(): array
    {
        return [
            'key' => 'mail',
            'name' => 'Mail',
            'route_prefix' => 'mail',
            'access_level' => 'user',
            'description' => 'Multi-Account-Webmail-Client für IMAP/SMTP.',
            'show_in_header' => true,
            'show_on_home' => true,
        ];
    }

    public static function create(ModuleContext $context): ?NativeModuleInterface
    {
        if ($context->pdo === null) {
            return null;
        }

        $controller = new MailController(
            new MailRepository($context->pdo),
            $context->session,
            $context->service('authService'),
        );

        return new self($controller, $context->moduleAccess('mail', 'user'));
    }

    public function __construct(
        private readonly MailController $controller,
        private readonly string $access,
    ) {
    }

    public function key(): string
    {
        return 'mail';
    }

    public function routePrefix(): string
    {
        return 'mail';
    }

    public function registerNavigation(ModuleSubnavigationRegistry $moduleNavigation, AdminNavigationRegistry $adminNavigation, UserNavigationRegistry $userNavigation): void
    {
    }

    public function registerRoutes(Router $router): void
    {
        $router->get('/mail', [$this->controller, 'index'], $this->access);
        $router->get('/mail/*', [$this->controller, 'subRoute'], $this->access);
        $router->post('/mail/accounts', [$this->controller, 'createAccount'], 'user');
        $router->post('/mail/accounts/*', [$this->controller, 'accountPostSubRoute'], 'user');
        $router->post('/mail/messages/*', [$this->controller, 'messagePostSubRoute'], 'user');
    }

    public function registerAdminRoutes(Router $router): void
    {
    }

    public function nativeBinding(): array
    {
        return [
            'module_key' => 'mail',
            'internal_name' => 'Mail',
            'controller' => MailController::class,
            'implementation_path' => 'src/MailController.php',
            'route_binding' => 'GET /mail, GET /mail/*, POST /mail/accounts, POST /mail/accounts/*',
        ];
    }
}
