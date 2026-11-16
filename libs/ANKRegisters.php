<?php

declare(strict_types=1);

/**
 * ANKRegisters – Registerkarten der Anker-SOLIX-Speicher (lokales Modbus TCP).
 *
 * Autor: Peter Chrisben
 *
 * Zwei Gerätefamilien mit gleicher Grundstruktur, aber unterschiedlicher Kodierung:
 *
 *  „sb“  Solarbank Max AC, Solarbank Max, XE AC, XE, Solarbank 4 E5000 Pro
 *        Quellen: offizielle Home-Assistant-Integration anker-charging/ha-anker-solix-official
 *        (Commit 33e5813, 29.10.2026, config/*.yaml) und evcc-Template anker-solix-solarbank-max-ac.
 *        32-Bit-Werte: höherwertiges Wort zuerst. Texte: höherwertiges Byte zuerst. Zähler ×0,1 kWh.
 *
 *  „x1“  X1-Serie (X1-H3.68K-S … X1-H12K-T)
 *        Quellen: „Anker SOLIX X1 Series Modbus Protocol“ V1.0.0 vom 07.08.2025 (Anker) und das
 *        Community-Projekt afewyards/anker-x1-ha (Commit d3356f4, 05.11.2026, an einer X1-H12K-T getestet).
 *        Abweichend vom Anker-Dokument laut echtem Gerät: 32-Bit-Werte niederwertiges Wort zuerst,
 *        Texte niederwertiges Byte zuerst, Zähler ×0,01 kWh.
 *
 * Betriebsart (10064) und Leistungsvorgabe (10071, negativ = Laden) sind bei beiden Familien gleich.
 */
final class ANKRegisters
{
    // Registerarten
    public const INPUT = 4;      // FC 04, nur lesen
    public const HOLDING = 3;    // FC 03, lesen und schreiben

    // Schreibregister (beide Familien)
    public const REG_MODE = 10064;       // UINT16, Betriebsart
    public const REG_SETPOINT = 10071;   // INT32, Leistungsvorgabe: negativ = Laden, positiv = Entladen
    public const REG_WATCHDOG = 10080;   // UINT16, nur X1: Sekunden ohne Modbus-Verkehr bis zum Rückfall

    public const MODE_THIRD_PARTY = 3;

    public const FAMILY_SB = 'sb';
    public const FAMILY_X1 = 'x1';

    /** Register mit dem Modellnamen der X1 (z. B. „X1-H5K-S“) – dient zur Erkennung der Familie */
    public const X1_MODEL_REGISTER = 10090;
    public const X1_MODEL_COUNT = 10;

    public const FAMILY_NAMES = [
        self::FAMILY_SB => 'Solarbank / XE',
        self::FAMILY_X1 => 'X1'
    ];

    /** Batteriestatus (Register 10001) */
    public const BATTERY_STATUS = [
        0 => 'Bereit',
        1 => 'Lädt',
        2 => 'Entlädt',
        3 => 'Ruhezustand'
    ];

    /** Anlagenstatus der X1 (Register 10000) */
    public const PLANT_STATUS = [
        1 => 'Am Netz',
        2 => 'Inselbetrieb',
        3 => 'Bereit',
        4 => 'Störung'
    ];

    private const PROFILES = [
        self::FAMILY_SB => [
            'lowWordFirst' => false,
            'lowByteFirst' => false,
            'energyDivisor' => 10,
            // Betriebsarten des Geräts (Register 10064)
            'modes' => [
                0 => 'Eigenverbrauch',
                1 => 'Zeitplan (TOU)',
                3 => 'Fremdsteuerung',
                4 => 'Benutzerdefiniert',
                5 => 'Steckdosen-Modus',
                6 => 'Smart',
                7 => 'Dynamische Preise'
            ],
            // Betriebsarten, die bei „Steuerung = Anker“ wählbar sind
            'ankerModes' => [
                0 => 'Eigenverbrauch',
                1 => 'Zeitplan (TOU)',
                4 => 'Benutzerdefiniert',
                6 => 'Smart',
                7 => 'Dynamische Preise'
            ],
            // Name => [Registerart, Start, Anzahl, Pflicht]
            'blocks' => [
                'live'   => [self::INPUT, 10000, 40, true],
                'energy' => [self::INPUT, 10208, 58, true],
                'ctrl'   => [self::HOLDING, 10060, 13, true],
                'info'   => [self::INPUT, 10100, 18, false],
                'model'  => [self::INPUT, 32768, 5, false],
                'soc'    => [self::HOLDING, 60000, 4, false]
            ],
            // Rohwert => [Block, Register, Typ]   Typen: u16, i16, i32, u32, str<Anzahl Register>
            'values' => [
                'BatteryStatus'     => ['live', 10001, 'u16'],
                'PVPower'           => ['live', 10002, 'i32'],
                'PVPowerThird'      => ['live', 10004, 'i32'],
                'BatteryPowerRaw'   => ['live', 10008, 'i32'],   // positiv = Entladen
                'LoadPower'         => ['live', 10010, 'i32'],
                'GridPower'         => ['live', 10012, 'i32'],   // positiv = Bezug
                'SOC'               => ['live', 10014, 'u16'],
                'PVEnergy'          => ['live', 10018, 'u32'],
                'Rechargeable'      => ['live', 10036, 'i32'],
                'Dischargeable'     => ['live', 10038, 'i32'],
                'ACOutputPower'     => ['energy', 10208, 'i32'],
                'RatedEnergy'       => ['energy', 10250, 'u32'],
                'ChargeEnergy'      => ['energy', 10262, 'u32'],
                'DischargeEnergy'   => ['energy', 10264, 'u32'],
                'DeviceMode'        => ['ctrl', 10064, 'u16'],
                'DeviceSetpointRaw' => ['ctrl', 10071, 'i32'],   // negativ = Laden
                'Serial'            => ['info', 10100, 'str12'],
                'Firmware'          => ['info', 10112, 'str6'],
                'ModelCode'         => ['model', 32768, 'str5'],
                'ChargeLimitSOC'    => ['soc', 60000, 'u16'],
                'DischargeLimitSOC' => ['soc', 60001, 'u16'],
                'BackupReserveSOC'  => ['soc', 60002, 'u16'],
                'BackupEnabled'     => ['soc', 60003, 'u16']
            ]
        ],
        self::FAMILY_X1 => [
            'lowWordFirst' => true,
            'lowByteFirst' => true,
            'energyDivisor' => 100,
            'modes' => [
                0  => 'Eigenverbrauch',
                1  => 'Zeitplan (TOU)',
                2  => 'Nur Notstrom',
                3  => 'Fremdsteuerung',
                4  => 'Benutzerdefiniert',
                5  => 'Steckdosen-Modus',
                16 => 'Dynamische Preise (App)',
                20 => 'Von der App geführt'
            ],
            'ankerModes' => [
                0 => 'Eigenverbrauch',
                1 => 'Zeitplan (TOU)',
                2 => 'Nur Notstrom',
                4 => 'Benutzerdefiniert'
            ],
            'blocks' => [
                'live'  => [self::INPUT, 10000, 40, true],
                'ctrl'  => [self::HOLDING, 10060, 21, true],
                'ident' => [self::INPUT, 10090, 38, false],
                'temp'  => [self::INPUT, 10156, 1, false],
                'batt'  => [self::INPUT, 10249, 17, false]
            ],
            'values' => [
                'PlantStatus'       => ['live', 10000, 'u16'],
                'BatteryStatus'     => ['live', 10001, 'u16'],
                'PVPower'           => ['live', 10002, 'i32'],
                'PVPowerThird'      => ['live', 10004, 'i32'],
                'ACOutputPower'     => ['live', 10006, 'i32'],   // PCS-AC-Seite, positiv = Abgabe
                'BatteryPowerRaw'   => ['live', 10008, 'i32'],
                'LoadPower'         => ['live', 10010, 'i32'],
                'GridPower'         => ['live', 10012, 'i32'],
                'SOC'               => ['live', 10014, 'u16'],
                'SOH'               => ['live', 10015, 'u16'],
                'PVEnergy'          => ['live', 10018, 'u32'],
                'LoadEnergy'        => ['live', 10026, 'u32'],
                'GridImportEnergy'  => ['live', 10030, 'u32'],
                'GridExportEnergy'  => ['live', 10034, 'u32'],
                'Rechargeable'      => ['live', 10036, 'i32'],
                'Dischargeable'     => ['live', 10038, 'i32'],
                'DeviceMode'        => ['ctrl', 10064, 'u16'],
                'DeviceSetpointRaw' => ['ctrl', 10071, 'i32'],
                'DeviceWatchdog'    => ['ctrl', 10080, 'u16'],
                'ModelName'         => ['ident', 10090, 'str10'],
                'Serial'            => ['ident', 10100, 'str12'],
                'Firmware'          => ['ident', 10112, 'str6'],
                'RatedPower'        => ['ident', 10124, 'i32'],
                'MaxActivePower'    => ['ident', 10126, 'i32'],
                'InternalTemp'      => ['temp', 10156, 'i16'],
                'Packs'             => ['batt', 10249, 'u16'],
                'BatteryVoltage'    => ['batt', 10253, 'u16'],
                'ChargeEnergy'      => ['batt', 10262, 'u32'],
                'DischargeEnergy'   => ['batt', 10264, 'u32']
            ]
        ]
    ];

    /**
     * Messwert-Variablen, die sich einzeln an- und abwählen lassen.
     * Ident => [Name, Typ (i/f/s/b), Darstellung, Position, Familien, Rohwert, Umrechnung, Standard]
     *  Darstellung: 'W', '%', 'V', '°C', 'kWh', 'count', 'text', 'bool', 'status', 'plant', 'mode'
     *  Umrechnung:  '' = unverändert, 'neg' = Vorzeichen drehen, 'abs' = Betrag, 'energy' = Zähler,
     *               'div10' = ein Zehntel, 'is1' = wahr bei 1
     *  Standard:    true / false oder 'pv' (aus bei Geräten ohne eigene PV-Eingänge)
     */
    public const CATALOG = [
        'Status'            => ['Status', 'i', 'status', 30, ['sb', 'x1'], 'BatteryStatus', '', true],
        'PlantStatus'       => ['Anlagenstatus', 'i', 'plant', 31, ['x1'], 'PlantStatus', '', true],
        'SOC'               => ['Ladezustand', 'i', '%', 32, ['sb', 'x1'], 'SOC', '', true],
        'SOH'               => ['Akku-Gesundheit', 'i', '%', 33, ['x1'], 'SOH', '', true],
        'BatteryPower'      => ['Batterieleistung', 'i', 'W', 34, ['sb', 'x1'], 'BatteryPowerRaw', 'neg', true],
        'PVPower'           => ['PV-Leistung', 'i', 'W', 35, ['sb', 'x1'], 'PVPower', '', 'pv'],
        'PVPowerThird'      => ['Fremd-PV-Leistung', 'i', 'W', 36, ['sb', 'x1'], 'PVPowerThird', '', true],
        'GridPower'         => ['Netzleistung (Gerät)', 'i', 'W', 37, ['sb', 'x1'], 'GridPower', '', true],
        'LoadPower'         => ['Hausverbrauch (Gerät)', 'i', 'W', 38, ['sb', 'x1'], 'LoadPower', '', true],
        'ACOutputPower'     => ['AC-Ausgangsleistung', 'i', 'W', 39, ['sb', 'x1'], 'ACOutputPower', '', true],
        'DeviceMode'        => ['Betriebsart am Gerät', 'i', 'mode', 40, ['sb', 'x1'], 'DeviceMode', '', true],
        'InternalTemp'      => ['Innentemperatur', 'f', '°C', 41, ['x1'], 'InternalTemp', 'div10', true],
        'BatteryVoltage'    => ['Batteriespannung', 'f', 'V', 42, ['x1'], 'BatteryVoltage', 'div10', false],

        'PVEnergy'          => ['PV-Ertrag gesamt', 'f', 'kWh', 50, ['sb', 'x1'], 'PVEnergy', 'energy', 'pv'],
        'ChargeEnergy'      => ['Geladen gesamt', 'f', 'kWh', 51, ['sb', 'x1'], 'ChargeEnergy', 'energy', true],
        'DischargeEnergy'   => ['Entladen gesamt', 'f', 'kWh', 52, ['sb', 'x1'], 'DischargeEnergy', 'energy', true],
        'LoadEnergy'        => ['Hausverbrauch gesamt', 'f', 'kWh', 53, ['x1'], 'LoadEnergy', 'energy', true],
        'GridImportEnergy'  => ['Netzbezug (Zähler Gerät)', 'f', 'kWh', 54, ['x1'], 'GridImportEnergy', 'energy', true],
        'GridExportEnergy'  => ['Einspeisung (Zähler Gerät)', 'f', 'kWh', 55, ['x1'], 'GridExportEnergy', 'energy', true],
        'RatedEnergy'       => ['Nennkapazität', 'f', 'kWh', 56, ['sb'], 'RatedEnergy', 'div10', true],
        'Packs'             => ['Anzahl Akkumodule', 'i', 'count', 57, ['x1'], 'Packs', '', true],

        'DeviceMaxCharge'   => ['Ladbare Leistung (Gerät)', 'i', 'W', 60, ['sb', 'x1'], 'Rechargeable', 'abs', true],
        'DeviceMaxDischarge'=> ['Entladbare Leistung (Gerät)', 'i', 'W', 61, ['sb', 'x1'], 'Dischargeable', 'abs', true],
        'RatedPower'        => ['Nennleistung', 'i', 'W', 62, ['x1'], 'RatedPower', 'abs', true],
        'MaxActivePower'    => ['Max. Wirkleistung', 'i', 'W', 63, ['x1'], 'MaxActivePower', 'abs', true],
        'ChargeLimitSOC'    => ['Ladegrenze (App)', 'i', '%', 64, ['sb'], 'ChargeLimitSOC', '', true],
        'DischargeLimitSOC' => ['Entladegrenze (App)', 'i', '%', 65, ['sb'], 'DischargeLimitSOC', '', true],
        'BackupReserveSOC'  => ['Notstrom-Reserve (App)', 'i', '%', 66, ['sb'], 'BackupReserveSOC', '', true],
        'BackupEnabled'     => ['Notstrom-Reserve aktiv', 'b', 'bool', 67, ['sb'], 'BackupEnabled', 'is1', true],
        'DeviceWatchdog'    => ['Geräte-Wächter', 'i', 's', 68, ['x1'], 'DeviceWatchdog', '', true],

        'Model'             => ['Modell', 's', 'text', 80, ['sb', 'x1'], '', '', true],
        'Serial'            => ['Seriennummer', 's', 'text', 81, ['sb', 'x1'], 'Serial', '', true],
        'Firmware'          => ['Firmware', 's', 'text', 82, ['sb', 'x1'], 'Firmware', '', true]
    ];

    /** Produktkürzel aus der Seriennummer der Solarbank-Familie => [Modellname, eigene PV-Eingänge] */
    public const PRODUCT_CODES = [
        'DMWH' => ['Anker SOLIX Solarbank Max AC', false],
        'DMXU' => ['Anker SOLIX Solarbank Max AC', false],
        'E25H' => ['Anker SOLIX Solarbank Max AC', false],
        'DNMS' => ['Anker SOLIX XE AC', false],
        'DPP4' => ['Anker SOLIX XE AC', false],
        'DNN3' => ['Anker SOLIX XE AC', false],
        'DMY6' => ['Anker SOLIX Solarbank Max', true],
        'DN9U' => ['Anker SOLIX Solarbank Max', true],
        'DPTK' => ['Anker SOLIX Solarbank Max', true],
        'DNMT' => ['Anker SOLIX XE', true],
        'DPP5' => ['Anker SOLIX XE', true],
        'DNN4' => ['Anker SOLIX XE', true],
        'DRE4' => ['Anker SOLIX XE', true],
        'E3DS' => ['Anker SOLIX XE', true],
        'DN7M' => ['Anker SOLIX Solarbank 4 E5000 Pro', true],
        'DPM4' => ['Anker SOLIX Solarbank 4 E5000 Pro', true]
    ];

    public static function Profile(string $family): array
    {
        return self::PROFILES[$family] ?? self::PROFILES[self::FAMILY_SB];
    }

    /** @return array{0:string,1:bool}|null [Modellname, eigene PV-Eingänge] */
    public static function ProductFromSerial(string $serial): ?array
    {
        $serial = trim($serial);
        $code = '';
        if (strlen($serial) === 16) {
            $code = substr($serial, 3, 3);
        } elseif (strlen($serial) === 17) {
            $code = substr($serial, 3, 4);
        }
        return self::PRODUCT_CODES[$code] ?? null;
    }
}
