# Changelog – modulnest.mail-client

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
