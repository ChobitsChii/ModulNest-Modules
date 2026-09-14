# Repository Manager – Versionsverlauf

## 1.0.0 - 2026-09-14

- Stabile Version 1.0.0 des ModulNest Repository Managers.
- Einheitliche Badge-Zähler für Katalog-Reiter (Updates, Entdecken, Installiert) integriert.
- Vollständige Verwaltung von Modul-Katalogquellen, Prioritäten und Trust-Schlüsseln.
- Integrierte Statusanzeige und Spiegelungssteuerung für Repository-Mirrors.

## 0.1.0-beta.4 - 2026-09-12

- Versionsneutrale, bereinigte Modulbeschreibung in Manifest und Modulklasse ("Verwaltet Modul-Katalogquellen und steuert den lokalen ModulNest-Repository-Spiegel.").
- Volle Konfigurationsfreiheit: Die offizielle Standard-Katalogquelle kann von Administratoren bei Bedarf deaktiviert, priorisiert oder in ihren Einstellungen angepasst werden.
- Formularerweiterung: Aktivierungs-Status ("Katalogquelle aktivieren") kann nun direkt im Bearbeiten-Formular eingesehen und geändert werden.
- Präzisierung der Dokumentation zur Hintergrundsynchronisation: Klarstellung, dass `flock` der interne Sperrmechanismus gegen parallele Läufe ist und Cron ausschließlich die zeitliche Steuerung übernimmt.
- Verbesserte UI-Konsistenz bei Katalogquellen-Status-Badges und Berechtigungsprüfungen für die Ausführung von Sync-Skripten.

## 0.1.0-beta.3 - 2026-09-12

- Repository-Mirror-Integration: Verwaltung und Synchronisation des lokalen ModulNest-Repository-Spiegels (`sync-repository.sh`).
- Live-Mirror-Statusanzeige: Anzeige von aktiver Sequenz, Snapshot-Name, UTC-Timestamp, Source-Fingerprint und Hintergrundprozess-Status (`flock`).
- Asynchrone Hintergrund-Synchronisation über `BackgroundProcessRunner` mit AJAX-Unterstützung und Live-Statuspolling im Frontend.
- Transparente Cron-Konfigurationsanzeige zur Dokumentation der empfohlenen System-Crontab.
- Dynamische Ermittlung der aktiven Modulversion direkt aus dem Manifest statt statischem Hinweistext.

## 0.1.0-beta.2 - 2026-09-12

- Strukturierte Trust-Key-Verwaltung mit dynamischen Zeilen für Key-ID und Base64-Public-Key statt reiner Raw-JSON-Eingabe (inklusive abwärtskompatiblem Fallback).
- Root-Key-Auswahl über intuitive Checkboxen pro Signaturschlüssel.
- Anzeige der SHA-256 Trust-Key-Fingerprints in der Übersicht und der Bearbeitungsansicht zur Verifikation.
- AJAX-Verbindungstest für vorhandene Katalogquellen mit Ladezustand und Inline-Fehlerrückmeldung ohne Seitenreload.
- Verbesserte Fehlermeldung bei Aufruf einer ungültigen oder gelöschten `?edit=` ID.
- Korrekte Sichtbarkeitsbehandlung für Admin-only-Module ohne unerwünschte Einblendung in der öffentlichen Header-Navigation.

## 0.1.0-beta.1 - 2026-09-10

- Erste Beta zur Verwaltung von Modul-Katalogquellen über die öffentliche Core-API (`ModuleContext::catalogSources()`).
- Quellen auflisten, hinzufügen, bearbeiten, aktivieren/deaktivieren und priorisieren.
- Offizielle Quellen sind klar gekennzeichnet und werden nicht versehentlich überschrieben.
- Keine eigene Trust-, Resolver- oder SQL-Logik; Validierung und Trust bleiben Core-Verantwortung.
