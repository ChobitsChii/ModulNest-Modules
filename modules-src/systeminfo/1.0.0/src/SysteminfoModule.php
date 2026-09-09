<?php

declare(strict_types=1);

namespace ModulNest\Systeminfo;

use Modulon\Core\AdminNavigationRegistry;
use Modulon\Core\ModuleContext;
use Modulon\Core\ModuleSubnavigationRegistry;
use Modulon\Core\NativeModuleInterface;
use Modulon\Core\Router;
use Modulon\Core\UserNavigationRegistry;

final class SysteminfoModule implements NativeModuleInterface
{
    public static function metadata(): array
    {
        return [
            'key' => 'modulnest.systeminfo',
            'name' => 'Systeminfo',
            'route_prefix' => 'systeminfo',
            'access_level' => 'admin',
            'description' => 'Systeminformationen und Healthchecks.',
            'show_in_header' => true,
            'show_on_home' => true,
        ];
    }

    public static function create(ModuleContext $context): ?NativeModuleInterface
    {
        $moduleRepository = $context->service('moduleRepository');
        $settings = $context->service('appSettingRepository');
        if ($context->pdo === null || $moduleRepository === null || $settings === null) {
            return null;
        }

        $controller = new SysteminfoController(
            $context->pdo,
            $moduleRepository,
            $settings,
            $context->service('healthCheck'),
            $context->service('authService'),
            (array) $context->config('authConfig', []),
            [
                'version' => (string) $context->config('app_version', ''),
                'channel' => (string) $context->config('app_channel', ''),
                'product_name' => (string) $context->config('product_name', ''),
            ],
        );

        return new self($controller, $context->moduleAccess('systeminfo', 'admin'));
    }

    public static function healthCheck(): bool
    {
        return true;
    }

    public function __construct(
        private readonly SysteminfoController $controller,
        private readonly string $access,
    ) {
    }

    public function key(): string
    {
        return 'modulnest.systeminfo';
    }

    public function routePrefix(): string
    {
        return 'systeminfo';
    }

    public function registerNavigation(ModuleSubnavigationRegistry $moduleNavigation, AdminNavigationRegistry $adminNavigation, UserNavigationRegistry $userNavigation): void
    {
    }

    public function registerRoutes(Router $router): void
    {
        $router->get('/systeminfo', [$this->controller, 'index'], $this->access);
    }

    public function registerAdminRoutes(Router $router): void
    {
    }

    public function nativeBinding(): array
    {
        return [
            'module_key' => 'modulnest.systeminfo',
            'internal_name' => 'Systeminfo',
            'controller' => SysteminfoController::class,
            'implementation_path' => __FILE__,
            'route_binding' => 'GET /systeminfo',
        ];
    }
}
