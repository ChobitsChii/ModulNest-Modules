# Changelog - modulnest.calendar

All notable changes to this module will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.1.0 - 2026-09-15

- Bidirektionale Google Calendar Integration via OAuth 2.0.
- Google Kalender im Thunderbird-Stil als eigenständige Kalender mit Auswahl-Checkboxen.
- Admin-Bereich unter /admin/calendar zur Konfiguration von Google Client-ID und Client-Secret mit Weiterleitungs-URI und Schritt-für-Schritt-Anleitung.
- Unterstützung von wiederkehrenden Terminen (täglich, werktags, wöchentlich, monatlich, jährlich) nach Google Calendar Vorbild.
- Intelligenter Hintergrund-Sync mit Rate-Limiting (Spam-Schutz) und relativer Zeitstatus-Anzeige.
- Rolling-Window Optimierung und Vorabladen von Monaten für maximale Performance.

## 1.0.0 - 2026-09-14

- Stabile Version 1.0.0 von ModulNest Calendar.
- Verwaltung mehrerer persönlicher Kalender mit Farb- und Namensanpassung.
- Monatsansicht mit interaktivem Raster und Terminanzeige.
- Tages- und Wochenansicht mit Drag & Drop, Terminvergrößerung und Schnellbearbeitung.
- Vollständige Datenportabilität (Export/Import) und responsives Design.

## 0.1.0-beta.1 - 2026-09-10

### Added
- Local calendar module with appointment management
- Day view with hourly time grid (0-23h)
- Week view with 7-day columns and hourly time grid
- All-day events section in both views
- CRUD operations for appointments:
  - Create appointments with title, description, location, start/end time, all-day flag, color
  - Read appointments in day/week views and via JSON API
  - Update appointments (full edit form)
  - Delete appointments with confirmation
- Drag & drop to move appointments (day/week view)
- Resize appointments by dragging bottom edge (day/week view)
- Double-click on empty time slot to create new appointment
- Keyboard navigation and accessibility (ARIA labels, focus management)
- Color picker for appointment categories
- Responsive design (mobile-friendly)
- Dark mode support via CSS variables
- Data Portability support (export/import appointments)
- Module subnavigation (Day/Week tabs)
- Database migration with indexes for performance
- Input validation (server-side + client-side)
- CSRF protection on all state-changing operations
- Flash messages for user feedback

### Data Model
- `calendar_appointments` table with columns:
  - `id` (BIGINT UNSIGNED, PK)
  - `user_id` (BIGINT UNSIGNED, FK to users)
  - `title` (VARCHAR 255, required)
  - `description` (TEXT, optional)
  - `location` (VARCHAR 500, optional)
  - `start_at` (DATETIME, required)
  - `end_at` (DATETIME, required)
  - `all_day` (TINYINT, default 0)
  - `color` (VARCHAR 7, default #3B82F6)
  - `created_at` / `updated_at` (DATETIME, auto)

### Indexes
- `idx_calendar_appointments_user_start` (user_id, start_at)
- `idx_calendar_appointments_user_end` (user_id, end_at)
- `idx_calendar_appointments_all_day` (user_id, all_day, start_at)

### Architecture
- PSR-4 autoloading under `ModulNest\Module\Calendar\`
- Domain layer: DTO, Repository, Service, Validator
- Entrypoint implements `NativeModuleInterface`
- Router with positional access/CSRF parameters (`user`, `strict`)
- ModuleSubnavigationProviderInterface implementation
- DataPortabilityProviderInterface implementation (full 11-method interface)
- Native binding for DataPortability provider
- Views rendered via `@modulnest.calendar/` namespace
- Assets published to `/assets/modules/modulnest.calendar/0.1.0-beta.1/`

### Not Included (Future Versions)
- Google Calendar synchronization (OAuth 2.0, bidirectional sync)
- Recurring appointments (RRULE support)
- CalDAV support
- Shared calendars / multi-user calendars
- Appointment reminders/notifications
- Timezone handling beyond server timezone
- File attachments to appointments
- Appointment categories/tags beyond color

### Security
- All database queries use prepared statements (PDO)
- User-scoped data access (user_id enforced on all queries)
- CSRF tokens required for POST/PATCH/DELETE
- Access level `user` (authenticated users only)
- Input validation and sanitization
- XSS prevention via `htmlspecialchars` in views

### Testing
- PHP lint check passes
- Module structure follows ModulNest v2 conventions
- Compatible with module-v2 test suite