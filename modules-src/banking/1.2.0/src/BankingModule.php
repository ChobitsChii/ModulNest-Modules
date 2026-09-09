<?php

declare(strict_types=1);

namespace ModulNest\Banking;

use Modulon\Core\AdminNavigationRegistry;
use Modulon\Core\Modules\CapabilityRegistry;
use Modulon\Core\ModuleContext;
use Modulon\Core\ModuleSubnavigationRegistry;
use Modulon\Core\NativeModuleInterface;
use Modulon\Core\Router;
use Modulon\Core\UserNavigationRegistry;

final class BankingModule implements NativeModuleInterface
{
    private readonly BankingSubnavigationProvider $subnavigationProvider;

    public static function metadata(): array
    {
        return [
            'key' => 'banking',
            'name' => 'Banking',
            'route_prefix' => 'banking',
            'access_level' => 'user',
            'description' => 'Native Banking-Übersicht.',
            'show_in_header' => true,
            'show_on_home' => true,
        ];
    }

    public static function create(ModuleContext $context): ?NativeModuleInterface
    {
        if ($context->pdo === null) {
            return null;
        }

        $transactionRepository = new BankingTransactionRepository($context->pdo);
        $recurringRuleRepository = new BankingRecurringRuleRepository($context->pdo);
        $recurringOverviewService = new BankingRecurringOverviewService($recurringRuleRepository, $transactionRepository);
        $capabilities = $context->service('capabilityRegistry');
        if ($capabilities instanceof CapabilityRegistry) {
            $capabilities->registerInstance('modulnest.banking', 'data_portability', new BankingDataPortabilityProvider($context->pdo));
        }
        $subnavigationProvider = new BankingSubnavigationProvider();
        $controller = new BankingController(
            new BankingRepository($context->pdo),
            new BankingDashboardService($transactionRepository, $recurringOverviewService, $context->pdo),
            new BankingTransactionListService($transactionRepository, new BankingDuplicateDetectionService()),
            new BankingMonthlyOverviewService($transactionRepository),
            $recurringRuleRepository,
            $recurringOverviewService,
            new BankingRecurringSuggestionService($transactionRepository, $recurringRuleRepository),
            new BankingCsvImportService($context->pdo),
            new BankingMigrationStatusService(),
            $subnavigationProvider,
            $context->session,
            $context->service('authService'),
        );

        return new self($controller, $subnavigationProvider, $context->moduleAccess('banking', 'user'));
    }

    public function __construct(
        private readonly BankingController $controller,
        BankingSubnavigationProvider $subnavigationProvider,
        private readonly string $access,
    ) {
        $this->subnavigationProvider = $subnavigationProvider;
    }

    public function key(): string
    {
        return 'banking';
    }

    public function routePrefix(): string
    {
        return 'banking';
    }

    public function registerNavigation(ModuleSubnavigationRegistry $moduleNavigation, AdminNavigationRegistry $adminNavigation, UserNavigationRegistry $userNavigation): void
    {
        $moduleNavigation->register($this->subnavigationProvider);
    }

    public function registerRoutes(Router $router): void
    {
        $router->get('/banking', [$this->controller, 'index'], $this->access);
        $router->post('/banking/import', [$this->controller, 'importPost'], $this->access);
        $router->post('/banking/transactions/duplicates/delete', [$this->controller, 'deleteDuplicateTransactions'], $this->access);
        $router->post('/banking/recurring', [$this->controller, 'recurringPost'], $this->access);
        $router->get('/banking/*', [$this->controller, 'subRoute'], $this->access);
    }

    public function registerAdminRoutes(Router $router): void
    {
    }

    public function nativeBinding(): array
    {
        return [
            'module_key' => 'banking',
            'internal_name' => 'Banking',
            'controller' => BankingController::class,
            'implementation_path' => 'src/BankingController.php',
            'route_binding' => 'GET /banking, GET /banking/*, POST /banking/import, POST /banking/recurring',
        ];
    }
}
