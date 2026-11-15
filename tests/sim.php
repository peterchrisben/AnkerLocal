<?php

declare(strict_types=1);

/*
 * sim.php – Simulation des Moduls gegen den nachgebauten Speicher samt Gateway (fake_anker.php).
 * Aufruf:  php tests/sim.php
 */

require __DIR__ . '/SymconStubs.php';
require __DIR__ . '/fake_anker.php';
Stub::$echoDebug = false;
FakeAnker::Reset();
$port = 0;
$fails = 0;
function check(string $what, $actual, $expected): void
{
    global $fails;
    $ok = $actual === $expected;
    if (!$ok) {
        $fails++;
    }
    echo ($ok ? '  ok   ' : '  FEHLER ') . $what . ($ok ? '' : ' – erwartet ' . var_export($expected, true) . ', ist ' . var_export($actual, true)) . "\n";
}
function val(int $id, string $ident)
{
    foreach (Stub::$objects as $o) {
        if ($o['type'] === 'variable' && $o['parent'] === $id && $o['ident'] === $ident) {
            return $o['value'];
        }
    }
    return null;
}
function status(int $id): int
{
    return Stub::$objects[$id]['status'];
}
function setProps(int $id, array $props): void
{
    foreach ($props as $k => $v) {
        IPS_SetProperty($id, $k, $v);
    }
    IPS_ApplyChanges($id);
}
function fakeHolding(int $port, int $addr, int $qty = 1): array
{
    $out = [];
    for ($i = 0; $i < $qty; $i++) {
        $out[] = FakeAnker::$holding[$addr + $i];
    }
    return $out;
}
function fakeSetpoint(int $port): int
{
    return FakeAnker::Setpoint();
}
function fakeSetInput(int $port, int $addr, int $value): void
{
    FakeAnker::$input[$addr] = $value & 0xFFFF;
}
function expectException(string $what, callable $fn): void
{
    global $fails;
    try {
        $fn();
        $fails++;
        echo "  FEHLER $what – keine Ausnahme\n";
    } catch (Exception $e) {
        echo "  ok   $what → „" . $e->getMessage() . "“\n";
    }
}

$class = Stub::LoadModule(__DIR__ . '/../AnkerSolix');

/** Modul mit nachgebautem Gateway statt echtem Datenfluss */
class AnkerSolixSim extends AnkerSolix
{
    protected function SendDataToParent($json)
    {
        return FakeAnker::Handle($json);
    }
    protected function HasActiveParent()
    {
        return FakeAnker::$online && (int) (IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0) > 0;
    }
}
$class = 'AnkerSolixSim';

echo "1 Anlegen ohne Gateway\n";
$lonely = Stub::CreateInstance($class, []);
check('Status 104', status($lonely), 104);
check('Update ohne Gateway', ANK_Update($lonely), false);
check('Max. Ladeleistung Startwert', val($lonely, 'MaxChargePower'), 3500);
check('Ladeleistung Startwert', val($lonely, 'ChargePower'), 100);
expectException('Schalten ohne Freigabe', fn() => IPS_RequestAction($lonely, 'Control', 1));
$form = json_decode(Stub::$instances[$lonely]->GetConfigurationForm(), true);
check('Formular gültig', is_array($form) && isset($form['elements']), true);
echo '       Überblick: ' . $form['elements'][0]['caption'] . "\n";
unset(Stub::$objects[$lonely], Stub::$instances[$lonely]);

echo "2 Gateway, Verbindung einstellen, Lesen\n";
[$gateway, $socket] = Stub_CreateGatewayChain();
$id = Stub::CreateInstance($class, [], $gateway);
check('Neuer Client Socket: Port 0 → 502', IPS_GetProperty($socket, 'Port'), 502);
$form = json_decode(Stub::$instances[$id]->GetConfigurationForm(), true);
check('Einrichtung aufgeklappt', $form['elements'][1]['expanded'], true);
echo '       Überblick: ' . $form['elements'][0]['caption'] . "\n";
echo '       ' . $form['elements'][1]['items'][1]['caption'] . "\n";
check('Vorschlag Port 502', $form['actions'][0]['items'][0]['items'][1]['value'], 502);
check('Vorschlag Geräte-ID 1', $form['actions'][0]['items'][0]['items'][2]['value'], 1);
check('SetConnection ohne IP abgelehnt', ANK_SetConnection($id, '', 502, 1), false);
check('SetConnection', ANK_SetConnection($id, '192.168.1.50', 502, 1), true);
check('Socket Host', IPS_GetProperty($socket, 'Host'), '192.168.1.50');
check('Socket geöffnet', IPS_GetProperty($socket, 'Open'), true);
check('Gateway Geräte-ID', IPS_GetProperty($gateway, 'DeviceID'), 1);
$other = Stub::CreateInstance($class, [], $gateway);
check('Geteiltes Gateway: SetConnection abgelehnt', ANK_SetConnection($id, '192.168.1.51', 502, 1), false);
echo '       ' . Stub::$instances[$id]->__formFields()['ConnResult']['caption'] . "\n";
unset(Stub::$objects[$other], Stub::$instances[$other]);
check('Timer 5 s', Stub::$instances[$id]->__timers()['Update']['interval'], 5000);
check('Update', ANK_Update($id), true);
check('Status 102', status($id), 102);
check('SOC', val($id, 'SOC'), 55);
check('PV-Leistung (eigen + fremd)', val($id, 'PVPower'), 1500);
check('Netzleistung', val($id, 'GridPower'), -1050);
check('Hausverbrauch', val($id, 'LoadPower'), 450);
check('PV-Ertrag', val($id, 'PVEnergy'), 1234.5);
check('Nennkapazität', val($id, 'RatedEnergy'), 7.0);
check('Modell', val($id, 'Model'), 'Anker SOLIX Solarbank Max AC');
check('Seriennummer', val($id, 'Serial'), 'A17DMWH1234567890');
check('Firmware', val($id, 'Firmware'), 'v1.2.3');
check('Entladegrenze App', val($id, 'DischargeLimitSOC'), 10);
check('Gerätegrenze Laden', val($id, 'DeviceMaxCharge'), 3500);
check('Steuerung = Anker', val($id, 'Control'), 0);
check('Erreichbar', val($id, 'Online'), true);
echo '       Hinweis: ' . val($id, 'ControlInfo') . "\n";
check('Nur lesen: nichts geschrieben', fakeHolding($port, 10064)[0], 0);
FakeAnker::$utf8 = true;
IPS_ApplyChanges($id);
check('Antworten als UTF-8: Update', ANK_Update($id), true);
check('… Netzleistung (negativ, hohe Bytes)', val($id, 'GridPower'), -1050);
check('… Seriennummer', val($id, 'Serial'), 'A17DMWH1234567890');
FakeAnker::$utf8 = false;
expectException('SetControl ohne Freigabe', fn() => ANK_SetControl($id, 1));
check('Verbindung prüfen', ANK_TestConnection($id), true);
echo '       ' . Stub::$instances[$id]->__formFields()['Result']['caption'] . "\n";

echo "3 Manuell: Pause, Laden, Entladen, Grenzen\n";
setProps($id, ['AllowControl' => true]);
check('SetControl(Manuell)', ANK_SetControl($id, 1), true);
check('Gerät in Betriebsart 3', fakeHolding($port, 10064)[0], 3);
check('Steuerung = Manuell', val($id, 'Control'), 1);
check('Regelung = Pause', val($id, 'Regulation'), 0);
check('Vorgabe 0', fakeSetpoint($port), 0);
IPS_RequestAction($id, 'Regulation', 1);
IPS_RequestAction($id, 'ChargePower', 800);
check('Laden 800 W → Gerät −800', fakeSetpoint($port), -800);
ANK_Update($id);
check('Batterieleistung + = Laden', val($id, 'BatteryPower'), 800);
IPS_RequestAction($id, 'MaxChargePower', 600);
check('Max. Ladeleistung 600 greift', fakeSetpoint($port), -600);
check('Sollleistung am Gerät', val($id, 'Setpoint'), 600);
echo '       Hinweis: ' . val($id, 'ControlInfo') . "\n";
IPS_RequestAction($id, 'MaxChargePower', 3500);
fakeSetInput($port, 10037, 400);
ANK_Update($id);
check('Gerätegrenze 400 greift', fakeSetpoint($port), -400);
fakeSetInput($port, 10037, 3500);
IPS_RequestAction($id, 'DischargePower', 500);
IPS_RequestAction($id, 'Regulation', 2);
check('Entladen 500 W → Gerät +500', fakeSetpoint($port), 500);
check('Sollleistung negativ', val($id, 'Setpoint'), -500);
setProps($id, ['MinSOC' => 60]);
ANK_Update($id);
check('Entladen gesperrt unter 60 %', fakeSetpoint($port), 0);
echo '       Hinweis: ' . val($id, 'ControlInfo') . "\n";
setProps($id, ['MinSOC' => 0]);
IPS_RequestAction($id, 'Regulation', 0);
check('Pause', fakeSetpoint($port), 0);

echo "4 Extern\n";
check('SetPower wirkt nicht bei Pause', ANK_SetPower($id, -250), false);
check('… Gerät bleibt 0', fakeSetpoint($port), 0);
IPS_RequestAction($id, 'Regulation', 4);
check('SetPower(−250)', ANK_SetPower($id, -250), true);
check('Gerät +250 (Entladen)', fakeSetpoint($port), 250);
ANK_SetPower($id, 50);
check('50 W unter Minimum → 0', fakeSetpoint($port), 0);
setProps($id, ['BelowMinMode' => 1]);
ANK_SetPower($id, 50);
check('Aufrunden → 100 W Laden', fakeSetpoint($port), -100);
setProps($id, ['BelowMinMode' => 0, 'ExternalTimeout' => 1]);
ANK_SetPower($id, 1000);
check('1000 W Laden', fakeSetpoint($port), -1000);
sleep(2);
ANK_Update($id);
check('Wächter: nach Ablauf 0 W', fakeSetpoint($port), 0);
echo '       Hinweis: ' . val($id, 'ControlInfo') . "\n";
setProps($id, ['FallbackToAnker' => true]);
ANK_SetPower($id, 1000);
sleep(2);
ANK_Update($id);
check('Rückfall: Gerät wieder Betriebsart 0', fakeHolding($port, 10064)[0], 0);
check('Steuerung = Anker', val($id, 'Control'), 0);
setProps($id, ['FallbackToAnker' => false, 'ExternalTimeout' => 60, 'StartPaused' => false]);

echo "5 Netzregelung\n";
$grid = Stub_CreateVariable(VARIABLETYPE_FLOAT, 0.0, 'Netzleistung');
setProps($id, ['GridVariable' => $grid]);
check('Referenz angemeldet', in_array($grid, (function () { return $this->GetReferenceList(); })->call(Stub::$instances[$id]), true), true);
IPS_RequestAction($id, 'Regulation', 3);
ANK_SetControl($id, 1);
check('StartPaused aus: Regelung bleibt Netzregelung', val($id, 'Regulation'), 3);
$base = -1500;   // 1500 W Überschuss ohne Speicher
$sp = 0;
for ($i = 0; $i < 7; $i++) {
    SetValue($grid, (float) ($base + $sp));
    ANK_Update($id);
    $sp = val($id, 'Setpoint');
    echo '       Schritt ' . ($i + 1) . ': Netz ' . ($base + $sp) . ' W, Soll ' . $sp . " W\n";
    sleep(1);
}
check('Überschuss 1500 W → lädt ~1500 W', abs($sp - 1500) <= 20, true);
check('Gerät bekommt −Soll', fakeSetpoint($port), -$sp);
$base = 800;     // jetzt 800 W Bezug
for ($i = 0; $i < 8; $i++) {
    SetValue($grid, (float) ($base + $sp));
    ANK_Update($id);
    $sp = val($id, 'Setpoint');
    sleep(1);
}
check('Bezug 800 W → entlädt ~800 W', abs($sp + 800) <= 20, true);
$base = -60;     // kleiner Überschuss
for ($i = 0; $i < 6; $i++) {
    SetValue($grid, (float) ($base + $sp));
    ANK_Update($id);
    $sp = val($id, 'Setpoint');
    sleep(1);
}
check('60 W Überschuss → nichts tun', $sp, 0);
echo '       Hinweis: ' . val($id, 'ControlInfo') . "\n";
setProps($id, ['GridSign' => 1, 'GridUnit' => 1]);
SetValue($grid, 1.2);   // 1,2 kW Einspeisung bei umgekehrtem Vorzeichen
ANK_Update($id);
check('kW + umgekehrtes Vorzeichen → lädt 1200 W', val($id, 'Setpoint'), 1200);
setProps($id, ['GridSign' => 0, 'GridUnit' => 0, 'RegDirection' => 1]);
sleep(1);
SetValue($grid, 900.0);
ANK_Update($id);
check('Nur laden: bei Bezug 0 W', val($id, 'Setpoint'), 0);
setProps($id, ['RegDirection' => 0, 'GridTimeout' => 1]);
sleep(2);
ANK_Update($id);
check('Netzleistung zu alt → 0 W', val($id, 'Setpoint'), 0);
echo '       Hinweis: ' . val($id, 'ControlInfo') . "\n";
setProps($id, ['GridTimeout' => 120]);

echo "6 Zurück zu Anker, Betriebsart, Umstellen in der App\n";
check('SetControl(Anker)', ANK_SetControl($id, 0), true);
check('Gerät Betriebsart 0', fakeHolding($port, 10064)[0], 0);
IPS_RequestAction($id, 'AnkerMode', 6);
check('Anker-Betriebsart Smart geschrieben', fakeHolding($port, 10064)[0], 6);
ANK_Update($id);
check('Betriebsart am Gerät', val($id, 'DeviceMode'), 6);
setProps($id, ['StartPaused' => true]);
IPS_RequestAction($id, 'Regulation', 1);
FakeAnker::$holding[10064] = 3;          // jemand stellt in der App auf Fremdsteuerung
sleep(16);                              // Karenzzeit nach eigenem Schreiben abwarten
ANK_Update($id);
check('Steuerung folgt dem Gerät', val($id, 'Control'), 1);
check('… und beginnt mit Pause', val($id, 'Regulation'), 0);
ANK_SetControl($id, 0);
check('Zurück: Betriebsart wieder Smart', fakeHolding($port, 10064)[0], 6);
$sum = json_decode(ANK_GetSummary($id), true);
check('GetSummary', $sum['SOC'] === 55 && $sum['ControlText'] === 'Anker', true);
check('ReadRegisters', json_decode(ANK_ReadRegisters($id, 4, 10014, 1), true), [55]);
$form = json_decode(Stub::$instances[$id]->GetConfigurationForm(), true);
echo '       Überblick: ' . $form['elements'][0]['caption'] . "\n";
check('Einrichtung zugeklappt', $form['elements'][1]['expanded'], false);

echo "7 Gerät nicht erreichbar\n";
FakeAnker::$silent = true;
ANK_Update($id);
check('1. Fehlversuch: Status bleibt', status($id), 102);
ANK_Update($id);
ANK_Update($id);
check('3. Fehlversuch: Status 201', status($id), 201);
check('Erreichbar = Nein', val($id, 'Online'), false);
FakeAnker::$silent = false;
ANK_Update($id);
check('Wieder da: Status 102', status($id), 102);
FakeAnker::$online = false;
$before = FakeAnker::$requests;
ANK_Update($id);
check('Gateway nicht aktiv: es wird nichts gesendet', FakeAnker::$requests, $before);
expectException('Schalten ohne Verbindung', fn() => ANK_SetControl($id, 1));
FakeAnker::$online = true;
check('Verbindung wieder da', ANK_Update($id), true);

$warn = array_filter(Stub::$log, fn($l) => strpos($l, 'WARNUNG') !== false && strpos($l, 'MessageSink') === false && strpos($l, 'RequestAction') === false && strpos($l, 'ReceiveData') === false);
foreach ($warn as $w) {
    echo "  $w\n";
}
echo $fails === 0 && !$warn ? "\nALLES IN ORDNUNG\n" : "\n$fails FEHLER, " . count($warn) . " Warnungen\n";
exit($fails === 0 ? 0 : 1);
