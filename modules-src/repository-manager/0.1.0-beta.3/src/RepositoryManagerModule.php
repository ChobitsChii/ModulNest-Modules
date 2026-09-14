<?php

declare(strict_types=1);

namespace ModulNest\RepositoryManager;

use Modulon\Core\AdminNavigationRegistry;
use Modulon\Core\ModuleContext;
use Modulon\Core\ModuleSubnavigationRegistry;
use Modulon\Core\NativeModuleInterface;
use Modulon\Core\Router;
use Modulon\Core\UserNavigationRegistry;
use Modulon\Core\Modules\Catalog\CatalogSourceRegistry;
use Modulon\Modules\Auth\AuthService;

final class RepositoryManagerModule implements NativeModuleInterface
{
    public static function metadata(): array
    {
        return [
            'key' => 'modulnest.repository-manager',
            'name' => 'Repository Manager',
            'route_prefix' => 'repository-manager',
            'access_level' => 'admin',
            'description' => 'Verwaltet Modul-Katalogquellen und synchronisiert das ModulNest-Repository (Beta 3).',
            'show_in_header' => false,
            'show_on_home' => false,
        ];
    }

    public static function create(ModuleContext $context): ?NativeModuleInterface
    {
        $registry = $context->catalogSources();
        if (!$registry instanceof CatalogSourceRegistry) {
            return null;
        }

        $auth = $context->service('authService');
        $mirrorService = new RepositoryMirrorService($context->basePath);

        return new self(new RepositoryManagerController(
            $context->session,
            $registry,
            $mirrorService,
            $auth instanceof AuthService ? $auth : null,
        ));
    }

    public function __construct(private readonly RepositoryManagerController $controller)
    {
    }

    public function key(): string
    {
        return 'modulnest.repository-manager';
    }

    public function routePrefix(): string
    {
        return 'repository-manager';
    }

    public function registerNavigation(
        ModuleSubnavigationRegistry $moduleNavigation,
        AdminNavigationRegistry $adminNavigation,
        UserNavigationRegistry $userNavigation,
    ): void {
        $adminNavigation->registerProvider(new RepositoryManagerAdminNavigationProvider());
    }

    public function registerRoutes(Router $router): void
    {
        // Dieses Modul besitzt ausschließlich einen Admin-Bereich.
    }

    public function registerAdminRoutes(Router $router): void
    {
        $router->get('/admin/repository-manager', [$this->controller, 'index'], 'admin');
        $router->post('/admin/repository-manager/add', [$this->controller, 'add'], 'admin');
        $router->post('/admin/repository-manager/update', [$this->controller, 'update'], 'admin');
        $router->post('/admin/repository-manager/enable', [$this->controller, 'enable'], 'admin');
        $router->post('/admin/repository-manager/disable', [$this->controller, 'disable'], 'admin');
        $router->post('/admin/repository-manager/test', [$this->controller, 'test'], 'admin');
        $router->post('/admin/repository-manager/sync', [$this->controller, 'sync'], 'admin');
        $router->get('/admin/repository-manager/mirror-status', [$this->controller, 'mirrorStatus'], 'admin');
    }

    public function nativeBinding(): array
    {
        return [
            'module_key' => 'modulnest.repository-manager',
            'internal_name' => 'Repository Manager',
            'controller' => RepositoryManagerController::class,
            'implementation_path' => __FILE__,
            'route_binding' => 'GET /admin/repository-manager',
        ];
    }
}
