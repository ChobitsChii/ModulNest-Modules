# Historische Modulversionen

Die Produktmodule wurden vor ModulNest 2 gemeinsam mit dem Core veröffentlicht. Für den unabhängigen Katalog wird ihre tatsächlich belegte Historie rückwirkend als eigene SemVer-Reihe geführt.

## Regeln

- `1.0.0` bezeichnet die erste öffentlich nachweisbare Modulversion.
- Neue Modulfunktionen erhöhen Minor, reine Korrekturen Patch.
- Die v2-Paketierung erhöht Minor, weil sie Installation, Updates und Adoption erweitert, die öffentliche Fachfunktion aber kompatibel erhält.
- Allgemeine Core-, Security- oder Layoutänderungen erzeugen keine Modulversion.
- Quellen sind die öffentlichen Release Notes unter `docs/releases/` und die zugehörige Git-Historie. Die Paket-Changelogs enthalten nur dem jeweiligen Modul zuordenbare Einträge.

## Zuordnung

| Modul | Historische Basis | Aktuell |
|---|---|---:|
| Banking | 0.7.0 Erstveröffentlichung; 0.7.3 Regeln/Filter; 0.7.4 Replace-Kontext; Commit `080fcc2` v2-Paket | 1.2.0 |
| Dashboard | 0.5.x Grundfunktion; 0.7.0 Portability; 0.7.4 Replace-Kontext; 0.8.1 Archive; Commit `33ce686` v2-Paket/Storage | 1.3.0 |
| DataPortability | 0.7.0 Erstveröffentlichung; 0.7.2 Streaming; 0.7.3 Vorschau/Provider; 0.7.4 Replace; 0.7.5 Textfix; Commit `39df719` v2-Paket | 1.3.0 |
| Homepage | 0.8.0 Erstveröffentlichung; Commit `39df719` v2-Paket/Root-Capability | 1.1.0 |
| Logs | 0.7.0 Erstveröffentlichung; Commit `bc42a89` zentrale Logs/Rotation; Commit `ed2ea48` v2-Paket | 1.2.0 |
| News | 0.5.x Erstveröffentlichung; 0.7.0/0.7.4 DataPortability; Commit `ed2ea48` v2-Paket | 1.2.0 |
| Pages | 0.9.0 Erstveröffentlichung; Commit `39df719` v2-Paket/Page-Link-Capability | 1.1.0 |
| SneakPreview | 0.7.0 Erstveröffentlichung; 0.7.3 Vorschaufix; 0.7.4 Replace; Commit `33ce686` v2-Paket/Storage | 1.2.0 |
| Systeminfo | 0.2.0 Erstveröffentlichung; Commit `ed2ea48` v2-Paket/Core-Checks | 1.1.0 |
| Tools | 0.7.0 Erstveröffentlichung; 0.8.2 Navigationsfix; Commit `965e4f6` v2-Paket/Worker-Storage | 1.1.0 |
| Wiki | Commits `5b105e3` Erstveröffentlichung, `8a3cffe` lokale Quellen, `cf694f3` Konfigurationsfix, `1cd6c8e` Suche; Commit `71fd97c` v2-Paket | 1.3.0 |

Die vollständigen Einträge und Daten stehen in der jeweiligen paketierten `CHANGELOG.md`.
