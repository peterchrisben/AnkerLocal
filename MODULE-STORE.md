# Module Store – Stand und Hinweise

ANKERlocal ist so gebaut, dass es ohne Umbau eingereicht werden kann. Eingereicht ist noch nichts.

## Vorgesehene Angaben

| Feld | Wert |
|---|---|
| Bundle-ID | `io.github.peterchrisben.ankerlocal` |
| Name im Store | ANKERlocal |
| Sprache | Deutsch |
| Kategorie | Energie |
| Doku-Link | README.md im Repo |

## Prüfpunkte

| Regel | Stand |
|---|---|
| Nur `<?php` | erfüllt |
| Kein `IPS_LogMessage` | erfüllt, nur `$this->LogMessage` |
| Kein `IPS_SetProperty` / `IPS_ApplyChanges` auf eigene Properties | erfüllt (siehe „Grauzone“ unten zu Client Socket und Gateway) |
| Instanz lässt sich immer anlegen | erfüllt – ohne Gateway bzw. ohne IP-Adresse Status „inaktiv“ und Hinweis in der Überblickszeile |
| Nur eigene oder zugewiesene Objekte | erfüllt – die Netzleistungs-Variable wird nur gelesen und als Referenz angemeldet |
| Keine Ereignisse | erfüllt, nur `RegisterTimer` |
| Keine eigenen Profile, keine Custom-Darstellungen, keine Archivierung | erfüllt – nur Darstellungen über `RegisterVariable*` |
| Lokaler Dateizugriff | nur `form.json` aus dem eigenen Modulordner |
| Datenfluss | nur über `SendDataToParent` / `ReceiveData` zum ModBus Gateway von Symcon |
| Name ohne „Symcon“/„IPS“ | erfüllt |
| Markenhinweis | im README |

## Grauzone (beim Einreichen erklären)

Das Modul schreibt in die übergeordneten Instanzen (`IPS_SetProperty` + `IPS_ApplyChanges`), genau wie SYMARSTEK:

1. **Port 502** in einen Client Socket, dessen Port noch 0 ist (Leerwert nach Neuanlage, sonst nie).
2. **Host / Port / Open** bzw. **Geräte-ID** nur per Knopf „Übernehmen“ mit den vom Benutzer eingetragenen Werten.

Beides nur, wenn das Gateway ausschließlich von dieser Instanz benutzt wird. Eigene Properties werden nie per `IPS_SetProperty` geändert. `GetConfigurationForParent` reicht dafür nicht: Es wirkt nur auf das Gateway und sperrt die Werte.

## Vor einer Einreichung noch nötig

- Test am echten Gerät (TODO.md), danach den Hinweis „ungetestet“ im README entfernen.
- Screenshots für das README (`imgs/`).
