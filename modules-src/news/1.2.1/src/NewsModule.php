<?php

declare(strict_types=1);

namespace ModulNest\News;

use Modulon\Core\AdminNavigationRegistry;
use Modulon\Core\MarkdownRenderer;
use Modulon\Core\ModuleContext;
use Modulon\Core\ModuleSubnavigationRegistry;
use Modulon\Core\NativeModuleInterface;
use Modulon\Core\Router;
use Modulon\Core\UserNavigationRegistry;

final class NewsModule implements NativeModuleInterface
{
    /**
     * @return array{key:string,name:string,route_prefix:string,access_level:string,description:string,show_in_header:bool,show_on_home:bool}
     */
    public static function metadata(): array
    {
        return [
            'key' => 'modulnest.news',
            'name' => 'News',
            'route_prefix' => 'news',
            'access_level' => 'public',
            'description' => 'News und Updates.',
            'show_in_header' => true,
            'show_on_home' => true,
        ];
    }

    public static function create(ModuleContext $context): ?NativeModuleInterface
    {
        if ($context->pdo === null) {
            return null;
        }

        $controller = new NewsController(
            new NewsRepository($context->pdo),
            $context->session,
            $context->service('authService'),
            new MarkdownRenderer(),
        );

        return new self(
            $controller,
            $context->moduleRow('news'),
            $context->moduleAccess('news', 'public'),
        );
    }

    public static function healthCheck(): bool
    {
        return true;
    }

    public function __construct(
        private readonly NewsController $controller,
        private readonly ?array $moduleRow,
        private readonly string $access,
    ) {
    }

    public function key(): string
    {
        return 'modulnest.news';
    }

    public function routePrefix(): string
    {
        return 'news';
    }

    public function registerNavigation(ModuleSubnavigationRegistry $moduleNavigation, AdminNavigationRegistry $adminNavigation, UserNavigationRegistry $userNavigation): void
    {
        $adminNavigation->registerProvider(new NewsAdminNavigationProvider());
    }

    public function registerRoutes(Router $router): void
    {
        if (!$this->isNativeActive()) {
            return;
        }

        $router->get('/news', [$this->controller, 'index'], $this->access);
        $router->get('/news/*', [$this->controller, 'subRoute'], $this->access);
    }

    public function registerAdminRoutes(Router $router): void
    {
        $router->get('/admin/news', [$this->controller, 'adminIndex'], 'admin');
        $router->get('/admin/news/*', [$this->controller, 'adminSubRoute'], 'admin');
        $router->post('/admin/news/create', [$this->controller, 'create'], 'admin');
        $router->post('/admin/news/update', [$this->controller, 'update'], 'admin');
        $router->post('/admin/news/delete', [$this->controller, 'delete'], 'admin');
    }

    /**
     * @return array{module_key:string,internal_name:string,controller:string,implementation_path:string,route_binding:string}
     */
    public function nativeBinding(): array
    {
        return [
            'module_key' => 'modulnest.news',
            'internal_name' => 'News',
            'controller' => NewsController::class,
            'implementation_path' => __FILE__,
            'route_binding' => 'GET /news, GET /news/*, GET /admin/news, GET /admin/news/*',
        ];
    }

    private function isNativeActive(): bool
    {
        return is_array($this->moduleRow)
            && strtolower((string) ($this->moduleRow['handler'] ?? 'native')) === 'native';
    }
}
