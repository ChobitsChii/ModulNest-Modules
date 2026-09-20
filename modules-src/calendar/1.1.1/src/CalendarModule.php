<?php

declare(strict_types=1);

namespace ModulNest\Calendar;

use Modulon\Core\AdminNavigationRegistry;
use Modulon\Core\ModuleContext;
use Modulon\Core\ModuleSubnavigationRegistry;
use Modulon\Core\NativeModuleInterface;
use Modulon\Core\Router;
use Modulon\Core\UserNavigationRegistry;
use Modulon\Modules\Admin\AppSettingRepository;
use ModulNest\Calendar\Navigation\CalendarAdminNavigationProvider;
use ModulNest\Calendar\Navigation\CalendarSubnavigationProvider;
use ModulNest\Calendar\Repository\AppointmentRepository;
use ModulNest\Calendar\Repository\CalendarRepository;
use ModulNest\Calendar\Repository\GoogleAccountRepository;
use ModulNest\Calendar\Service\CalendarService;
use ModulNest\Calendar\Service\GoogleCalendarService;
use ModulNest\Calendar\Validation\AppointmentValidator;

final class CalendarModule implements NativeModuleInterface
{
    private readonly CalendarSubnavigationProvider $subnavigationProvider;
    private readonly CalendarAdminNavigationProvider $adminNavigationProvider;

    public static function metadata(): array
    {
        return [
            'key' => 'calendar',
            'name' => 'Kalender',
            'route_prefix' => 'calendar',
            'access_level' => 'user',
            'description' => 'Kalender mit Terminverwaltung (Tages-, Wochen- und Monatsansicht), Serien- und Wiederholungsterminen sowie bidirektionaler Google Calendar Synchronisation.',
            'show_in_header' => true,
            'show_on_home' => true,
        ];
    }

    public static function create(ModuleContext $context): ?NativeModuleInterface
    {
        if ($context->pdo === null) {
            return null;
        }

        $settings = $context->service('appSettingRepository');
        if (!$settings instanceof AppSettingRepository) {
            $settings = new AppSettingRepository($context->pdo);
        }

        $appointmentRepo = new AppointmentRepository($context->pdo);
        $calendarRepo = new CalendarRepository($context->pdo);
        $googleAccountRepo = new GoogleAccountRepository($context->pdo);

        $googleService = new GoogleCalendarService($settings, $googleAccountRepo, $calendarRepo, $appointmentRepo);

        $calendarService = new CalendarService(
            $appointmentRepo,
            $calendarRepo,
            new AppointmentValidator(),
            $googleService
        );

        $controller = new CalendarController(
            $calendarService,
            $context->session,
            $context->service('authService'),
            $googleService
        );

        $adminController = new CalendarAdminController(
            $settings,
            $context->session
        );

        $subnavigationProvider = new CalendarSubnavigationProvider();
        $adminNavigationProvider = new CalendarAdminNavigationProvider();

        return new self(
            $controller,
            $adminController,
            $subnavigationProvider,
            $adminNavigationProvider,
            $context->moduleAccess('calendar', 'user')
        );
    }

    public function __construct(
        private readonly CalendarController $controller,
        private readonly CalendarAdminController $adminController,
        CalendarSubnavigationProvider $subnavigationProvider,
        CalendarAdminNavigationProvider $adminNavigationProvider,
        private readonly string $access,
    ) {
        $this->subnavigationProvider = $subnavigationProvider;
        $this->adminNavigationProvider = $adminNavigationProvider;
    }

    public function key(): string
    {
        return 'calendar';
    }

    public function routePrefix(): string
    {
        return 'calendar';
    }

    public function registerNavigation(ModuleSubnavigationRegistry $moduleNavigation, AdminNavigationRegistry $adminNavigation, UserNavigationRegistry $userNavigation): void
    {
        $moduleNavigation->register($this->subnavigationProvider);
        $adminNavigation->registerProvider($this->adminNavigationProvider);
    }

    public function registerRoutes(Router $router): void
    {
        // Views
        $router->get('/calendar', [$this->controller, 'index'], $this->access);
        $router->get('/calendar/day', [$this->controller, 'day'], $this->access);
        $router->get('/calendar/week', [$this->controller, 'week'], $this->access);
        $router->get('/calendar/month', [$this->controller, 'month'], $this->access);

        // Calendar operations
        $router->post('/calendar/toggle-calendar', [$this->controller, 'toggleCalendar'], $this->access);
        $router->post('/calendar/calendars/create', [$this->controller, 'createCalendar'], $this->access);
        $router->post('/calendar/calendars/update', [$this->controller, 'updateCalendar'], $this->access);
        $router->post('/calendar/calendars/delete', [$this->controller, 'deleteCalendar'], $this->access);

        // Appointment operations
        $router->get('/calendar/appointment/create', [$this->controller, 'createForm'], $this->access);
        $router->post('/calendar/appointment/create', [$this->controller, 'create'], $this->access);

        $router->get('/calendar/appointment/*', [$this->controller, 'appointmentForm'], $this->access);
        $router->post('/calendar/appointment/*', [$this->controller, 'appointmentPost'], $this->access);

        $router->patch('/calendar/api/appointment/*', [$this->controller, 'appointmentMove'], $this->access);
        $router->get('/calendar/api/appointments', [$this->controller, 'apiAppointments'], $this->access);
        $router->get('/calendar/api/appointment/*', [$this->controller, 'apiAppointment'], $this->access);

        // Google OAuth & Sync
        $router->get('/calendar/google/connect', [$this->controller, 'googleConnect'], $this->access);
        $router->get('/calendar/google/callback', [$this->controller, 'googleCallback'], $this->access);
        $router->get('/calendar/google/select-calendars', [$this->controller, 'googleSelectCalendars'], $this->access);
        $router->post('/calendar/google/select-calendars', [$this->controller, 'googleSaveCalendars'], $this->access);
        $router->post('/calendar/google/sync', [$this->controller, 'googleSync'], $this->access);
        $router->get('/calendar/google/auto-sync', [$this->controller, 'googleAutoSync'], $this->access);
        $router->post('/calendar/google/disconnect', [$this->controller, 'googleDisconnect'], $this->access);
    }

    public function registerAdminRoutes(Router $router): void
    {
        $router->get('/admin/calendar', [$this->adminController, 'index'], 'admin');
        $router->post('/admin/calendar/settings', [$this->adminController, 'saveSettings'], 'admin');
    }

    public function nativeBinding(): array
    {
        return [
            'module_key' => 'calendar',
            'internal_name' => 'Kalender',
            'controller' => CalendarController::class,
            'implementation_path' => 'src/CalendarController.php',
            'route_binding' => 'GET /calendar, GET /calendar/day, GET /calendar/week, GET /calendar/month, GET/POST /calendar/appointment/*',
        ];
    }
}
