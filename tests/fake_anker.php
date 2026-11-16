<?php

declare(strict_types=1);

/*
 * fake_anker.php – nachgebauter Anker-SOLIX-Speicher samt ModBus Gateway für Tests (nur im Speicher).
 *
 * Zwei Gerätefamilien: 'sb' (Solarbank Max AC, höherwertiges Wort zuerst, Zähler ×0,1 kWh) und
 * 'x1' (X1-H12K-T, niederwertiges Wort/Byte zuerst, Zähler ×0,01 kWh).
 * Verhalten: Leistungsvorgabe (10071) wird nur in Betriebsart 3 angenommen; die Batterieleistung
 * (10008) folgt der Vorgabe. Fehler meldet das „Gateway“ wie das echte als PHP-Warnung.
 */
final class FakeAnker
{
    public static string $family = 'sb';
    public static array $input = [];
    public static array $holding = [];
    public static bool $online = true;      // false = Gateway/Socket nicht aktiv
    public static bool $silent = false;     // true = Gerät antwortet nicht (Zeitüberschreitung)
    public static bool $utf8 = false;       // true = Antworten UTF-8-kodiert (je nach Symcon-Version)
    public static int $requests = 0;

    public static function Reset(string $family = 'sb'): void
    {
        self::$family = $family;
        self::$input = [];
        self::$holding = [];
        self::$online = true;
        self::$silent = false;
        self::$utf8 = false;
        for ($a = 10000; $a <= 10265; $a++) {
            self::$input[$a] = 0;
        }
        for ($a = 10060; $a <= 10081; $a++) {
            self::$holding[$a] = 0;
        }
        $e = $family === 'x1' ? 10 : 1;      // X1 zählt in 0,01 kWh
        self::Set32(10002, 1200);            // PV eigen
        self::Set32(10004, 300);             // PV fremd
        self::Set32(10008, 0);               // Batterie (+ Entladen)
        self::Set32(10010, 450);             // Haus
        self::Set32(10012, -1050);           // Netz (+ Bezug)
        self::$input[10014] = 55;            // SoC
        self::Set32(10018, 12345 * $e);      // PV-Ertrag 1234,5 kWh
        self::Set32(10036, 3500);
        self::Set32(10038, 3500);
        self::Set32(10262, 2345 * $e);
        self::Set32(10264, 2100 * $e);
        if ($family === 'x1') {
            self::$input[10000] = 1;                     // am Netz
            self::Set32(10006, -800);                    // PCS-AC-Seite
            self::$input[10015] = 98;                    // SOH
            self::Set32(10026, 500000);                  // Hausverbrauch 5000 kWh
            self::Set32(10030, 300000);
            self::Set32(10034, 150000);
            self::SetString(10090, 10, 'X1-H12K-T');
            self::SetString(10100, 12, 'APC123456789012');
            self::SetString(10112, 6, '1.0.19.16');
            self::Set32(10124, 6000);                    // Nennleistung
            self::Set32(10126, 6600);                    // max. Wirkleistung
            self::$input[10156] = 0xFFCE;                // −5,0 °C
            self::$input[10249] = 2;                     // Akkumodule
            self::$input[10253] = 512;                   // 51,2 V
            self::$holding[10064] = 20;                  // von der App geführt
            self::$holding[10080] = 1800;                // Geräte-Wächter
        } else {
            for ($a = 32768; $a <= 32774; $a++) {
                self::$input[$a] = 0;
            }
            self::Set32(10250, 70);                      // 7,0 kWh
            self::SetString(10100, 12, 'A17DMWH1234567890');
            self::SetString(10112, 6, 'v1.2.3');
            self::SetString(32768, 5, 'A17E2');
            self::$holding[60000] = 100;
            self::$holding[60001] = 10;
            self::$holding[60002] = 5;
            self::$holding[60003] = 0;
        }
    }

    public static function Set32(int $address, int $value): void
    {
        $high = ($value >> 16) & 0xFFFF;
        $low = $value & 0xFFFF;
        self::$input[$address] = self::$family === 'x1' ? $low : $high;
        self::$input[$address + 1] = self::$family === 'x1' ? $high : $low;
    }

    public static function SetString(int $address, int $count, string $text): void
    {
        $text = str_pad($text, $count * 2, "\0");
        for ($i = 0; $i < $count; $i++) {
            $first = ord($text[$i * 2]);
            $second = ord($text[$i * 2 + 1]);
            self::$input[$address + $i] = self::$family === 'x1' ? ($second << 8) | $first : ($first << 8) | $second;
        }
    }

    public static function Setpoint(): int
    {
        $v = self::$family === 'x1'
            ? (self::$holding[10072] << 16) | self::$holding[10071]
            : (self::$holding[10071] << 16) | self::$holding[10072];
        return $v >= 0x80000000 ? $v - 0x100000000 : $v;
    }

    /** Entspricht SendDataToParent am ModBus Gateway */
    public static function Handle(string $json)
    {
        self::$requests++;
        $q = json_decode($json, true);
        if (!is_array($q) || ($q['DataID'] ?? '') !== '{E310B701-4AE7-458E-B618-EC13A1A6F6A8}') {
            trigger_error('Falsche DataID', E_USER_WARNING);
            return false;
        }
        if (self::$silent) {
            trigger_error('Timeout', E_USER_WARNING);
            return false;
        }
        $fc = (int) $q['Function'];
        $addr = (int) $q['Address'];
        $qty = (int) $q['Quantity'];
        $data = mb_convert_encoding((string) $q['Data'], 'ISO-8859-1', 'UTF-8');

        if ($fc === 3 || $fc === 4) {
            $regs = $fc === 3 ? self::$holding : self::$input;
            $payload = '';
            for ($i = 0; $i < $qty; $i++) {
                if (!isset($regs[$addr + $i])) {
                    trigger_error('Illegal data address', E_USER_WARNING);
                    return false;
                }
                $payload .= pack('n', $regs[$addr + $i]);
            }
            $response = chr($fc) . chr(strlen($payload)) . $payload;
            return self::$utf8 ? mb_convert_encoding($response, 'UTF-8', 'ISO-8859-1') : $response;
        }
        if ($fc === 6 || $fc === 16) {
            if (strlen($data) !== $qty * 2) {
                trigger_error('Falsche Datenlänge', E_USER_WARNING);
                return false;
            }
            foreach (array_values(unpack('n*', $data)) as $i => $value) {
                $a = $addr + $i;
                if ($a === 10071 || $a === 10072) {
                    if (self::$holding[10064] !== 3) {
                        trigger_error('Illegal data value', E_USER_WARNING);
                        return false;
                    }
                    self::$holding[$a] = $value;
                } elseif (isset(self::$holding[$a])) {
                    self::$holding[$a] = $value;
                    if ($a === 10064 && $value !== 3) {
                        self::$holding[10071] = 0;
                        self::$holding[10072] = 0;
                    }
                } else {
                    trigger_error('Illegal data address', E_USER_WARNING);
                    return false;
                }
            }
            // Batterie folgt der Vorgabe
            self::$input[10008] = self::$holding[10071];
            self::$input[10009] = self::$holding[10072];
            $sp = self::Setpoint();
            self::$input[10001] = $sp === 0 ? 0 : ($sp < 0 ? 1 : 2);
            return '';
        }
        trigger_error('Illegal function', E_USER_WARNING);
        return false;
    }
}

/** Nachbildung Client Socket / ModBus Gateway (nur Properties) */
class FakeSocket extends IPSModule
{
    public function Create()
    {
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 0);
        $this->RegisterPropertyBoolean('Open', false);
    }
}
class FakeGateway extends IPSModule
{
    public function Create()
    {
        $this->RegisterPropertyInteger('GatewayMode', 0);
        $this->RegisterPropertyInteger('DeviceID', 0);
        $this->RegisterPropertyBoolean('SwapWords', false);
    }
}

// ---------------------------------------------------------------- Ergänzungen zu den Symcon-Stubs

function IPS_GetInstanceList(): array
{
    $out = [];
    foreach (Stub::$objects as $id => $o) {
        if ($o['type'] === 'instance') {
            $out[] = $id;
        }
    }
    return $out;
}

function IPS_GetObjectIDByIdent(string $ident, int $parent)
{
    foreach (Stub::$objects as $id => $o) {
        if (($o['parent'] ?? -1) === $parent && ($o['ident'] ?? '') === $ident && $o['type'] === 'variable') {
            return $id;
        }
    }
    return false;
}

function IPS_GetInstanceListByModuleID(string $guid): array
{
    return [99999];
}

function AC_GetLoggingStatus(int $archive, int $id): bool
{
    return (bool) (Stub::$objects[$id]['logging'] ?? false);
}

/** Kette Client Socket → Gateway anlegen, liefert [Gateway-ID, Socket-ID] */
function Stub_CreateGatewayChain(): array
{
    $io = Stub::CreateInstance('FakeSocket');
    return [Stub::CreateInstance('FakeGateway', [], $io), $io];
}
