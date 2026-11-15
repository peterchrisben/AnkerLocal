<?php

declare(strict_types=1);

/**
 * ANKRegisters – Registerkarte der Anker-SOLIX-Speicher (lokales Modbus TCP).
 *
 * Autor: Peter Chrisben
 *
 * Quellen (Stand 15.11.2026):
 *  1) Offizielle Home-Assistant-Integration anker-charging/ha-anker-solix-official,
 *     Commit 33e5813 (29.10.2026), config/*.yaml
 *  2) evcc-Template anker-solix-solarbank-max-ac.yaml
 *
 * Solarbank Max AC, Solarbank Max, XE AC, XE und Solarbank 4 E5000 Pro haben in der
 * HA-Integration dieselbe Registerkarte – sie unterscheiden sich nur im Namen.
 */
final class ANKRegisters
{
    // Registerarten
    public const INPUT = 4;      // FC 04, nur lesen
    public const HOLDING = 3;    // FC 03, lesen und schreiben

    // Schreibregister
    public const REG_MODE = 10064;       // UINT16, Betriebsart
    public const REG_SETPOINT = 10071;   // INT32, Leistungsvorgabe: negativ = Laden, positiv = Entladen

    public const MODE_THIRD_PARTY = 3;

    /** Betriebsarten des Geräts (Register 10064) */
    public const MODES = [
        0 => 'Eigenverbrauch',
        1 => 'Zeitplan (TOU)',
        3 => 'Fremdsteuerung',
        4 => 'Benutzerdefiniert',
        5 => 'Steckdosen-Modus',
        6 => 'Smart',
        7 => 'Dynamische Preise'
    ];

    /** Betriebsarten, die bei „Steuerung = Anker“ wählbar sind */
    public const ANKER_MODES = [
        0 => 'Eigenverbrauch',
        1 => 'Zeitplan (TOU)',
        4 => 'Benutzerdefiniert',
        6 => 'Smart',
        7 => 'Dynamische Preise'
    ];

    /** Batteriestatus (Register 10001) */
    public const BATTERY_STATUS = [
        0 => 'Bereit',
        1 => 'Lädt',
        2 => 'Entlädt',
        3 => 'Ruhezustand'
    ];

    /**
     * Blöcke, die in einem Zug gelesen werden: Name => [Registerart, Start, Anzahl, Pflicht].
     * Pflicht-Blöcke gehören zur schnellen Abfrage; fehlt einer, gilt die Runde als fehlgeschlagen.
     * Die übrigen werden selten gelesen und dürfen fehlen (ältere Firmware).
     */
    public const BLOCKS = [
        'live'   => [self::INPUT, 10000, 40, true],
        'energy' => [self::INPUT, 10208, 58, true],
        'ctrl'   => [self::HOLDING, 10060, 13, true],
        'info'   => [self::INPUT, 10100, 18, false],
        'model'  => [self::INPUT, 32768, 5, false],
        'soc'    => [self::HOLDING, 60000, 4, false]
    ];

    /**
     * Messwerte: Ident => [Block, Register, Typ, Teiler].
     * Typen: u16, i32, u32, str<Anzahl Register>
     */
    public const VALUES = [
        'BatteryStatus'     => ['live', 10001, 'u16', 1],
        'PVPowerOwn'        => ['live', 10002, 'i32', 1],
        'PVPowerThird'      => ['live', 10004, 'i32', 1],
        'BatteryPowerRaw'   => ['live', 10008, 'i32', 1],   // positiv = Entladen
        'LoadPower'         => ['live', 10010, 'i32', 1],
        'GridPower'         => ['live', 10012, 'i32', 1],   // positiv = Bezug
        'SOC'               => ['live', 10014, 'u16', 1],
        'PVEnergy'          => ['live', 10018, 'u32', 10],
        'DeviceMaxCharge'   => ['live', 10036, 'i32', 1],
        'DeviceMaxDischarge'=> ['live', 10038, 'i32', 1],
        'ACOutputPower'     => ['energy', 10208, 'i32', 1],
        'RatedEnergy'       => ['energy', 10250, 'u32', 10],
        'ChargeEnergy'      => ['energy', 10262, 'u32', 10],
        'DischargeEnergy'   => ['energy', 10264, 'u32', 10],
        'DeviceMode'        => ['ctrl', 10064, 'u16', 1],
        'DeviceSetpointRaw' => ['ctrl', 10071, 'i32', 1],   // negativ = Laden
        'Serial'            => ['info', 10100, 'str12', 1],
        'Firmware'          => ['info', 10112, 'str6', 1],
        'ModelCode'         => ['model', 32768, 'str5', 1],
        'ChargeLimitSOC'    => ['soc', 60000, 'u16', 1],
        'DischargeLimitSOC' => ['soc', 60001, 'u16', 1],
        'BackupReserveSOC'  => ['soc', 60002, 'u16', 1],
        'BackupEnabled'     => ['soc', 60003, 'u16', 1]
    ];

    /** Produktkürzel aus der Seriennummer (Zeichen 4–6 bzw. 4–7) => Modellname */
    public const PRODUCT_CODES = [
        'DMWH' => 'Anker SOLIX Solarbank Max AC',
        'DMXU' => 'Anker SOLIX Solarbank Max AC',
        'E25H' => 'Anker SOLIX Solarbank Max AC',
        'DNMS' => 'Anker SOLIX XE AC',
        'DPP4' => 'Anker SOLIX XE AC',
        'DNN3' => 'Anker SOLIX XE AC',
        'DMY6' => 'Anker SOLIX Solarbank Max',
        'DN9U' => 'Anker SOLIX Solarbank Max',
        'DPTK' => 'Anker SOLIX Solarbank Max',
        'DNMT' => 'Anker SOLIX XE',
        'DPP5' => 'Anker SOLIX XE',
        'DNN4' => 'Anker SOLIX XE',
        'DRE4' => 'Anker SOLIX XE',
        'E3DS' => 'Anker SOLIX XE',
        'DN7M' => 'Anker SOLIX Solarbank 4 E5000 Pro',
        'DPM4' => 'Anker SOLIX Solarbank 4 E5000 Pro'
    ];

    public static function ModelFromSerial(string $serial): string
    {
        $serial = trim($serial);
        $code = '';
        if (strlen($serial) === 16) {
            $code = substr($serial, 3, 3);
        } elseif (strlen($serial) === 17) {
            $code = substr($serial, 3, 4);
        }
        return self::PRODUCT_CODES[$code] ?? '';
    }
}
