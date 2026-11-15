# Testliste

Build 1 ist nur gegen einen nachgebauten Speicher getestet (`tests/sim.php`). Alles hier muss am echten Gerät geprüft werden. Reihenfolge von oben nach unten – erst lesen, dann schalten, dann regeln.

| Was | Wie prüfen | Wann | Status |
|---|---|---|---|
| Anlegen | Instanz anlegen: Symcon bietet vorhandene ModBus Gateways an oder legt ein neues an. Kein „Datenfluss ist inkompatibel“. | geht schon ohne Gerät | offen |
| Verbindung einstellen | Unten IP eintragen, „Übernehmen“: Stehen IP und Port danach im Client Socket, die Geräte-ID im Gateway? Zeigt „Aktuell: …“ die Werte? Neuer Socket: Port von selbst 502? | geht schon ohne Gerät | offen |
| Verbindung | „Verbindung prüfen“. Ergebnis nennt Modell, Akku, Betriebsart. | sobald das Gerät da ist | offen |
| Dauerbetrieb | Läuft die Abfrage alle 5 Sekunden über Stunden ohne Aussetzer? Debug-Fenster auf „Fehler“ achten. | erster Tag | offen |
| Stecker ziehen | Speicher vom Netz trennen: Instanz meldet nach drei Versuchen „nicht erreichbar“, es wird nichts mehr gesendet; nach dem Einstecken läuft es von selbst weiter. | erster Tag | offen |
| Schreiben über das Gateway | Kommen negative Werte richtig an (Laden = negativer Wert in Register 10071)? Zeigt sich beim Test „Laden von Hand“. | beim ersten Schalten | offen |
| Zweiter Modbus-Teilnehmer | Geht die Abfrage noch, wenn gleichzeitig z. B. Loxone oder Home Assistant verbunden ist? | bei Gelegenheit | offen |
| Messwerte | Ladezustand, PV-Leistung, Zähler mit der Anker-App vergleichen. | Tag mit Sonne | offen |
| Vorzeichen Batterieleistung | Beim Laden muss „Batterieleistung“ positiv sein, beim Entladen negativ. | beim Laden / Entladen | offen |
| Netzleistung / Hausverbrauch (Gerät) | Sind die Werte ohne Anker-Zähler 0 oder sinnvoll? | erster Tag | offen |
| Modell, Seriennummer, Firmware | Stimmen sie mit der App überein? Wird das Modell erkannt (sonst steht nur ein Kürzel da)? | erster Tag | offen |
| Grenzen aus der App | Ladegrenze, Entladegrenze, Notstrom-Reserve: stimmen die Werte? Bleiben sie leer, kennt die Firmware die Register nicht. | erster Tag | offen |
| Steuerung → Manuell | Häkchen „Steuerung erlauben“, Variable „Steuerung“ auf Manuell. App zeigt „Steuerung durch Drittanbieter“, „Regelung“ steht auf Pause, Speicher tut nichts. | danach | offen |
| Betriebsart-Rückmeldung | Meldet der Speicher die neue Betriebsart sofort? Debug „Rücklesen 10064“ ansehen. | dabei | offen |
| Laden von Hand | Regelung „Laden“, Ladeleistung 500 W. Lädt der Speicher mit etwa 500 W? **Wichtigster Test fürs Vorzeichen von Register 10071.** | Tag, an dem Laden geht | offen |
| Entladen von Hand | Regelung „Entladen“, 300 W. Entlädt er? | Akku über Entladegrenze | offen |
| Rücklesen der Vorgabe | Debug „Rücklesen 10071“: Meldet der Speicher die geschriebene Vorgabe zurück oder immer etwas anderes? | dabei | offen |
| Hält die Vorgabe? | Bleibt die Leistung über Minuten stehen oder fällt der Speicher von selbst zurück? (Das Modul sendet alle 30 Sekunden neu.) | dabei | offen |
| Max. Ladeleistung | Max. Ladeleistung auf 600 W, Ladeleistung 800 W → Sollleistung 600 W. | dabei | offen |
| Gerätegrenze | In der App die Höchstleistung ändern → Variable „Gerätegrenze“ folgt und begrenzt. | bei Gelegenheit | offen |
| Kleine Leistungen | 100 W, 150 W, 200 W von Hand: wie genau regelt der Speicher? Danach Mindestleistung anpassen. | bei Gelegenheit | offen |
| Netzregelung – Anzeige | Netzleistungs-Variable wählen. Zeigt das Formular Bezug/Einspeisung richtig herum? | vor dem ersten Regeln | offen |
| Netzregelung – Überschuss | Regelung „Netzregelung“ bei PV-Überschuss: Netzleistung pendelt sich um 0 W ein? Schwingt es, Regelstärke senken. | Tag mit Sonne | offen |
| Netzregelung – Bezug | Abends: entlädt er passend zum Verbrauch? | Abend | offen |
| Wirkt 10071 auf Netz- oder Batterieseite? | Bei angeschlossener PV am Speicher: Vorgabe 0 W – speist er PV ein oder lädt er? Bestimmt, ob die Vorgabe AC-Leistung oder Batterieleistung meint. | Tag mit Sonne | offen |
| Extern + Wächter | Regelung „Extern“, `ANK_SetPower($id, 500)` im Skript, dann 60 Sekunden nichts → geht auf 0 W. | bei Gelegenheit | offen |
| Zurück zu Anker | Steuerung „Anker“ → App zeigt wieder die alte Betriebsart, Speicher regelt selbst. | danach | offen |
| Umstellen in der App | In der App auf Drittanbieter stellen → Variable „Steuerung“ folgt, Regelung geht auf Pause. | bei Gelegenheit | offen |
| Notstrom-Reserve | Verhalten, wenn der Ladezustand unter der Reserve liegt (lädt er trotz Vorgabe aus dem Netz?). | bei Gelegenheit | offen |
| Symcon-Neustart | Speicher in „Manuell/Laden“, Symcon neu starten: Läuft es danach richtig weiter? | bei Gelegenheit | offen |
| Darstellungen in der App | Alle Variablen in der Symcon-App (iOS) ansehen – keine Fehlermeldung statt Wert. | erster Tag | offen |
