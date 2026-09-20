<?php

declare(strict_types=1);

namespace ModulNest\Wiki;

use Modulon\Core\{AdminNavigationRegistry, ModuleContext, ModuleSubnavigationRegistry, NativeModuleInterface, Router, UserNavigationRegistry};

final class WikiModule implements NativeModuleInterface
{
    public static function metadata(): array { return ['key'=>'modulnest.wiki','name'=>'Wiki','route_prefix'=>'wiki','access_level'=>'user','description'=>'Synchronisierte Markdown-Dokumentation aus GitHub oder einem lokalen Verzeichnis.','show_in_header'=>true,'show_on_home'=>false]; }
    public static function healthCheck(): bool { return true; }
    public static function create(ModuleContext $context): ?NativeModuleInterface
    {
        if ($context->pdo === null) return null;
        $repository = new WikiRepository($context->pdo);
        $indexer = new WikiSearchIndexer($context->pdo);
        $storageRoot = $context->basePath . '/storage/modules/modulnest.wiki';
        $search = new WikiSearchService($context->pdo, $indexer, $storageRoot . '/content');
        $service = new WikiService($repository, $context->pdo, $context->basePath, new GitHubWikiClient(), $indexer);
        $auth = $context->service('authService');
        $releaseId = basename(dirname(__DIR__));
        return new self(new WikiController($context->session, $repository, $service, $context->basePath, $auth instanceof \Modulon\Modules\Auth\AuthService ? $auth : null, $search, '/assets/modules/modulnest.wiki/' . $releaseId));
    }
    public function __construct(private readonly WikiController $controller) {}
    public function key(): string { return 'modulnest.wiki'; }
    public function routePrefix(): string { return 'wiki'; }
    public function registerNavigation(ModuleSubnavigationRegistry $moduleNavigation, AdminNavigationRegistry $adminNavigation, UserNavigationRegistry $userNavigation): void { $adminNavigation->registerProvider(new WikiAdminNavigationProvider()); }
    public function registerRoutes(Router $router): void { $router->get('/wiki', [$this->controller,'index'],'user'); $router->get('/wiki/search',[$this->controller,'search'],'user'); $router->get('/wiki/assets/*',[$this->controller,'asset'],'user'); $router->get('/wiki/*',[$this->controller,'page'],'user'); }
    public function registerAdminRoutes(Router $router): void { $router->get('/admin/wiki',[$this->controller,'admin'],'admin');$router->get('/admin/wiki/local-directories',[$this->controller,'localDirectories'],'admin');$router->post('/admin/wiki/save',[$this->controller,'adminSave'],'admin');$router->post('/admin/wiki/sync',[$this->controller,'sync'],'admin');$router->post('/admin/wiki/search/rebuild',[$this->controller,'rebuildSearch'],'admin'); }
    public function nativeBinding(): array { return ['module_key'=>'modulnest.wiki','internal_name'=>'Wiki','controller'=>WikiController::class,'implementation_path'=>__FILE__,'route_binding'=>'GET /wiki']; }
}
