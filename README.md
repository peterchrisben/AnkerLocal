# ANKERlocal

Bindet Anker-SOLIX-Speicher **rein lokal** in Symcon ein – über Modbus TCP (ModBus Gateway von Symcon) im eigenen Netz, ohne Cloud und ohne Anker-Konto. Das Modul liest die Werte des Speichers und kann ihn auf Wunsch steuern: von Hand laden oder entladen, über einen eingebauten Regler auf eine Ziel-Netzleistung führen (Überschussladen / Nulleinspeisung) oder die Leistung von einem eigenen Energiemanager vorgeben lassen.

**Aktuelle Version:** 1.0 Build 1 (15.11.2026) – erste Fassung, **noch nicht an einem echten Gerät getestet** (siehe [TODO.md](TODO.md)).

## Inhalt

1. [Unterstützte Geräte](#1-unterstützte-geräte)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Einrichtung](#3-einrichtung)
4. [Bedienung: Steuerung und Regelung](#4-bedienung-steuerung-und-regelung)
5. [Netzregelung (Überschussladen)](#5-netzregelung-überschussladen)
6. [Grenzen und Sicherheit](#6-grenzen-und-sicherheit)
7. [Variablen](#7-variablen)
8. [Einstellungen im Formular](#8-einstellungen-im-formular)
9. [Funktionen für Skripte](#9-funktionen-für-skripte)
10. [Was man wissen sollte](#10-was-man-wissen-sollte)
11. [Technik und Quellen](#11-technik-und-quellen)

## 1 Unterstützte Geräte

| Gerät | Stand |
|---|---|
| Anker SOLIX Solarbank Max AC | Registerkarte hinterlegt, ungetestet |
| Anker SOLIX Solarbank Max | gleiche Registerkarte, ungetestet |
| Anker SOLIX XE AC / XE | gleiche Registerkarte, ungetestet |
| Anker SOLIX Solarbank 4 E5000 Pro | gleiche Registerkarte, ungetestet |

Diese Speicher haben laut der offiziellen Home-Assistant-Integration von Anker dieselben Register. Das Modell wird aus der Seriennummer erkannt und angezeigt.

**Nicht unterstützt:** ältere Solarbank-Generationen (Solarbank 2 / 3 – sie haben kein lokales Modbus), Smart Meter Gen 2 und Smart Plug (eigene Register, noch nicht umgesetzt), X1 und V1-Wallbox.

## 2 Voraussetzungen

- Symcon ab Version 8.0
- Speicher und Symcon im selben Netz, Port 502 nicht gesperrt
- In der **Anker-App** eingeschaltet: Gerät → Einstellungen → Steuerung durch Drittanbieter (Three-Party Control Settings) → **Modbus TCP**. Dort steht auch die IP-Adresse des Speichers.
- Die IP-Adresse am besten im Router fest vergeben.

Ist der Punkt in der App ausgegraut: Der Speicher ist offline, Teil einer Power Station oder die Firmware ist zu alt.

## 3 Einrichtung

Die Verbindung läuft über die Bordmittel von Symcon: **Client Socket** (IP-Adresse, Port) → **ModBus Gateway** (Geräte-ID) → **Anker Solix Speicher**.

1. In Symcon „Instanz hinzufügen“ → **Anker Solix Speicher**.
2. Symcon fragt nach dem **ModBus Gateway**: ein vorhandenes Gateway zu diesem Speicher auswählen oder ein neues anlegen. Beim neuen Gateway legt Symcon auch den Client Socket an. Jeder Speicher braucht sein eigenes Gateway (eigene IP-Adresse).
3. In der Instanz ganz unten unter **Verbindung einstellen** die IP-Adresse eintragen. Port 502 und Geräte-ID 1 sind vorgeschlagen und passen im Normalfall. **Übernehmen** schreibt die Werte in den Client Socket und das Gateway und öffnet die Verbindung.
4. **Verbindung prüfen**. Darunter steht das Ergebnis (Modell, Ladezustand, Betriebsart).
5. Erst wenn das Lesen klappt: unter **Steuerung** das Häkchen **Steuerung erlauben** setzen. Ohne dieses Häkchen schreibt das Modul nichts an den Speicher.

Oben unter **Einrichtung** steht immer, womit die Instanz gerade verbunden ist („Aktuell: 192.168.1.50:502 · Geräte-ID 1 · verbunden“). Wer lieber direkt im Client Socket und im Gateway einstellt, kann das genauso tun. Hängen mehrere Geräte am selben Gateway, ändert das Modul dort nichts.

Hat ein neu angelegter Client Socket noch den Port 0, trägt das Modul von sich aus 502 ein.

Vor jeder Abfrage und vor jedem Schreiben prüft das Modul, ob die Verbindung steht. Ist sie weg, wird nichts gesendet und die Instanz meldet nach einigen Fehlversuchen „nicht erreichbar“.

## 4 Bedienung: Steuerung und Regelung

Bedient wird in zwei Stufen, beides sind Variablen unter der Instanz.

### Stufe 1 – „Steuerung“: Wer hat das Sagen?

| Wert | Bedeutung |
|---|---|
| **Anker** | Der Speicher regelt selbst, in der gewählten **Anker-Betriebsart** (Eigenverbrauch, Zeitplan, Benutzerdefiniert, Smart, Dynamische Preise). Das Modul liest nur. |
| **Manuell** | Der Speicher wird auf „Fremdsteuerung“ gestellt und macht nur noch, was das Modul vorgibt. |

### Stufe 2 – „Regelung“: Wie wird bei „Manuell“ geregelt?

| Wert | Was passiert | Leistung kommt aus |
|---|---|---|
| **Pause** | 0 Watt – der Speicher lädt nicht und entlädt nicht | – |
| **Laden** | lädt fest mit der eingestellten Leistung | Variable **Ladeleistung** |
| **Entladen** | entlädt fest mit der eingestellten Leistung | Variable **Entladeleistung** |
| **Netzregelung** | der eingebaute Regler führt die Leistung nach, bis die Netzleistung das Ziel erreicht (Standard 0 Watt) – lädt bei Überschuss, entlädt bei Bezug | gewählte Netzleistungs-Variable |
| **Extern** | ein eigener Energiemanager oder ein Skript gibt die Leistung vor | Variable **Externe Vorgabe** bzw. `ANK_SetPower` |

Steht die Steuerung auf „Anker“, bleibt die Regelung stehen, wirkt aber nicht. Beim Wechsel auf „Manuell“ beginnt das Modul mit „Pause“, damit der Speicher nicht unerwartet losläuft (abschaltbar).

Die Variable **Sollleistung am Gerät** zeigt, was gerade wirklich an den Speicher geht (plus = Laden, minus = Entladen), **Regelung – Hinweis** erklärt in einem Satz, warum.

Stellt jemand den Speicher in der Anker-App um, folgt die Variable „Steuerung“ nach ein paar Sekunden.

## 5 Netzregelung (Überschussladen)

Für die Regelung „Netzregelung“ braucht das Modul die Leistung am Netzanschluss. Im Formular unter **Netzregelung** wählst du dafür eine **Variable** aus – egal, woher sie kommt (Wechselrichter, Stromzähler, Shelly, KNX …). Dazu:

- **Vorzeichen:** Bezug positiv (Normalfall) oder Bezug negativ.
- **Einheit:** Watt oder Kilowatt.
- **Ziel-Netzleistung:** Standard 0 Watt. Mit z. B. −50 bleibt immer etwas Einspeisung, mit +50 etwas Bezug.
- **Der Regler darf:** laden und entladen, nur laden (reines Überschussladen) oder nur entladen.
- **Nicht nachregeln unter:** kleine Abweichungen (Standard 20 Watt) werden nicht ausgeregelt.
- **Regelstärke:** 100 gleicht eine Abweichung in einem Schritt aus, kleinere Werte (Standard 70) regeln ruhiger.
- **Netzleistung zu alt nach:** Wird die Variable so lange nicht aktualisiert (Standard 120 Sekunden), geht der Speicher auf 0 Watt.

Unter der Auswahl zeigt das Formular, was das Modul gerade aus der Variable liest („+350 W (Bezug)“). Stimmt das nicht mit der Wirklichkeit überein, ist Vorzeichen oder Einheit falsch.

Der Regler arbeitet im Takt der Abfrage (Standard alle 5 Sekunden) und reagiert nur auf einen neuen Messwert.

## 6 Grenzen und Sicherheit

**Höchstleistung:** Die Variablen **Max. Ladeleistung** und **Max. Entladeleistung** sind die oberste Grenze. Sie gelten bei „Manuell“ immer – bei Laden, Entladen, Netzregelung und Extern. Steht Max. Ladeleistung auf 600 Watt und es werden 800 Watt verlangt, gehen 600 Watt an den Speicher. Zusätzlich gilt die Grenze, die in der Anker-App eingestellt ist (Variablen „Gerätegrenze Laden/Entladen“); es zählt der kleinere Wert. Bei Steuerung „Anker“ wirken die Variablen nicht.

**Mindestleistung:** Unter etwa 100 Watt regelt der Speicher ungenau. Im Formular unter **Grenzen** steht je eine Mindestleistung für Laden und Entladen (Standard 100 Watt). Liegt die berechnete Leistung darunter, gibt es zwei Möglichkeiten:

- **nichts tun (Standard):** der Speicher bleibt bei 0 Watt.
- **aufrunden:** der Speicher läuft mit der Mindestleistung. Achtung: Bei 30 Watt Überschuss lädt er dann mit 100 Watt und holt 70 Watt aus dem Netz.

Das gilt für Netzregelung und Extern. Bei Laden und Entladen von Hand beginnen die Schieberegler einfach bei der Mindestleistung.

**Ladezustand:** Optional „Entladen nur über … Prozent“ und „Laden nur unter … Prozent“. Die Grenzen aus der Anker-App gelten unabhängig davon.

**Wächter:** Bleibt bei „Extern“ länger als eingestellt (Standard 60 Sekunden) keine neue Vorgabe, oder wird bei „Netzregelung“ die Netzleistung nicht mehr aktualisiert, geht der Speicher auf 0 Watt – oder, wenn so eingestellt, zurück auf „Anker“.

**Steuerung erlauben:** Ohne das Häkchen schreibt das Modul nie an den Speicher. Die Bedien-Variablen sind dann nur Anzeigen.

## 7 Variablen

| Variable | Bedeutung | schaltbar |
|---|---|---|
| Steuerung | Anker / Manuell | ja |
| Regelung | Pause / Laden / Entladen / Netzregelung / Extern | ja |
| Ladeleistung | Leistung für „Laden“ (Watt) | ja |
| Entladeleistung | Leistung für „Entladen“ (Watt) | ja |
| Externe Vorgabe | Leistung für „Extern“ (plus = Laden, minus = Entladen) | ja |
| Max. Ladeleistung | oberste Grenze fürs Laden | ja |
| Max. Entladeleistung | oberste Grenze fürs Entladen | ja |
| Anker-Betriebsart | Betriebsart, die bei „Anker“ gilt | ja |
| Sollleistung am Gerät | was gerade vorgegeben wird (plus = Laden) | nein |
| Regelung – Hinweis | Erklärung in einem Satz | nein |
| Status | Bereit / Lädt / Entlädt / Ruhezustand | nein |
| Ladezustand | Prozent | nein |
| Batterieleistung | plus = Laden, minus = Entladen | nein |
| PV-Leistung | eigene PV-Eingänge plus Fremd-PV | nein |
| Netzleistung (Gerät) | wie der Speicher sie sieht, plus = Bezug | nein |
| Hausverbrauch (Gerät) | wie der Speicher ihn sieht | nein |
| AC-Ausgangsleistung | Abgabe des Speichers auf der Netzseite | nein |
| Betriebsart am Gerät | Betriebsart, die der Speicher meldet | nein |
| PV-Ertrag / Geladen / Entladen gesamt | Zähler in kWh | nein |
| Nennkapazität | kWh | nein |
| Gerätegrenze Laden / Entladen | Höchstleistung laut Anker-App | nein |
| Ladegrenze / Entladegrenze / Notstrom-Reserve (App) | Prozentwerte aus der Anker-App | nein |
| Modell, Seriennummer, Firmware, Erreichbar | Geräteinfo | nein |

„Schaltbar“ sind die Variablen nur mit dem Häkchen „Steuerung erlauben“. Die Werte „Netzleistung (Gerät)“ und „Hausverbrauch (Gerät)“ stimmen nur, wenn der Speicher einen eigenen Zähler hat.

## 8 Einstellungen im Formular

| Bereich | Einstellung | Standard |
|---|---|---|
| Verbindung einstellen (unten) | IP-Adresse, Port, Geräte-ID – stehen im Client Socket bzw. ModBus Gateway | –, 502, 1 |
| Steuerung | Steuerung erlauben | aus |
| | Beim Wechsel auf „Manuell“ mit „Pause“ beginnen | an |
| | Bei ausbleibender Netzleistung / Vorgabe zurück auf „Anker“ | aus (= 0 Watt) |
| | Externe Vorgabe gilt höchstens (Sekunden) | 60 |
| | Obergrenze der Schieberegler (Watt) | 3500 |
| Netzregelung | Variable, Vorzeichen, Einheit, Richtung | –, Bezug positiv, Watt, beide |
| | Ziel-Netzleistung, Nicht nachregeln unter, Regelstärke, zu alt nach | 0 W, 20 W, 70 %, 120 s |
| Grenzen | Mindestleistung Laden / Entladen | 100 W / 100 W |
| | Unter der Mindestleistung | nichts tun |
| | Entladen nur über / Laden nur unter Ladezustand | 0 % / 100 % (= aus) |
| Abfrage | Abfrage und Regelung, Geräteinfo, Fehler melden nach | 5 s, 60 s, 3 Versuchen |

## 9 Funktionen für Skripte

Alle Schreibfunktionen verlangen das Häkchen „Steuerung erlauben“.

| Funktion | Zweck |
|---|---|
| `ANK_Update(int $id): bool` | sofort abfragen und nachregeln |
| `ANK_SetControl(int $id, int $Mode): bool` | 0 = Anker, 1 = Manuell |
| `ANK_SetRegulation(int $id, int $Mode): bool` | 0 Pause, 1 Laden, 2 Entladen, 3 Netzregelung, 4 Extern |
| `ANK_SetChargePower(int $id, int $Watt): bool` | Ladeleistung für „Laden“ |
| `ANK_SetDischargePower(int $id, int $Watt): bool` | Entladeleistung für „Entladen“ |
| `ANK_SetMaxChargePower(int $id, int $Watt): bool` | oberste Grenze Laden |
| `ANK_SetMaxDischargePower(int $id, int $Watt): bool` | oberste Grenze Entladen |
| `ANK_SetPower(int $id, int $Watt): bool` | externe Vorgabe: plus = Laden, minus = Entladen, 0 = nichts. Liefert `true`, wenn sie gerade wirkt (Manuell + Extern) |
| `ANK_SetAnkerMode(int $id, int $Mode): bool` | 0 Eigenverbrauch, 1 Zeitplan, 4 Benutzerdefiniert, 6 Smart, 7 Dynamische Preise |
| `ANK_GetSummary(int $id): string` | wichtige Werte als JSON |
| `ANK_TestConnection(int $id): bool` | Verbindung prüfen (Formular-Knopf) |
| `ANK_SetConnection(int $id, string $Host, int $Port, int $DeviceID): bool` | IP-Adresse und Port in den Client Socket, Geräte-ID ins Gateway schreiben (Formular-Knopf „Übernehmen“) |
| `ANK_ReadRegisters(int $id, int $Type, int $Address, int $Quantity): string` | für Fachleute: Register roh lesen (Typ 3 = Holding, 4 = Input) |

**Beispiel für einen eigenen Energiemanager:** einmal `ANK_SetControl($id, 1)` und `ANK_SetRegulation($id, 4)`, danach regelmäßig (öfter als der Wächter, z. B. alle 10 Sekunden) `ANK_SetPower($id, $watt)`.

## 10 Was man wissen sollte

- **Symcon fällt aus:** Der Speicher hat bei Fremdsteuerung keinen eigenen Wächter. Er behält die letzte Vorgabe, bis wieder jemand etwas schreibt. Der Wächter des Moduls hilft nur, solange Symcon läuft.
- **Notstrom-Reserve:** Liegt der Ladezustand unter der in der App eingestellten Reserve, lädt der Speicher von sich aus mit voller Leistung aus dem Netz und übergeht die Fremdsteuerung. Über der Reserve kann sie das Entladen sperren. Wer voll selbst steuern will, schaltet Reserve, Schnellladen und Storm Guard in der App aus.
- **0 Watt sperrt beide Richtungen:** Bei „Pause“ lädt der Speicher auch keinen PV-Überschuss.
- **Nur ein Regler:** Nicht gleichzeitig mit Home Assistant, evcc oder Loxone auf denselben Speicher schreiben.
- **Abweichungen zur App:** Das Modul liest die Rohwerte des Speichers, die App zeigt Cloud-Werte im 5-Minuten-Takt.
- **Netzeinspeisung** ist in manchen Ländern ab Werk gesperrt und lässt sich nur in der App freigeben.

## 11 Technik und Quellen

- Modbus TCP über das ModBus Gateway von Symcon (das Gateway hält die Verbindung), Geräte-ID 1, Werte Big-Endian. Lesen mit Funktion 3/4, Schreiben mit Funktion 6 (Betriebsart) und 16 (Leistungsvorgabe).
- Betriebsart: Holding-Register 10064 (3 = Fremdsteuerung). Leistungsvorgabe: Holding-Register 10071, 32 Bit mit Vorzeichen, **am Gerät** negativ = Laden, positiv = Entladen. Das Modul dreht das Vorzeichen um (plus = Laden).
- Die Registerkarte steht in `libs/ANKRegisters.php`.
- Quellen: die offizielle Home-Assistant-Integration [anker-charging/ha-anker-solix-official](https://github.com/anker-charging/ha-anker-solix-official) (MIT-Lizenz, Stand 29.10.2026) und das evcc-Template `anker-solix-solarbank-max-ac`. Von dort stammen nur die Registeradressen und Wertebedeutungen, kein Programmcode.
- Tests ohne Gerät: `php tests/sim.php` (Speicher und Gateway sind in `tests/fake_anker.php` nachgebaut).

## Lizenz

[CC BY-NC-SA 4.0](LICENSE)

## Markenhinweis

Anker und SOLIX sind Marken ihres jeweiligen Inhabers. ANKERlocal ist ein privates Projekt und steht in keiner Verbindung zum Hersteller.
