<?php

declare(strict_types=1);

namespace ModulNest\DataPortability;

use Modulon\Core\AdminNavigationRegistry;
use Modulon\Core\ModuleContext;
use Modulon\Core\ModuleSubnavigationRegistry;
use Modulon\Core\NativeModuleInterface;
use Modulon\Core\Router;
use Modulon\Core\UserNavigationRegistry;
use Modulon\Core\Modules\CapabilityRegistry;
use Modulon\Core\Modules\DataPortability\DataPortabilityProviderInterface;
use Modulon\Modules\Auth\AuthService;
use Throwable;

final class DataPortabilityModule implements NativeModuleInterface
{
    public static function metadata(): array
    {
        return [
            'key' => 'modulnest.data-portability',
            'name' => 'Export / Import',
            'route_prefix' => 'data-portability',
            'access_level' => 'admin',
            'description' => 'Moduldaten sicher zwischen ModulNest-Instanzen exportieren und importieren.',
            'show_in_header' => false,
            'show_on_home' => false,
        ];
    }

    public static function create(ModuleContext $context): ?NativeModuleInterface
    {
        if ($context->pdo === null) {
            return null;
        }

        $authService = $context->service('authService');
        $controller = new DataPortabilityController(
            new DataPortabilityService(
                $context->basePath,
                (string) $context->config('app_version', '0.0.0'),
                static function () use ($context): array {
                    $providers = [];
                    foreach (self::capabilityProviders($context) as $provider) {
                        $providers[$provider->key()] = $provider;
                    }
                    return $providers;
                }
            ),
            $context->session,
            $authService instanceof AuthService ? $authService : null,
            $context->isNativeActive('fantasy-cards'),
        );

        return new self($controller, $context->moduleRow('data-portability'), $context->isNativeActive('profil'));
    }

    public function __construct(
        private readonly DataPortabilityController $controller,
        private readonly ?array $moduleRow,
        private readonly bool $profileAvailable,
    ) {
    }

    public static function healthCheck(): bool
    {
        return true;
    }

    public function key(): string
    {
        return 'modulnest.data-portability';
    }

    public function routePrefix(): string
    {
        return 'data-portability';
    }

    public function registerNavigation(ModuleSubnavigationRegistry $moduleNavigation, AdminNavigationRegistry $adminNavigation, UserNavigationRegistry $userNavigation): void
    {
        $adminNavigation->registerProvider(new DataPortabilityAdminNavigationProvider());
        if ($this->profileAvailable) {
            $userNavigation->registerProvider(new DataPortabilityUserNavigationProvider());
        }
    }

    public function registerRoutes(Router $router): void
    {
        if (!$this->isNativeActive() || !$this->profileAvailable) {
            return;
        }

        $router->get('/profil/data-portability', [$this->controller, 'userIndex'], 'user');
        $router->post('/profil/data-portability/export', [$this->controller, 'userExport'], 'user');
        $router->post('/profil/data-portability/import/preview', [$this->controller, 'userPreviewImport'], 'user');
        $router->post('/profil/data-portability/import/run', [$this->controller, 'userRunImport'], 'user');
    }

    public function registerAdminRoutes(Router $router): void
    {
        if (!$this->isNativeActive()) {
            return;
        }

        $router->get('/admin/data-portability', [$this->controller, 'index'], 'admin');
        $router->post('/admin/data-portability/export', [$this->controller, 'export'], 'admin');
        $router->post('/admin/data-portability/import/preview', [$this->controller, 'previewImport'], 'admin');
        $router->post('/admin/data-portability/import/run', [$this->controller, 'runImport'], 'admin');
    }

    public function nativeBinding(): array
    {
        return [
            'module_key' => 'modulnest.data-portability',
            'internal_name' => 'DataPortability',
            'controller' => DataPortabilityController::class,
            'implementation_path' => __FILE__,
            'route_binding' => 'GET/POST /profil/data-portability/*, GET /admin/data-portability, POST /admin/data-portability/export, POST /admin/data-portability/import/preview, POST /admin/data-portability/import/run',
        ];
    }

    private function isNativeActive(): bool
    {
        return is_array($this->moduleRow)
            && strtolower((string) ($this->moduleRow['handler'] ?? 'native')) === 'native';
    }

    /**
     * Resolves providers from active v1 instances and installed v2 declarations.
     *
     * @return list<DataPortabilityProviderInterface>
     */
    private static function capabilityProviders(ModuleContext $context): array
    {
        $registry = $context->service('capabilityRegistry');
        if (!$registry instanceof CapabilityRegistry) {
            return [];
        }
        try {
            $providers = [];
            foreach ($registry->instances('data_portability') as $provider) {
                if ($provider instanceof DataPortabilityProviderInterface) {
                    $providers[] = $provider;
                }
            }
            foreach ($registry->providers('data_portability') as $moduleId => $class) {
                if (isset($registry->instances('data_portability')[$moduleId])) {
                    continue;
                }
                if (!is_a($class, DataPortabilityProviderInterface::class, true)) {
                    continue;
                }
                $provider = new $class($context->pdo);
                if ($provider instanceof DataPortabilityProviderInterface) {
                    $providers[] = $provider;
                }
            }
            return $providers;
        } catch (Throwable) {
            return [];
        }
    }
}
