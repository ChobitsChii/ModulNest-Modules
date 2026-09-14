<?php

declare(strict_types=1);

namespace ModulNest\Calendar;

use Modulon\Core\AdminNavigationRegistry;
use Modulon\Core\ModuleContext;
use Modulon\Core\ModuleSubnavigationRegistry;
use Modulon\Core\NativeModuleInterface;
use Modulon\Core\Router;
use Modulon\Core\UserNavigationRegistry;
use ModulNest\Calendar\Navigation\CalendarSubnavigationProvider;
use ModulNest\Calendar\Repository\AppointmentRepository;
use ModulNest\Calendar\Repository\CalendarRepository;
use ModulNest\Calendar\Service\CalendarService;
use ModulNest\Calendar\Validation\AppointmentValidator;

final class CalendarModule implements NativeModuleInterface
{
    private readonly CalendarSubnavigationProvider $subnavigationProvider;

    public static function metadata(): array
    {
        return [
            'key' => 'calendar',
            'name' => 'Kalender',
            'route_prefix' => 'calendar',
            'access_level' => 'user',
            'description' => 'Lokaler Kalender mit Terminverwaltung (Tages-, Wochen- und Monatsansicht, CRUD für Termine).',
            'show_in_header' => true,
            'show_on_home' => true,
        ];
    }

    public static function create(ModuleContext $context): ?NativeModuleInterface
    {
        if ($context->pdo === null) {
            return null;
        }

        $subnavigationProvider = new CalendarSubnavigationProvider();
        $controller = new CalendarController(
            new CalendarService(
                new AppointmentRepository($context->pdo),
                new CalendarRepository($context->pdo),
                new AppointmentValidator()
            ),
            $context->session,
            $context->service('authService'),
        );

        return new self($controller, $subnavigationProvider, $context->moduleAccess('calendar', 'user'));
    }

    public function __construct(
        private readonly CalendarController $controller,
        CalendarSubnavigationProvider $subnavigationProvider,
        private readonly string $access,
    ) {
        $this->subnavigationProvider = $subnavigationProvider;
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
    }

    public function registerRoutes(Router $router): void
    {
        $router->get('/calendar', [$this->controller, 'index'], $this->access);
        $router->get('/calendar/day', [$this->controller, 'day'], $this->access);
        $router->get('/calendar/week', [$this->controller, 'week'], $this->access);
        $router->get('/calendar/month', [$this->controller, 'month'], $this->access);

        $router->post('/calendar/toggle-calendar', [$this->controller, 'toggleCalendar'], $this->access);
        $router->post('/calendar/calendars/create', [$this->controller, 'createCalendar'], $this->access);
        $router->post('/calendar/calendars/update', [$this->controller, 'updateCalendar'], $this->access);
        $router->post('/calendar/calendars/delete', [$this->controller, 'deleteCalendar'], $this->access);

        $router->get('/calendar/appointment/create', [$this->controller, 'createForm'], $this->access);
        $router->post('/calendar/appointment/create', [$this->controller, 'create'], $this->access);

        $router->get('/calendar/appointment/*', [$this->controller, 'appointmentForm'], $this->access);
        $router->post('/calendar/appointment/*', [$this->controller, 'appointmentPost'], $this->access);

        $router->patch('/calendar/api/appointment/*', [$this->controller, 'appointmentMove'], $this->access);
        $router->get('/calendar/api/appointments', [$this->controller, 'apiAppointments'], $this->access);
        $router->get('/calendar/api/appointment/*', [$this->controller, 'apiAppointment'], $this->access);
    }

    public function registerAdminRoutes(Router $router): void
    {
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
