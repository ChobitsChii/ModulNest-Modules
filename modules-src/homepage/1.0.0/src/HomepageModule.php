<?php

declare(strict_types=1);

namespace ModulNest\Homepage;

use Modulon\Core\AdminNavigationRegistry;
use Modulon\Core\MarkdownRenderer;
use Modulon\Core\Modules\CapabilityRegistry;
use Modulon\Core\ModuleContext;
use Modulon\Core\ModuleSubnavigationRegistry;
use Modulon\Core\NativeModuleInterface;
use Modulon\Core\Router;
use Modulon\Core\UserNavigationRegistry;
use Modulon\Modules\Admin\AppSettingRepository;

final class HomepageModule implements NativeModuleInterface
{
    /**
     * @return array{key:string,name:string,route_prefix:string,access_level:string,description:string,show_in_header:bool,show_on_home:bool}
     */
    public static function metadata(): array
    {
        return [
            'key' => 'modulnest.homepage',
            'name' => 'Startseite',
            'route_prefix' => 'homepage',
            'access_level' => 'admin',
            'description' => 'Konfigurierbare Root-Startseite vorbereiten und veröffentlichen.',
            'show_in_header' => false,
            'show_on_home' => false,
        ];
    }

    public static function create(ModuleContext $context): ?NativeModuleInterface
    {
        if ($context->pdo === null) {
            return null;
        }

        $settings = $context->service('appSettingRepository');
        if (!$settings instanceof AppSettingRepository) {
            return null;
        }

        $repository = new HomepageRepository($context->pdo, $settings);
        $markdown = new MarkdownRenderer();
        $renderer = new HomepageRenderer($repository, $markdown);
        $capabilities = $context->service('capabilityRegistry');
        if ($capabilities instanceof CapabilityRegistry) {
            $capabilities->registerInstance('modulnest.homepage', 'root_page', $renderer);
        }
        $controller = new HomepageController(
            $repository,
            $context->session,
            $renderer,
            $context->service('moduleRepository') instanceof \Modulon\Modules\Modules\ModuleRepository
                ? $context->service('moduleRepository')
                : null,
            null,
        );

        return new self(
            $controller,
            $repository,
            $markdown,
            $context->moduleRow('homepage'),
        );
    }

    public function __construct(
        private readonly HomepageController $controller,
        private readonly HomepageRepository $repository,
        private readonly MarkdownRenderer $markdown,
        private readonly ?array $moduleRow,
    ) {
    }

    public static function healthCheck(): bool
    {
        return true;
    }

    public function key(): string
    {
        return 'modulnest.homepage';
    }

    public function routePrefix(): string
    {
        return 'homepage';
    }

    public function renderer(): HomepageRenderer
    {
        return new HomepageRenderer($this->repository, $this->markdown);
    }

    public function registerNavigation(ModuleSubnavigationRegistry $moduleNavigation, AdminNavigationRegistry $adminNavigation, UserNavigationRegistry $userNavigation): void
    {
        $adminNavigation->registerProvider(new HomepageAdminNavigationProvider());
    }

    public function registerRoutes(Router $router): void
    {
        // Die öffentliche Root-Route bleibt im Core-Fallback und nutzt das Modul nur bei veröffentlichter Konfiguration.
    }

    public function registerAdminRoutes(Router $router): void
    {
        if (!$this->isNativeActive()) {
            return;
        }

        $router->get('/admin/homepage', [$this->controller, 'adminIndex'], 'admin');
        $router->post('/admin/homepage/publish', [$this->controller, 'togglePublished'], 'admin');
        $router->post('/admin/homepage/blocks/create', [$this->controller, 'createBlock'], 'admin');
        $router->post('/admin/homepage/blocks/update', [$this->controller, 'updateBlock'], 'admin');
        $router->post('/admin/homepage/blocks/toggle', [$this->controller, 'toggleBlock'], 'admin');
        $router->post('/admin/homepage/blocks/visibility', [$this->controller, 'toggleVisibility'], 'admin');
        $router->post('/admin/homepage/blocks/delete', [$this->controller, 'deleteBlock'], 'admin');
        $router->post('/admin/homepage/blocks/move', [$this->controller, 'moveBlock'], 'admin');
    }

    /**
     * @return array{module_key:string,internal_name:string,controller:string,implementation_path:string,route_binding:string}
     */
    public function nativeBinding(): array
    {
        return [
            'module_key' => 'modulnest.homepage',
            'internal_name' => 'Homepage',
            'controller' => HomepageController::class,
            'implementation_path' => __FILE__,
            'route_binding' => 'GET /admin/homepage, POST /admin/homepage/publish, POST /admin/homepage/blocks/*, optional root rendering at GET /',
        ];
    }

    private function isNativeActive(): bool
    {
        return is_array($this->moduleRow)
            && strtolower((string) ($this->moduleRow['handler'] ?? 'native')) === 'native';
    }
}
