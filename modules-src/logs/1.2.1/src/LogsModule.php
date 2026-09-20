<?php

declare(strict_types=1);

namespace ModulNest\Logs;

use Modulon\Core\AdminNavigationRegistry;
use Modulon\Core\ModuleContext;
use Modulon\Core\ModuleSubnavigationRegistry;
use Modulon\Core\NativeModuleInterface;
use Modulon\Core\Router;
use Modulon\Core\UserNavigationRegistry;

final class LogsModule implements NativeModuleInterface
{
    public static function metadata(): array
    {
        return [
            'key' => 'modulnest.logs',
            'name' => 'Logs',
            'route_prefix' => 'logs',
            'access_level' => 'admin',
            'description' => 'Admin-Logviewer für Modulon-Logdateien.',
            'show_in_header' => false,
            'show_on_home' => false,
        ];
    }

    public static function create(ModuleContext $context): ?NativeModuleInterface
    {
        $controller = new LogsController(
            $context->basePath,
            $context->service('authService'),
        );

        return new self($controller);
    }

    public static function healthCheck(): bool
    {
        return true;
    }

    public function __construct(private readonly LogsController $controller)
    {
    }

    public function key(): string
    {
        return 'modulnest.logs';
    }

    public function routePrefix(): string
    {
        return 'logs';
    }

    public function registerNavigation(ModuleSubnavigationRegistry $moduleNavigation, AdminNavigationRegistry $adminNavigation, UserNavigationRegistry $userNavigation): void
    {
        $adminNavigation->registerProvider(new LogsAdminNavigationProvider());
    }

    public function registerRoutes(Router $router): void
    {
    }

    public function registerAdminRoutes(Router $router): void
    {
        $router->get('/admin/logs', [$this->controller, 'index'], 'admin');
        $router->get('/admin/logs/*', [$this->controller, 'index'], 'admin');
    }

    public function nativeBinding(): array
    {
        return [
            'module_key' => 'modulnest.logs',
            'internal_name' => 'Logs',
            'controller' => LogsController::class,
            'implementation_path' => __FILE__,
            'route_binding' => 'GET /admin/logs, GET /admin/logs/*',
        ];
    }
}
