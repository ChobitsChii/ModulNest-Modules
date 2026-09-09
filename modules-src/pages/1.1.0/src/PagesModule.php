<?php

declare(strict_types=1);

namespace ModulNest\Pages;

use Modulon\Core\AdminNavigationRegistry;
use Modulon\Core\MarkdownRenderer;
use Modulon\Core\Modules\CapabilityRegistry;
use Modulon\Core\ModuleContext;
use Modulon\Core\ModuleSubnavigationRegistry;
use Modulon\Core\NativeModuleInterface;
use Modulon\Core\Router;
use Modulon\Core\UserNavigationRegistry;

final class PagesModule implements NativeModuleInterface
{
    /**
     * @return array{key:string,name:string,route_prefix:string,access_level:string,description:string,show_in_header:bool,show_on_home:bool}
     */
    public static function metadata(): array
    {
        return [
            'key' => 'modulnest.pages',
            'name' => 'Pages',
            'route_prefix' => 'pages',
            'access_level' => 'public',
            'description' => 'Einfache statische Seiten wie Impressum und Datenschutz.',
            'show_in_header' => false,
            'show_on_home' => false,
        ];
    }

    public static function create(ModuleContext $context): ?NativeModuleInterface
    {
        if ($context->pdo === null) {
            return null;
        }

        $repository = new PagesRepository($context->pdo);
        $repository->ensureSchema();
        $capabilities = $context->service('capabilityRegistry');
        if ($capabilities instanceof CapabilityRegistry) {
            $capabilities->registerInstance('modulnest.pages', 'page_links', $repository);
        }

        $controller = new PagesController(
            $repository,
            $context->session,
            $context->service('authService'),
            new MarkdownRenderer(),
        );

        return new self(
            $controller,
            $context->moduleRow('pages'),
            $context->moduleAccess('pages', 'public'),
        );
    }

    public function __construct(
        private readonly PagesController $controller,
        private readonly ?array $moduleRow,
        private readonly string $access,
    ) {
    }

    public static function healthCheck(): bool
    {
        return true;
    }

    public function key(): string
    {
        return 'modulnest.pages';
    }

    public function routePrefix(): string
    {
        return 'pages';
    }

    public function registerNavigation(ModuleSubnavigationRegistry $moduleNavigation, AdminNavigationRegistry $adminNavigation, UserNavigationRegistry $userNavigation): void
    {
        $adminNavigation->registerProvider(new PagesAdminNavigationProvider());
    }

    public function registerRoutes(Router $router): void
    {
        if (!$this->isNativeActive()) {
            return;
        }

        $router->get('/pages', [$this->controller, 'index'], $this->access);
        $router->get('/pages/*', [$this->controller, 'subRoute'], $this->access);
    }

    public function registerAdminRoutes(Router $router): void
    {
        $router->get('/admin/pages', [$this->controller, 'adminIndex'], 'admin');
        $router->post('/admin/pages/create', [$this->controller, 'create'], 'admin');
        $router->post('/admin/pages/update', [$this->controller, 'update'], 'admin');
        $router->post('/admin/pages/delete', [$this->controller, 'delete'], 'admin');
        $router->post('/admin/pages/toggle', [$this->controller, 'toggle'], 'admin');
        $router->post('/admin/pages/move', [$this->controller, 'move'], 'admin');
    }

    /**
     * @return array{module_key:string,internal_name:string,controller:string,implementation_path:string,route_binding:string}
     */
    public function nativeBinding(): array
    {
        return [
            'module_key' => 'modulnest.pages',
            'internal_name' => 'Pages',
            'controller' => PagesController::class,
            'implementation_path' => __FILE__,
            'route_binding' => 'GET /pages, GET /pages/*, GET /admin/pages',
        ];
    }

    private function isNativeActive(): bool
    {
        return is_array($this->moduleRow)
            && strtolower((string) ($this->moduleRow['handler'] ?? 'native')) === 'native';
    }
}
