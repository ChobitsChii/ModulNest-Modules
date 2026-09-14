<?php

declare(strict_types=1);

namespace ModulNest\Mirror;

use ModulNest\Mirror\Portability\MirrorDataPortabilityProvider;
use ModulNest\Mirror\Repository\MirrorConfigRepository;
use ModulNest\Mirror\Service\MirrorPathResolver;
use ModulNest\Mirror\Service\MirrorSyncService;
use Modulon\Core\AdminNavigationRegistry;
use Modulon\Core\ModuleContext;
use Modulon\Core\ModuleSubnavigationRegistry;
use Modulon\Core\NativeModuleInterface;
use Modulon\Core\Router;
use Modulon\Core\UserNavigationRegistry;

final class MirrorModule implements NativeModuleInterface
{
    public static function metadata(): array
    {
        return [
            'key' => 'mirror',
            'name' => 'Mirror Manager',
            'route_prefix' => 'mirror',
            'access_level' => 'admin',
            'description' => 'Multi-Mirror-Verwaltung für Modul-Repositories und Core-Updates (GitHub-Quellen, Verzeichnis-Explorer, Hintergrund-Sync und Cronjobs).',
            'show_in_header' => false,
            'show_on_home' => false,
        ];
    }

    public static function create(ModuleContext $context): ?NativeModuleInterface
    {
        $pdo = $context->pdo;
        $repo = new MirrorConfigRepository($pdo);
        $syncService = new MirrorSyncService($context->basePath, $repo);
        $pathResolver = new MirrorPathResolver();

        $controller = new MirrorController(
            $context->session,
            $repo,
            $syncService,
            $pathResolver,
            $context->basePath
        );

        $portability = $context->service('dataPortabilityRegistry');
        if ($portability instanceof \Modulon\Core\Modules\DataPortability\DataPortabilityRegistry) {
            $portability->register(new MirrorDataPortabilityProvider($pdo));
        }

        return new self($controller, $context->moduleRow('modulnest.mirror') ?? $context->moduleRow('mirror'));
    }

    public function __construct(
        private readonly MirrorController $controller,
        private readonly ?array $moduleRow = null,
    ) {
    }

    public function key(): string
    {
        return 'modulnest.mirror';
    }

    public function routePrefix(): string
    {
        return 'mirror';
    }

    public function registerNavigation(ModuleSubnavigationRegistry $moduleNavigation, AdminNavigationRegistry $adminNavigation, UserNavigationRegistry $userNavigation): void
    {
        $adminNavigation->registerProvider(new MirrorAdminNavigationProvider());
    }

    public function registerRoutes(Router $router): void
    {
    }

    public function registerAdminRoutes(Router $router): void
    {
        $router->get('/admin/mirror', [$this->controller, 'index'], 'admin');
        $router->post('/admin/mirror/save', [$this->controller, 'save'], 'admin');
        $router->post('/admin/mirror/toggle', [$this->controller, 'toggle'], 'admin');
        $router->post('/admin/mirror/delete', [$this->controller, 'delete'], 'admin');
        $router->post('/admin/mirror/sync', [$this->controller, 'sync'], 'admin');
        $router->get('/admin/mirror/status', [$this->controller, 'status'], 'admin');
        $router->get('/admin/mirror/directories', [$this->controller, 'directories'], 'admin');
    }

    public function nativeBinding(): array
    {
        return [
            'module_key' => 'modulnest.mirror',
            'internal_name' => 'Mirror Manager',
            'controller' => MirrorController::class,
            'implementation_path' => 'modules/modulnest.mirror/releases/0.1.0-beta.1-f7a93c41b802/src/MirrorController.php',
            'route_binding' => 'GET /admin/mirror, POST /admin/mirror/save, POST /admin/mirror/toggle, POST /admin/mirror/delete, POST /admin/mirror/sync, GET /admin/mirror/status, GET /admin/mirror/directories',
        ];
    }
}
