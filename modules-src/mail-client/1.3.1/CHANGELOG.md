# Changelog – modulnest.mail-client

## 1.3.1 - 2026-09-21

- Absender-Whitelist / Externe Bilder: Normalisierung von E-Mail-Adressen und Domänen beim Speichern und Abgleichen behoben, sodass Bilder nach "Absender vertrauen" zuverlässig sofort geladen werden.
- Whitelist-Verwaltung: Neuer Reiter "Vertraute Absender" in den Einstellungen zur Übersicht aller freigegebenen Absender und Domänen inklusive manueller Hinzufügen- und Löschen-Funktion.
- Vollbild-Nachrichtenansicht: "Absender vertrauen & neu laden" nutzt jetzt asynchrones AJAX mit direktem Neuladen statt nackter JSON-Antwort.

## 1.3.0 - 2026-09-21

- Moderne Baumstruktur für Ordner: Verschachtelte Unter- und Unter-Unterordner werden hierarchisch mit auf-/zuklappbarem Pfeil und Einrückung übersichtlich dargestellt.
- Zuverlässige Zeichen- & Umlaut-Dekodierung: IMAP Modified-UTF-7 in Ordnernamen sowie MIME-Header im Mail-Betreff und Absender/Empfänger werden sauber nach UTF-8 dekodiert.
- ICS-Kalender-Integration: Zuverlässige Erkennung von Kalendereinladungen und .ics-Anhängen direkt in der E-Mail.
- Interaktiver Termin-Import: Modales Dialogfenster zur Detailansicht und Zielkalenderauswahl für den Import von Einzel- und Serienterminen in das Modulon-Kalender-Modul.
- Umsortierung von E-Mail-Konten: Konten können via Hoch/Runter-Schaltflächen und Drag & Drop in ihrer Reihenfolge angepasst werden, gespeichert über sort_order.
- Multiple Absender-Aliase und Identitäten: Beliebig viele Aliase/Identitäten pro Mail-Konto konfigurierbar und auswählbar beim Verfassen neuer E-Mails.
- Vollbild- & App-Modus Optimierungen: Unterseiten wie Kontoverwaltung und Verfassen blenden im PWA/App-Modus Modulon-Header und -Footer zuverlässig aus.
- Robuste IMAP- und Hintergrund-Synchronisation: Vermeidung von Notice-Eskalationen beim Script-Shutdown.

## 1.2.0 - 2026-09-20

- Kompaktes zweizeiliges Listen-Layout: Zweizeilige Darstellung in der Nachrichtenliste mit rechtsbündigem "An:"-Empfänger und dynamischer Betreff-Kürzung
- Status- und Stern-Synchronisation: Sofortiges Umschalten von Gelesen/Ungelesen und Stern-Markierungen mit bidirektionaler IMAP-Flag-Synchronisation
- Iframe-Vorschau und Scroll-Verbesserungen: Zuverlässige automatische Höhenanpassung ohne doppelte Scrollbalken und fehlerfreies Umschalten zwischen fixiertem und mitscrollendem Header
- Kalender-Seitenleiste: Anbindung an das Kalender-Modul zur Vorschau anstehender Termine der nächsten 30 Tage inklusive automatischer Entfaltung von Serienterminen
- Termin-Detail-Overlay: Klick auf einen Termin in der Seitenleiste öffnet ein modales Detailfenster mit Kalenderfarbe, Datum, Uhrzeit, Wiederholung, Ort, Beschreibung und Direktlink zum Kalender
- Typografische Ausrichtung: Bündige typografische Grundlinie für Zeit- und Titelangaben in der Kalenderleiste
- Kalender-Aktualisierung: Manueller Refresh-Button sowie automatische Aktualisierung beim Öffnen der Leiste und bei Browser-Fokus

## 1.1.3 - 2026-09-19

- IMAP "Missing search parameters"-Fehler behoben: `fetchAllUids()` verwendet nun explizite Where-Klausel (`whereUid('1:*')`)
- "Alle Ordner laden..."-Button bleibt nach dem Laden der Ordnerliste sichtbar
- Active-Status eines Ordners bleibt nach dem Neuladen der Ordnerliste erhalten
- "Alle Ordner laden..." Button kann beliebig oft geklickt werden ohne zu verschwinden

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
