<?php

declare(strict_types=1);

namespace ModulNest\FantasyCards;

use Modulon\Core\AdminNavigationRegistry;
use Modulon\Core\ModuleContext;
use Modulon\Core\ModuleSubnavigationRegistry;
use Modulon\Core\NativeModuleInterface;
use Modulon\Core\Router;
use Modulon\Core\UserNavigationRegistry;

final class FantasyCardsModule implements NativeModuleInterface
{
    public static function metadata(): array
    {
        return [
            'key' => 'fantasy-cards',
            'name' => 'Fantasy Cards',
            'route_prefix' => 'fantasy-cards',
            'access_level' => 'user',
            'description' => 'Digitales Fantasy-Sammelkarten-System für Sets, Karten und Booster.',
            'show_in_header' => true,
            'show_on_home' => true,
        ];
    }

    public static function create(ModuleContext $context): ?NativeModuleInterface
    {
        if ($context->pdo === null) {
            return null;
        }

        $repository = new FantasyCardsRepository($context->pdo);
        $subnavigation = new FantasyCardsSubnavigationProvider();
        $controller = new FantasyCardsController(
            $repository,
            new FantasyCardsService($repository),
            new FantasyCardsBoosterService($repository),
            new FantasyCardsUploadService($repository, $context->basePath),
            $subnavigation,
            $context->session,
            $context->service('authService'),
            $context->moduleAccess('fantasy-cards', 'user'),
        );

        return new self(
            $controller,
            $subnavigation,
            $context->moduleAccess('fantasy-cards', 'user'),
        );
    }

    public function __construct(
        private readonly FantasyCardsController $controller,
        private readonly FantasyCardsSubnavigationProvider $subnavigation,
        private readonly string $access,
    ) {
    }

    public function key(): string
    {
        return 'fantasy-cards';
    }

    public function routePrefix(): string
    {
        return 'fantasy-cards';
    }

    public function registerNavigation(ModuleSubnavigationRegistry $moduleNavigation, AdminNavigationRegistry $adminNavigation, UserNavigationRegistry $userNavigation): void
    {
        $moduleNavigation->register($this->subnavigation);
        $adminNavigation->registerProvider(new FantasyCardsAdminNavigationProvider());
    }

    public function registerRoutes(Router $router): void
    {
        $router->get('/fantasy-cards', [$this->controller, 'index'], $this->access);
        $router->get('/fantasy-cards/*', [$this->controller, 'subRoute'], $this->access);
        $router->post('/fantasy-cards/boosters/claim', [$this->controller, 'claimBooster'], $this->access);
        $router->post('/fantasy-cards/boosters/open', [$this->controller, 'openBooster'], $this->access);
    }

    public function registerAdminRoutes(Router $router): void
    {
        $router->get('/admin/fantasy-cards', [$this->controller, 'adminSets'], 'admin');
        $router->post('/admin/fantasy-cards/upload', [$this->controller, 'uploadCards'], 'admin');
        $router->get('/admin/fantasy-cards/*', [$this->controller, 'adminSubRoute'], 'admin');
        $router->post('/admin/fantasy-cards/sets/save', [$this->controller, 'saveSet'], 'admin');
        $router->post('/admin/fantasy-cards/sets/toggle', [$this->controller, 'toggleSet'], 'admin');
        $router->post('/admin/fantasy-cards/cards/save', [$this->controller, 'saveCard'], 'admin');
        $router->post('/admin/fantasy-cards/cards/toggle', [$this->controller, 'toggleCard'], 'admin');
        $router->post('/admin/fantasy-cards/cards/inline', [$this->controller, 'updateCardInline'], 'admin');
        $router->post('/admin/fantasy-cards/cards/reorder', [$this->controller, 'reorderCards'], 'admin');
        $router->post('/admin/fantasy-cards/cards/bulk', [$this->controller, 'bulkCards'], 'admin');
    }

    public function nativeBinding(): array
    {
        return [
            'module_key' => 'fantasy-cards',
            'internal_name' => 'Fantasy Cards',
            'controller' => FantasyCardsController::class,
            'implementation_path' => 'src/FantasyCardsController.php',
            'route_binding' => 'GET /fantasy-cards, GET /fantasy-cards/*, POST /fantasy-cards/boosters/*, GET /admin/fantasy-cards, GET/POST /admin/fantasy-cards/upload, POST /admin/fantasy-cards/*',
        ];
    }
}
