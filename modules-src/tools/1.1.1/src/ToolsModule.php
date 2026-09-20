<?php

declare(strict_types=1);

namespace ModulNest\Tools;

use Modulon\Core\AdminNavigationRegistry;
use Modulon\Core\HealthCheckProviderInterface;
use Modulon\Core\HealthCheckRegistry;
use Modulon\Core\ModuleContext;
use Modulon\Core\ModuleSubnavigationRegistry;
use Modulon\Core\NativeModuleInterface;
use Modulon\Core\Router;
use Modulon\Core\UserNavigationRegistry;

final class ToolsModule implements NativeModuleInterface, HealthCheckProviderInterface
{
    public static function metadata(): array
    {
        return [
            'key' => 'modulnest.tools',
            'name' => 'Tools',
            'route_prefix' => 'tools',
            'access_level' => 'user',
            'description' => 'Sammlung kleiner Hilfs-, Entwickler- und Admin-Werkzeuge.',
            'show_in_header' => true,
            'show_on_home' => true,
        ];
    }

    public static function create(ModuleContext $context): ?NativeModuleInterface
    {
        $registry = new ToolsRegistry();
        $navigation = new ToolsSubnavigationProvider();
        $controller = new ToolsController(
            $registry,
            $navigation,
            new ToolsNetworkService(),
            new ToolsSpeechService($context->basePath, dirname(__DIR__), $context->pdo),
            $context->session,
            $context->service('authService'),
        );

        return new self(
            $controller,
            $navigation,
            $context->moduleRow('tools'),
            $context->moduleAccess('tools', 'user'),
            $context->basePath,
        );
    }

    public function __construct(
        private readonly ToolsController $controller,
        private readonly ToolsSubnavigationProvider $navigation,
        private readonly ?array $moduleRow,
        private readonly string $access,
        private readonly string $basePath,
    ) {
    }

    public function key(): string
    {
        return 'modulnest.tools';
    }

    public function routePrefix(): string
    {
        return 'tools';
    }

    public function registerNavigation(ModuleSubnavigationRegistry $moduleNavigation, AdminNavigationRegistry $adminNavigation, UserNavigationRegistry $userNavigation): void
    {
        $moduleNavigation->register($this->navigation);
        $adminNavigation->registerProvider(new ToolsAdminNavigationProvider());
    }

    public function registerHealthChecks(HealthCheckRegistry $healthChecks): void
    {
        $healthChecks->addWritableDirectory(
            'dir_modulnest_tools_speech',
            'Persistenter Tools-Speech-Storage',
            $this->basePath . '/storage/modules/modulnest.tools/speech',
            'error',
        );
    }

    public function registerRoutes(Router $router): void
    {
        if (!$this->isNativeActive()) {
            return;
        }

        $router->get('/tools', [$this->controller, 'index'], $this->access);
        $router->get('/tools/*', [$this->controller, 'index'], $this->access);
    }

    public function registerAdminRoutes(Router $router): void
    {
        $router->get('/admin/tools', [$this->controller, 'adminIndex'], 'admin');
        $router->get('/admin/tools/speech/status', [$this->controller, 'speechStatus'], 'admin');
        $router->get('/admin/tools/speech/download', [$this->controller, 'speechDownload'], 'admin');
        $router->get('/admin/tools/*', [$this->controller, 'adminIndex'], 'admin');
        $router->post('/admin/tools/network', [$this->controller, 'networkAction'], 'admin');
        $router->post('/admin/tools/speech', [$this->controller, 'speechUpload'], 'admin');
        $router->post('/admin/tools/speech/delete', [$this->controller, 'speechDelete'], 'admin');
    }

    public function nativeBinding(): array
    {
        return [
            'module_key' => 'modulnest.tools',
            'internal_name' => 'Tools',
            'controller' => ToolsController::class,
            'implementation_path' => __FILE__,
            'route_binding' => 'GET /tools, GET /admin/tools, GET /admin/tools/speech/status, GET /admin/tools/speech/download, POST /admin/tools/network, POST /admin/tools/speech, POST /admin/tools/speech/delete',
        ];
    }

    public static function healthCheck(): bool
    {
        return function_exists('proc_open');
    }

    private function isNativeActive(): bool
    {
        return is_array($this->moduleRow)
            && strtolower((string) ($this->moduleRow['handler'] ?? 'native')) === 'native';
    }
}
