<?php

declare(strict_types=1);

namespace ModulNest\Dashboard;

use Modulon\Core\AdminNavigationRegistry;
use Modulon\Core\HealthCheckProviderInterface;
use Modulon\Core\HealthCheckRegistry;
use Modulon\Core\Modules\CapabilityRegistry;
use Modulon\Core\ModuleContext;
use Modulon\Core\ModuleSubnavigationRegistry;
use Modulon\Core\NativeModuleInterface;
use Modulon\Core\Router;
use Modulon\Core\UserNavigationRegistry;

final class DashboardModule implements NativeModuleInterface, HealthCheckProviderInterface
{
    public static function metadata(): array
    {
        return [
            'key' => 'modulnest.dashboard',
            'name' => 'Dashboard',
            'route_prefix' => 'dashboard',
            'access_level' => 'user',
            'description' => 'Persönliches Dashboard.',
            'show_in_header' => true,
            'show_on_home' => true,
        ];
    }

    public static function create(ModuleContext $context): ?NativeModuleInterface
    {
        if ($context->pdo === null) {
            return null;
        }
        $faviconStorage = $context->basePath . '/storage/modules/modulnest.dashboard/favicons';
        if (!is_dir($faviconStorage)) @mkdir($faviconStorage, 0770, true);

        $capabilities = $context->service('capabilityRegistry');
        if ($capabilities instanceof CapabilityRegistry) {
            $capabilities->registerInstance('modulnest.dashboard', 'data_portability', new DashboardDataPortabilityProvider($context->pdo));
        }

        $controller = new DashboardController(
            new DashboardRepository($context->pdo),
            $context->session,
            $context->service('authService'),
            $context->service('userRepository'),
            $context->basePath,
        );

        return new self($controller, $context->moduleAccess('dashboard', 'user'), $context->basePath);
    }

    public function __construct(
        private readonly DashboardController $controller,
        private readonly string $access,
        private readonly string $basePath,
    ) {
    }

    public function key(): string
    {
        return 'modulnest.dashboard';
    }

    public function routePrefix(): string
    {
        return 'dashboard';
    }

    public function registerNavigation(ModuleSubnavigationRegistry $moduleNavigation, AdminNavigationRegistry $adminNavigation, UserNavigationRegistry $userNavigation): void
    {
    }

    public function registerHealthChecks(HealthCheckRegistry $healthChecks): void
    {
        $healthChecks->addWritableDirectory(
            'dir_modulnest_dashboard_favicons',
            'Privater Dashboard-Favicon-Storage',
            $this->basePath . '/storage/modules/modulnest.dashboard/favicons',
            'error',
        );
    }

    public function registerRoutes(Router $router): void
    {
        $router->get('/dashboard', [$this->controller, 'index'], $this->access);
        $router->get('/dashboard/favicons/*', [$this->controller, 'serveFavicon'], 'user');
        $router->post('/dashboard/links/analyze', [$this->controller, 'analyzeLink'], 'user');
        $router->post('/dashboard/links/save', [$this->controller, 'storeLink'], 'user');
        $router->post('/dashboard/links/update', [$this->controller, 'updateLink'], 'user');
        $router->post('/dashboard/links/delete', [$this->controller, 'deleteLink'], 'user');
        $router->post('/dashboard/links/folders/create', [$this->controller, 'createFolder'], 'user');
        $router->post('/dashboard/widgets/create', [$this->controller, 'createWidget'], 'user');
        $router->post('/dashboard/widgets/update', [$this->controller, 'updateWidget'], 'user');
        $router->post('/dashboard/widgets/reorder', [$this->controller, 'reorderWidgets'], 'user');
        $router->post('/dashboard/widgets/delete', [$this->controller, 'deleteWidget'], 'user');
        $router->post('/dashboard/tasks/create', [$this->controller, 'createTask'], 'user');
        $router->post('/dashboard/tasks/update', [$this->controller, 'updateTask'], 'user');
        $router->post('/dashboard/tasks/delete', [$this->controller, 'deleteTask'], 'user');
        $router->post('/dashboard/tasks/toggle', [$this->controller, 'toggleTask'], 'user');
        $router->post('/dashboard/tasks/archive', [$this->controller, 'archiveTask'], 'user');
        $router->post('/dashboard/settings/auto-refresh', [$this->controller, 'updateAutoRefreshSettings'], 'user');
        $router->post('/dashboard/notes/create', [$this->controller, 'createNote'], 'user');
        $router->post('/dashboard/notes/update', [$this->controller, 'updateNote'], 'user');
        $router->post('/dashboard/notes/delete', [$this->controller, 'deleteNote'], 'user');
        $router->post('/dashboard/notes/archive', [$this->controller, 'archiveNote'], 'user');
    }

    public function registerAdminRoutes(Router $router): void
    {
    }

    public function nativeBinding(): array
    {
        return [
            'module_key' => 'modulnest.dashboard',
            'internal_name' => 'Dashboard',
            'controller' => DashboardController::class,
            'implementation_path' => __FILE__,
            'route_binding' => 'GET /dashboard, POST /dashboard/widgets/*, POST /dashboard/links/*, POST /dashboard/tasks/create, POST /dashboard/tasks/toggle, POST /dashboard/tasks/archive, POST /dashboard/notes/*',
        ];
    }

    public static function healthCheck(): bool
    {
        return true;
    }
}
