# Changelog – modulnest.mail-client

## 1.1.2 - 2026-09-19

- Fehlende Methode countForFolder() im MessageIndexRepository hinzugefügt
- Fehler "Call to undefined method MessageIndexRepository::countForFolder()" bei Nachrichten-Liste behoben
- Nachrichtenliste und Sync-Fortschritts-Tracking funktionieren jetzt korrekt

## 1.1.1 - 2026-09-19

- Benutzerfreundlichere Fehlermeldung wenn kein Mail-Konto existiert aber E-Mail versendet werden soll
- Leerer State: Klare Hinweise mit direktem "Mail-Konto anlegen"-Button statt leeres Dropdown
- "Neue Mail" Button wird in Hauptansicht ausgeblendet wenn keine Konten existieren
- Neue compose-empty.php View mit instruktivem Empty-State-Design

## 1.1.0 - 2026-09-19

- Autoconfig/Autodiscover: E-Mail-Konten können automatisch via MX-Records und Autoconfig-Endpunkte konfiguriert werden
- Progressiver IMAP-Hintergrundsync mit Fortschritts-Tracking über mail_client_sync_progress Tabelle
- AJAX-basierter Sync-Status: Echtzeit-Updates zu laufenden Sync-Vorgängen
- API-Endpunkte für Sync-Status (/api/sync/status) und Sync-Verfolgung (/api/sync/continue)
- Verbesserte Fehlerbehandlung und Logging bei IMAP-Verbindungsproblemen
- Robusterer Connection-Handler mit automatischem Reconnect bei IMAP-Trennungen
- Optimierte Abfrageleistung für den Nachrichten-Index

## 1.0.0 - 2026-09-19

- Thunderbird-artiges 3-Pane-Layout (Ordnerbaum | Nachrichten-Liste | Vorschau)
- Multi-Account-IMAP-Unterstützung: mehrere Konten pro Benutzer
- IMAP-Sync mit UIDVALIDITY-Prüfung: Löschungen/Verschiebungen auf anderen Geräten werden erkannt
- Nur Header-Metadaten werden gecacht (kein Mail-Body-Cache)
- Sichere HTML-Mail-Anzeige in sandboxed iframe – kein JavaScript möglich
- Externe Bilder standardmäßig blockiert, Absender-Whitelist (Sender/Domain)
- SMTP-Versand via symfony/mailer
- Rich-Text-Editor beim Verfassen (Bold, Italic, Underline, Listen, Links)
- Sanitierung des ausgehenden Mail-Inhalts (kein JS/Schadcode möglich)
- Doppelklick öffnet Mail in neuem Browser-Tab (Vollbild-Ansicht)
- Vollständig AJAX-basiert – kein Seitenneuladen bei Ordner-/Mail-Wechsel
- Responsive: 3-Pane auf Desktop, Overlay-Panes auf Tablet/Mobile
- Resizable Panes per Drag-and-Drop, gespeichert in localStorage
- Passwörter verschlüsselt in der DB (AES-256-GCM via SecretBox)
- Anhang-Download direkt aus dem IMAP
