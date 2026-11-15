# Changelog

## Version 1.0

### Build 1 (15.11.2026)

#### Neu
- Erste Version: Anker-SOLIX-Speicher (Solarbank Max AC, Solarbank Max, XE AC, XE, Solarbank 4 E5000 Pro) lokal über Modbus TCP, ohne Cloud. Anbindung über das ModBus Gateway von Symcon – ein vorhandenes Gateway nutzen oder ein neues anlegen.
- „Verbindung einstellen“ im Formular: IP-Adresse, Port (Vorschlag 502) und Geräte-ID (Vorschlag 1) per Knopf in Client Socket und Gateway schreiben; Anzeige, womit die Instanz gerade verbunden ist. Neuer Client Socket mit Port 0 bekommt von selbst 502.
- Vor jedem Lesen und Schreiben wird geprüft, ob die Verbindung steht.
- Lesen: Status, Ladezustand, Batterie-, PV-, Netz- und Hausleistung, AC-Ausgangsleistung, Zähler, Nennkapazität, Gerätegrenzen, Lade-/Entladegrenze und Notstrom-Reserve aus der App, Modell, Seriennummer, Firmware.
- Bedienung in zwei Stufen: „Steuerung“ (Anker / Manuell) und – bei Manuell – „Regelung“ (Pause, Laden, Entladen, Netzregelung, Extern).
- Getrennte Schieberegler für Ladeleistung und Entladeleistung; „Max. Ladeleistung“ und „Max. Entladeleistung“ als oberste Grenze für alle Regelungen, zusätzlich gilt die Gerätegrenze aus der Anker-App.
- Netzregelung: eingebauter Regler auf eine Ziel-Netzleistung (Standard 0 Watt) nach einer frei wählbaren Variable; Vorzeichen, Einheit, Richtung (laden und entladen / nur laden / nur entladen), Regelstärke und Totband einstellbar.
- Extern: Leistungsvorgabe über `ANK_SetPower` bzw. die Variable „Externe Vorgabe“, mit Wächter.
- Mindestleistung je Richtung (Standard 100 Watt); darunter wahlweise „nichts tun“ (Standard) oder aufrunden.
- Wächter: bleibt die Netzleistung oder die externe Vorgabe aus, 0 Watt oder zurück auf „Anker“.
- Steuerung nur mit dem Häkchen „Steuerung erlauben“ (Standard aus).
- Formular mit Überblickszeile, „Verbindung prüfen“ und Anzeige der gelesenen Netzleistung.
- Simulation ohne Gerät: `php tests/sim.php`.

#### Dokumentation
- README, Testliste (TODO.md), Hinweise zum Module Store (MODULE-STORE.md).
