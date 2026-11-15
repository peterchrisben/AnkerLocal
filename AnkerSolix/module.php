<?php

declare(strict_types=1);

/**
 * ANKERlocal – Anker-SOLIX-Speicher lokal über Modbus TCP (ohne Cloud).
 *
 * Autor: Peter Chrisben
 * Lizenz: CC BY-NC-SA 4.0
 *
 * Verbindung: Client Socket (IP-Adresse, Port 502) → ModBus Gateway (Geräte-ID 1) → diese Instanz.
 *
 * Vorzeichen im Modul: Leistungsvorgaben und Batterieleistung positiv = Laden, negativ = Entladen.
 * Netzleistung positiv = Bezug, negativ = Einspeisung.
 */

require_once __DIR__ . '/../libs/ANKModbus.php';
require_once __DIR__ . '/../libs/ANKRegisters.php';

class AnkerSolix extends IPSModule
{
    use ANKModbus;

    // Steuerung
    private const CONTROL_ANKER = 0;
    private const CONTROL_MANUAL = 1;

    // Regelung (wirkt nur bei Steuerung = Manuell)
    private const REG_PAUSE = 0;
    private const REG_CHARGE = 1;
    private const REG_DISCHARGE = 2;
    private const REG_GRID = 3;
    private const REG_EXTERN = 4;

    private const CONTROL_TEXT = [
        self::CONTROL_ANKER  => 'Anker',
        self::CONTROL_MANUAL => 'Manuell'
    ];

    private const REG_TEXT = [
        self::REG_PAUSE     => 'Pause',
        self::REG_CHARGE    => 'Laden',
        self::REG_DISCHARGE => 'Entladen',
        self::REG_GRID      => 'Netzregelung',
        self::REG_EXTERN    => 'Extern'
    ];

    /** Sekunden, in denen nach einem Wechsel der Betriebsart dem geschriebenen Wert geglaubt wird */
    private const MODE_GRACE = 15;
    /** Leistungsvorgabe spätestens nach so vielen Sekunden erneut senden */
    private const SETPOINT_REFRESH = 30;

    // ================================================================ Lebenszyklus

    public function Create()
    {
        parent::Create();

        // Verbindung über das ModBus Gateway von Symcon
        $this->ConnectParent('{A5F663AB-C400-4FE5-B207-4D67CC030564}');

        // Abfrage
        $this->RegisterPropertyInteger('Interval', 5);
        $this->RegisterPropertyInteger('SlowInterval', 60);
        $this->RegisterPropertyInteger('FailLimit', 3);

        // Steuerung
        $this->RegisterPropertyBoolean('AllowControl', false);
        $this->RegisterPropertyBoolean('StartPaused', true);
        $this->RegisterPropertyBoolean('FallbackToAnker', false);
        $this->RegisterPropertyInteger('ExternalTimeout', 60);
        $this->RegisterPropertyInteger('PowerRange', 3500);

        // Netzregelung
        $this->RegisterPropertyInteger('GridVariable', 0);
        $this->RegisterPropertyInteger('GridSign', 0);      // 0 = Bezug positiv, 1 = Bezug negativ
        $this->RegisterPropertyInteger('GridUnit', 0);      // 0 = W, 1 = kW
        $this->RegisterPropertyInteger('GridTimeout', 120);
        $this->RegisterPropertyInteger('TargetGrid', 0);
        $this->RegisterPropertyInteger('RegGain', 70);
        $this->RegisterPropertyInteger('Deadband', 20);
        $this->RegisterPropertyInteger('RegDirection', 0);  // 0 = beide, 1 = nur Laden, 2 = nur Entladen

        // Grenzen
        $this->RegisterPropertyInteger('MinChargePower', 100);
        $this->RegisterPropertyInteger('MinDischargePower', 100);
        $this->RegisterPropertyInteger('BelowMinMode', 0);  // 0 = nichts tun, 1 = auf Minimum aufrunden
        $this->RegisterPropertyInteger('MinSOC', 0);
        $this->RegisterPropertyInteger('MaxSOC', 100);

        $this->RegisterAttributeInteger('AnkerMode', 0);

        $this->RegisterTimer('Update', 0, 'ANK_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->MaintainVariables();
        $this->SyncReferences();

        // Regler neu beginnen lassen, wenn Einstellungen geändert wurden
        $this->SetBuffer('RegState', '');
        $this->SetBuffer('RegSeen', '');
        $this->SetBuffer('LastSlow', '');

        // Der Timer läuft immer: Sobald ein Gateway verbunden und aktiv ist, beginnt die Abfrage von selbst.
        $this->SetTimerInterval('Update', max(2, $this->ReadPropertyInteger('Interval')) * 1000);
        if (!$this->HasGateway()) {
            $this->SetStatus(104);
            return;
        }
        $this->DefaultPort();
    }

    /** Antworten kommen als Rückgabewert von SendDataToParent – hier ist nichts zu tun. */
    public function ReceiveData($JSONString)
    {
        return '';
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Control':
                $this->SetControl((int) $Value);
                break;
            case 'Regulation':
                $this->SetRegulation((int) $Value);
                break;
            case 'ChargePower':
                $this->SetChargePower((int) $Value);
                break;
            case 'DischargePower':
                $this->SetDischargePower((int) $Value);
                break;
            case 'ExternalSetpoint':
                $this->SetPower((int) $Value);
                break;
            case 'MaxChargePower':
                $this->SetMaxChargePower((int) $Value);
                break;
            case 'MaxDischargePower':
                $this->SetMaxDischargePower((int) $Value);
                break;
            case 'AnkerMode':
                $this->SetAnkerMode((int) $Value);
                break;
            default:
                throw new Exception('Ungültiger Ident: ' . $Ident);
        }
    }

    // ================================================================ Öffentliche Funktionen

    /** Gerät abfragen und – falls freigegeben und „Manuell“ – die Leistungsvorgabe nachführen. */
    public function Update(): bool
    {
        if (!$this->HasGateway()) {
            if ($this->GetStatus() !== 104) {
                $this->SetStatus(104);
            }
            return false;
        }
        $this->DefaultPort();
        return $this->Run(null);
    }

    /** Steuerung umschalten: 0 = Anker regelt selbst, 1 = Manuell (Fremdsteuerung). */
    public function SetControl(int $Mode): bool
    {
        $this->RequireControl();
        if (!isset(self::CONTROL_TEXT[$Mode])) {
            throw new Exception('Ungültige Steuerung: ' . $Mode);
        }
        $this->RequireGateway();
        return $this->Run(function () use ($Mode) {
            if ($Mode === self::CONTROL_MANUAL) {
                $this->WriteMode(ANKRegisters::MODE_THIRD_PARTY);
                if ($this->ReadPropertyBoolean('StartPaused')) {
                    $this->SetVal('Regulation', self::REG_PAUSE);
                }
                $this->ResetRegulator();
                $this->TryWriteSetpoint(0);
            } else {
                $this->TryWriteSetpoint(0);
                $this->WriteMode($this->ReadAttributeInteger('AnkerMode'));
                $this->SetBuffer('LastSent', '');
            }
            $this->SetVal('Control', $Mode);
        });
    }

    /** Regelung wählen: 0 Pause, 1 Laden, 2 Entladen, 3 Netzregelung, 4 Extern. Wirkt nur bei „Manuell“. */
    public function SetRegulation(int $Mode): bool
    {
        $this->RequireControl();
        if (!isset(self::REG_TEXT[$Mode])) {
            throw new Exception('Ungültige Regelung: ' . $Mode);
        }
        $this->SetVal('Regulation', $Mode);
        $this->ResetRegulator();
        return $this->RunIfPossible();
    }

    /** Ladeleistung für „Laden“ in Watt. */
    public function SetChargePower(int $Watt): bool
    {
        $this->RequireControl();
        $this->SetVal('ChargePower', $this->Clamp($Watt, $this->ReadPropertyInteger('MinChargePower'), $this->PowerRange()));
        return $this->RunIfPossible();
    }

    /** Entladeleistung für „Entladen“ in Watt. */
    public function SetDischargePower(int $Watt): bool
    {
        $this->RequireControl();
        $this->SetVal('DischargePower', $this->Clamp($Watt, $this->ReadPropertyInteger('MinDischargePower'), $this->PowerRange()));
        return $this->RunIfPossible();
    }

    /** Oberste Grenze fürs Laden in Watt – gilt für alle Regelungen unter „Manuell“. */
    public function SetMaxChargePower(int $Watt): bool
    {
        $this->RequireControl();
        $this->SetVal('MaxChargePower', $this->Clamp($Watt, 0, $this->PowerRange()));
        return $this->RunIfPossible();
    }

    /** Oberste Grenze fürs Entladen in Watt – gilt für alle Regelungen unter „Manuell“. */
    public function SetMaxDischargePower(int $Watt): bool
    {
        $this->RequireControl();
        $this->SetVal('MaxDischargePower', $this->Clamp($Watt, 0, $this->PowerRange()));
        return $this->RunIfPossible();
    }

    /**
     * Externe Vorgabe in Watt: positiv = Laden, negativ = Entladen, 0 = nichts.
     * Wirkt nur bei Steuerung „Manuell“ und Regelung „Extern“. Muss regelmäßig wiederholt
     * werden (Wächter). Rückgabe true, wenn die Vorgabe gerade wirksam ist.
     */
    public function SetPower(int $Watt): bool
    {
        $this->RequireControl();
        $range = $this->PowerRange();
        $this->SetVal('ExternalSetpoint', $this->Clamp($Watt, -$range, $range));
        $this->SetBuffer('ExtTime', (string) time());
        $active = $this->GetValue('Control') === self::CONTROL_MANUAL && $this->GetValue('Regulation') === self::REG_EXTERN;
        if ($active) {
            $this->RunIfPossible();
        }
        return $active;
    }

    /** Anker-Betriebsart, die bei Steuerung „Anker“ gilt (0, 1, 4, 6, 7 – siehe README). */
    public function SetAnkerMode(int $Mode): bool
    {
        $this->RequireControl();
        if (!isset(ANKRegisters::ANKER_MODES[$Mode])) {
            throw new Exception('Ungültige Anker-Betriebsart: ' . $Mode);
        }
        $this->WriteAttributeInteger('AnkerMode', $Mode);
        $this->SetVal('AnkerMode', $Mode);
        if ($this->GetValue('Control') !== self::CONTROL_ANKER || !$this->HasGateway()) {
            return true;
        }
        return $this->Run(function () use ($Mode) {
            $this->WriteMode($Mode);
        });
    }

    /** Kurzfassung aller wichtigen Werte als JSON (für Energiemanager und Skripte). */
    public function GetSummary(): string
    {
        $keys = ['Online', 'Control', 'Regulation', 'Setpoint', 'ExternalSetpoint', 'ChargePower', 'DischargePower',
            'MaxChargePower', 'MaxDischargePower', 'SOC', 'BatteryPower', 'PVPower', 'GridPower', 'LoadPower',
            'ACOutputPower', 'Status', 'DeviceMode', 'DeviceMaxCharge', 'DeviceMaxDischarge', 'RatedEnergy'];
        $summary = [];
        foreach ($keys as $key) {
            $summary[$key] = $this->GetValue($key);
        }
        $summary['AllowControl'] = $this->ReadPropertyBoolean('AllowControl');
        $summary['ControlText'] = self::CONTROL_TEXT[$summary['Control']] ?? '';
        $summary['RegulationText'] = self::REG_TEXT[$summary['Regulation']] ?? '';
        return json_encode($summary);
    }

    /** Formular-Knopf: Verbindung prüfen, Ergebnis ins Formular schreiben. */
    public function TestConnection(): bool
    {
        if (!$this->HasGateway()) {
            $this->UpdateFormField('Result', 'caption', 'Es ist noch kein ModBus Gateway verbunden (unten „Gateway ändern“).');
            return false;
        }
        $ok = $this->Run(null);
        if ($ok) {
            $text = 'Verbindung in Ordnung – ' . ($this->GetValue('Model') ?: 'Gerät') . ', Akku ' . $this->GetValue('SOC') . ' %, Betriebsart „'
                . (ANKRegisters::MODES[$this->GetValue('DeviceMode')] ?? $this->GetValue('DeviceMode')) . '“.';
        } else {
            $text = 'Keine Antwort: ' . $this->GetBuffer('LastError');
        }
        $this->UpdateFormField('Result', 'caption', $text);
        return $ok;
    }

    /**
     * Für Fachleute: Register roh lesen. $Type 3 = Holding, 4 = Input. Liefert JSON mit den Werten.
     */
    public function ReadRegisters(int $Type, int $Address, int $Quantity): string
    {
        $this->RequireGateway();
        if (!in_array($Type, [3, 4], true) || $Quantity < 1 || $Quantity > 100) {
            throw new Exception('Typ 3 oder 4, Anzahl 1 bis 100');
        }
        if (!IPS_SemaphoreEnter($this->SemaphoreKey(), 8000)) {
            throw new Exception('Gerät ist gerade beschäftigt');
        }
        try {
            return json_encode($this->MbRead($Type, $Address, $Quantity));
        } finally {
            IPS_SemaphoreLeave($this->SemaphoreKey());
        }
    }

    /**
     * Formular-Knopf: IP-Adresse und Port im Client Socket und die Geräte-ID im ModBus Gateway eintragen.
     * Ändert nur die eigene Verbindung und nur, wenn kein anderes Gerät dasselbe Gateway benutzt.
     */
    public function SetConnection(string $Host, int $Port, int $DeviceID): bool
    {
        $info = $this->ConnectionInfo();
        $message = '';
        $host = trim($Host);
        if ($info['gateway'] === 0) {
            $message = 'Es ist noch kein ModBus Gateway verbunden – zuerst unten „Gateway ändern“.';
        } elseif ($info['socket'] === 0) {
            $message = 'Am ModBus Gateway hängt noch kein Client Socket – bitte im Gateway eine Schnittstelle wählen.';
        } elseif ($info['shared']) {
            $message = 'Dieses Gateway wird auch von anderen Geräten benutzt. Bitte IP-Adresse und Port direkt im Client Socket ändern oder ein eigenes Gateway anlegen.';
        } elseif ($host === '' || $Port < 1 || $Port > 65535 || $DeviceID < 1 || $DeviceID > 247) {
            $message = 'Bitte IP-Adresse, Port (1–65535) und Geräte-ID (1–247) ausfüllen.';
        }
        if ($message !== '') {
            $this->UpdateFormField('ConnResult', 'caption', $message);
            return false;
        }
        IPS_SetProperty($info['socket'], 'Host', $host);
        IPS_SetProperty($info['socket'], 'Port', $Port);
        IPS_SetProperty($info['socket'], 'Open', true);
        IPS_ApplyChanges($info['socket']);
        if ($info['deviceKey'] !== '' && $info['device'] !== $DeviceID) {
            IPS_SetProperty($info['gateway'], $info['deviceKey'], $DeviceID);
            IPS_ApplyChanges($info['gateway']);
        }
        $this->SetBuffer('FailCount', '0');
        $text = 'Übernommen: ' . $host . ':' . $Port . ', Geräte-ID ' . $DeviceID . '.';
        if ($info['deviceKey'] === '') {
            $text .= ' Die Geräte-ID bitte direkt im ModBus Gateway eintragen.';
        }
        $this->UpdateFormField('ConnResult', 'caption', $text . ' Jetzt „Verbindung prüfen“.');
        return true;
    }

    // ================================================================ Formular

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $allow = $this->ReadPropertyBoolean('AllowControl');
        $info = $this->ConnectionInfo();
        $ready = $info['host'] !== '' && $info['port'] > 0;

        $fill = [
            'Overview'     => ['caption' => $this->OverviewText()],
            'PanelSetup'   => [
                'caption'  => $ready ? 'Einrichtung (' . $info['host'] . ':' . $info['port'] . ')' : 'Einrichtung',
                'expanded' => !$ready
            ],
            'ConnState'    => ['caption' => $this->ConnectionText($info)],
            'PanelConn'    => ['expanded' => !$ready, 'visible' => $info['gateway'] > 0],
            'ConnHost'     => ['value' => $info['host']],
            'ConnPort'     => ['value' => $info['port'] > 0 ? $info['port'] : 502],
            'ConnDevice'   => ['value' => $info['device'] >= 1 ? $info['device'] : 1],
            'PanelControl' => ['caption' => 'Steuerung (' . ($allow ? 'erlaubt' : 'nicht erlaubt – nur lesen') . ')'],
            'PanelGrid'    => ['caption' => 'Netzregelung (' . $this->GridCaption() . ')'],
            'GridInfo'     => ['caption' => $this->GridInfoText()]
        ];
        $this->FillForm($form['elements'], $fill);
        $this->FillForm($form['actions'], $fill);
        return json_encode($form);
    }

    private function FillForm(array &$items, array $fill): void
    {
        foreach ($items as &$item) {
            $name = $item['name'] ?? '';
            if ($name !== '' && isset($fill[$name])) {
                foreach ($fill[$name] as $key => $value) {
                    $item[$key] = $value;
                }
            }
            if (isset($item['items']) && is_array($item['items'])) {
                $this->FillForm($item['items'], $fill);
            }
        }
    }

    private function OverviewText(): string
    {
        $info = $this->ConnectionInfo();
        if ($info['gateway'] === 0) {
            return 'Noch nicht eingerichtet: Es fehlt das ModBus Gateway (unten „Gateway ändern“).';
        }
        if ($info['host'] === '' || $info['port'] <= 0) {
            return 'Noch nicht eingerichtet: In der Anker-App „Modbus TCP“ einschalten, dann unter „Verbindung einstellen“ die IP-Adresse eintragen.';
        }
        $last = (int) $this->GetBuffer('LastOK');
        if ($last === 0) {
            $error = $this->GetBuffer('LastError');
            return '○ Noch keine Antwort vom Gerät' . ($error !== '' ? '  ·  ' . $error : '');
        }
        $parts = [];
        $parts[] = $this->GetValue('Online') ? '● Verbunden' : '○ Nicht erreichbar';
        $model = (string) $this->GetValue('Model');
        if ($model !== '') {
            $parts[] = str_replace('Anker SOLIX ', '', $model);
        }
        $parts[] = 'Akku ' . $this->GetValue('SOC') . ' %';
        if ($this->GetValue('Control') === self::CONTROL_MANUAL) {
            $parts[] = 'Manuell: ' . (self::REG_TEXT[$this->GetValue('Regulation')] ?? '?');
            $parts[] = 'Soll ' . sprintf('%+d', $this->GetValue('Setpoint')) . ' W';
        } else {
            $parts[] = 'Anker regelt (' . (ANKRegisters::MODES[$this->GetValue('DeviceMode')] ?? '?') . ')';
        }
        $parts[] = 'letzte Abfrage ' . date('H:i:s', $last);
        return implode('  ·  ', $parts);
    }

    /**
     * Verbindungskette lesen: Gateway, Client Socket, IP-Adresse, Port, Geräte-ID.
     *
     * @return array{gateway:int,socket:int,host:string,port:int,open:bool,device:int,deviceKey:string,shared:bool,hasPort:bool}
     */
    private function ConnectionInfo(): array
    {
        $info = ['gateway' => 0, 'socket' => 0, 'host' => '', 'port' => 0, 'open' => false, 'device' => 1, 'deviceKey' => '', 'shared' => false, 'hasPort' => false];
        $gateway = (int) (IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($gateway <= 0 || !IPS_InstanceExists($gateway)) {
            return $info;
        }
        $info['gateway'] = $gateway;
        $config = json_decode((string) IPS_GetConfiguration($gateway), true) ?: [];
        foreach ($config as $key => $value) {
            if (strtolower((string) $key) === 'deviceid') {
                $info['deviceKey'] = (string) $key;
                $info['device'] = (int) $value;
            }
        }
        foreach (IPS_GetInstanceList() as $other) {
            if ($other !== $this->InstanceID && (int) (IPS_GetInstance($other)['ConnectionID'] ?? 0) === $gateway) {
                $info['shared'] = true;
            }
        }
        $socket = (int) (IPS_GetInstance($gateway)['ConnectionID'] ?? 0);
        if ($socket <= 0 || !IPS_InstanceExists($socket)) {
            return $info;
        }
        $info['socket'] = $socket;
        $config = json_decode((string) IPS_GetConfiguration($socket), true) ?: [];
        $info['host'] = trim((string) ($config['Host'] ?? ''));
        $info['hasPort'] = array_key_exists('Port', $config);
        $info['port'] = (int) ($config['Port'] ?? 0);
        $info['open'] = (bool) ($config['Open'] ?? false);
        return $info;
    }

    /**
     * Ein neu angelegter Client Socket hat Port 0 → Modbus-Standard 502 eintragen.
     * Nur dieser Leerwert, und nur, wenn kein anderes Gerät dasselbe Gateway benutzt (wie in SYMARSTEK).
     */
    private function DefaultPort(): void
    {
        $info = $this->ConnectionInfo();
        if ($info['socket'] === 0 || $info['shared'] || !$info['hasPort'] || $info['port'] !== 0) {
            return;
        }
        IPS_SetProperty($info['socket'], 'Port', 502);
        IPS_ApplyChanges($info['socket']);
        $this->SendDebug('Verbindung', 'Client Socket ohne Port → 502 eingetragen', 0);
    }

    private function ConnectionText(array $info): string
    {
        if ($info['gateway'] === 0) {
            return 'Aktuell: kein ModBus Gateway verbunden. Unten „Gateway ändern“ wählen – ein vorhandenes Gateway zum Speicher nehmen oder ein neues anlegen.';
        }
        if ($info['socket'] === 0) {
            return 'Aktuell: ModBus Gateway ohne Client Socket. Bitte im Gateway eine Schnittstelle wählen.';
        }
        if ($info['host'] === '') {
            return 'Aktuell: noch keine IP-Adresse eingetragen. Unten unter „Verbindung einstellen“ eintragen.';
        }
        $text = 'Aktuell: ' . $info['host'] . ':' . $info['port'] . '  ·  Geräte-ID ' . $info['device']
            . '  ·  ' . ($this->HasActiveParent() ? 'verbunden' : ($info['open'] ? 'nicht verbunden' : 'Client Socket ist nicht geöffnet'));
        if ($info['port'] <= 0) {
            $text .= '  ·  Port fehlt (Anker: 502)';
        }
        if ($info['shared']) {
            $text .= '  ·  Gateway wird auch von anderen Geräten benutzt';
        }
        return $text;
    }

    private function GridCaption(): string
    {
        $id = $this->ReadPropertyInteger('GridVariable');
        return ($id >= 10000 && IPS_VariableExists($id)) ? 'Netzleistung gewählt' : 'keine Netzleistung gewählt';
    }

    private function GridInfoText(): string
    {
        $id = $this->ReadPropertyInteger('GridVariable');
        if ($id < 10000 || !IPS_VariableExists($id)) {
            return 'Ohne Netzleistungs-Variable bleibt die Regelung „Netzregelung“ bei 0 W.';
        }
        $grid = $this->ReadGridPower($id);
        $age = time() - (int) IPS_GetVariable($id)['VariableUpdated'];
        return sprintf('Aktuell gelesen: %s%d W (%s), zuletzt aktualisiert vor %d s.',
            $grid >= 0 ? '+' : '−', abs($grid), $grid >= 0 ? 'Bezug' : 'Einspeisung', max(0, $age));
    }

    // ================================================================ Variablen

    private function MaintainVariables(): void
    {
        $allow = $this->ReadPropertyBoolean('AllowControl');
        $range = $this->PowerRange();
        $minCharge = min($range, max(0, $this->ReadPropertyInteger('MinChargePower')));
        $minDischarge = min($range, max(0, $this->ReadPropertyInteger('MinDischargePower')));

        // --- Bedienung
        $this->RegisterVariableInteger('Control', 'Steuerung', $this->PresChoice(self::CONTROL_TEXT, $allow), 10);
        $this->RegisterVariableInteger('Regulation', 'Regelung', $this->PresChoice(self::REG_TEXT, $allow), 11);
        $this->RegisterVariableInteger('ChargePower', 'Ladeleistung', $this->PresPower($minCharge, $range, $allow), 12);
        $this->RegisterVariableInteger('DischargePower', 'Entladeleistung', $this->PresPower($minDischarge, $range, $allow), 13);
        $this->RegisterVariableInteger('ExternalSetpoint', 'Externe Vorgabe', $this->PresPower(-$range, $range, $allow), 14);
        $this->RegisterVariableInteger('MaxChargePower', 'Max. Ladeleistung', $this->PresPower(0, $range, $allow), 15);
        $this->RegisterVariableInteger('MaxDischargePower', 'Max. Entladeleistung', $this->PresPower(0, $range, $allow), 16);
        $this->RegisterVariableInteger('AnkerMode', 'Anker-Betriebsart', $this->PresChoice(ANKRegisters::ANKER_MODES, $allow), 17);
        $this->RegisterVariableInteger('Setpoint', 'Sollleistung am Gerät', $this->PresValue(' W'), 18);
        $this->RegisterVariableString('ControlInfo', 'Regelung – Hinweis', '', 19);

        foreach (['Control', 'Regulation', 'ChargePower', 'DischargePower', 'ExternalSetpoint', 'MaxChargePower', 'MaxDischargePower', 'AnkerMode'] as $ident) {
            if ($allow) {
                $this->EnableAction($ident);
            } else {
                $this->DisableAction($ident);
            }
        }

        // --- Messwerte
        $this->RegisterVariableInteger('Status', 'Status', $this->PresText(ANKRegisters::BATTERY_STATUS), 30);
        $this->RegisterVariableInteger('SOC', 'Ladezustand', $this->PresValue(' %'), 31);
        $this->RegisterVariableInteger('BatteryPower', 'Batterieleistung', $this->PresValue(' W'), 32);
        $this->RegisterVariableInteger('PVPower', 'PV-Leistung', $this->PresValue(' W'), 33);
        $this->RegisterVariableInteger('GridPower', 'Netzleistung (Gerät)', $this->PresValue(' W'), 34);
        $this->RegisterVariableInteger('LoadPower', 'Hausverbrauch (Gerät)', $this->PresValue(' W'), 35);
        $this->RegisterVariableInteger('ACOutputPower', 'AC-Ausgangsleistung', $this->PresValue(' W'), 36);
        $this->RegisterVariableInteger('DeviceMode', 'Betriebsart am Gerät', $this->PresText(ANKRegisters::MODES), 37);

        // --- Energie
        $this->RegisterVariableFloat('PVEnergy', 'PV-Ertrag gesamt', $this->PresValue(' kWh', 1), 50);
        $this->RegisterVariableFloat('ChargeEnergy', 'Geladen gesamt', $this->PresValue(' kWh', 1), 51);
        $this->RegisterVariableFloat('DischargeEnergy', 'Entladen gesamt', $this->PresValue(' kWh', 1), 52);
        $this->RegisterVariableFloat('RatedEnergy', 'Nennkapazität', $this->PresValue(' kWh', 1), 53);

        // --- Grenzen am Gerät (aus der Anker-App)
        $this->RegisterVariableInteger('DeviceMaxCharge', 'Gerätegrenze Laden', $this->PresValue(' W'), 60);
        $this->RegisterVariableInteger('DeviceMaxDischarge', 'Gerätegrenze Entladen', $this->PresValue(' W'), 61);
        $this->RegisterVariableInteger('ChargeLimitSOC', 'Ladegrenze (App)', $this->PresValue(' %'), 62);
        $this->RegisterVariableInteger('DischargeLimitSOC', 'Entladegrenze (App)', $this->PresValue(' %'), 63);
        $this->RegisterVariableInteger('BackupReserveSOC', 'Notstrom-Reserve (App)', $this->PresValue(' %'), 64);
        $this->RegisterVariableBoolean('BackupEnabled', 'Notstrom-Reserve aktiv', $this->PresBool('Nein', 'Ja'), 65);

        // --- Gerät
        $this->RegisterVariableString('Model', 'Modell', '', 80);
        $this->RegisterVariableString('Serial', 'Seriennummer', '', 81);
        $this->RegisterVariableString('Firmware', 'Firmware', '', 82);
        $this->RegisterVariableBoolean('Online', 'Erreichbar', $this->PresBool('Nein', 'Ja'), 83);

        // Startwerte nur beim allerersten Mal
        $this->InitValue('MaxChargePower', $range);
        $this->InitValue('MaxDischargePower', $range);
        $this->InitValue('ChargePower', $minCharge);
        $this->InitValue('DischargePower', $minDischarge);
        $this->InitValue('AnkerMode', $this->ReadAttributeInteger('AnkerMode'));
    }

    private function InitValue(string $ident, int $value): void
    {
        $id = $this->GetIDForIdent($ident);
        if ((int) IPS_GetVariable($id)['VariableUpdated'] === 0) {
            $this->SetValue($ident, $value);
        }
    }

    /** Nur schreiben, wenn sich der Wert ändert – oder beim allerersten Mal. */
    private function SetVal(string $ident, $value): void
    {
        $id = $this->GetIDForIdent($ident);
        if ($this->GetValue($ident) !== $value || (int) IPS_GetVariable($id)['VariableUpdated'] === 0) {
            $this->SetValue($ident, $value);
        }
    }

    private function SyncReferences(): void
    {
        $wanted = [];
        $grid = $this->ReadPropertyInteger('GridVariable');
        if ($grid >= 10000) {
            $wanted[] = $grid;
        }
        foreach ($this->GetReferenceList() as $id) {
            if (!in_array($id, $wanted, true)) {
                $this->UnregisterReference($id);
            }
        }
        foreach ($wanted as $id) {
            $this->RegisterReference($id);
        }
    }

    // ---------------------------------------------------------------- Darstellungen

    private function PresValue(string $suffix, int $digits = 0): array
    {
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX'       => $suffix,
            'DIGITS'       => $digits
        ];
    }

    /** Auswahl: mit Aktion Aufzählung, ohne Aktion Klartext über Intervalle. */
    private function PresChoice(array $texts, bool $action): array
    {
        if (!$action) {
            return $this->PresText($texts);
        }
        $options = [];
        foreach ($texts as $value => $text) {
            $options[] = [
                'Value' => $value, 'Caption' => $text,
                'IconActive' => false, 'IconValue' => '',
                'Color' => -1,
                'ColorActive' => false, 'ColorValue' => -1,
                'ContentColorActive' => false, 'ContentColorValue' => -1
            ];
        }
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => json_encode($options)
        ];
    }

    /** Integer-Status als Klartext (Wertanzeige mit Intervallen, Min = Max). */
    private function PresText(array $texts): array
    {
        $intervals = [];
        foreach ($texts as $value => $text) {
            $intervals[] = [
                'IntervalMinValue' => $value, 'IntervalMaxValue' => $value,
                'ConstantActive' => true, 'ConstantValue' => $text,
                'ConversionFactor' => 1,
                'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '',
                'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => false, 'IconValue' => '',
                'ColorActive' => false, 'ColorValue' => -1,
                'ContentColorActive' => false, 'ContentColorValue' => -1
            ];
        }
        return [
            'PRESENTATION'     => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'INTERVALS_ACTIVE' => true,
            'INTERVALS'        => json_encode($intervals)
        ];
    }

    private function PresPower(int $min, int $max, bool $action): array
    {
        if (!$action) {
            return $this->PresValue(' W');
        }
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'MIN'          => $min,
            'MAX'          => max($max, $min),
            'STEP_SIZE'    => 50,
            'SUFFIX'       => ' W',
            'DIGITS'       => 0,
            'USAGE_TYPE'   => 5
        ];
    }

    private function PresBool(string $false, string $true): array
    {
        $options = [];
        foreach ([[false, $false], [true, $true]] as [$value, $text]) {
            $options[] = [
                'Value' => $value, 'Caption' => $text,
                'IconActive' => false, 'IconValue' => '',
                'ColorActive' => false, 'ColorValue' => -1,
                'ContentColorActive' => false, 'ContentColorValue' => -1
            ];
        }
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'OPTIONS'      => json_encode($options)
        ];
    }

    // ================================================================ Abfragerunde

    /**
     * Eine Runde: verbinden, optional zuerst schreiben ($before), lesen, Variablen füllen, regeln.
     * Fehler aus $before werden weitergereicht (Rückmeldung an den Aufrufer), sonst false.
     */
    private function Run(?callable $before): bool
    {
        if (!IPS_SemaphoreEnter($this->SemaphoreKey(), 8000)) {
            if ($before !== null) {
                throw new Exception('Gerät ist gerade beschäftigt – bitte noch einmal versuchen.');
            }
            return false;
        }
        try {
            // Vor dem Senden prüfen, ob die Verbindung überhaupt steht
            if (!$this->HasActiveParent()) {
                throw new Exception('Keine Verbindung zum Speicher (Client Socket oder ModBus Gateway ist nicht aktiv)');
            }
            if ($before !== null) {
                $before();
            }
            $data = $this->ReadDevice();
            $this->Publish($data);
            $this->ControlStep($data);
            $this->NoteSuccess();
            return true;
        } catch (Exception $e) {
            $this->NoteFailure($e->getMessage());
            if ($before !== null) {
                throw $e;
            }
            return false;
        } finally {
            IPS_SemaphoreLeave($this->SemaphoreKey());
        }
    }

    private function RunIfPossible(): bool
    {
        if (!$this->HasGateway()) {
            return false;
        }
        return $this->Run(null);
    }

    private function HasGateway(): bool
    {
        return (int) (IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0) > 0;
    }

    private function NoteSuccess(): void
    {
        $this->SetBuffer('FailCount', '0');
        $this->SetBuffer('LastOK', (string) time());
        $this->SetBuffer('LastError', '');
        $this->SetVal('Online', true);
        if ($this->GetStatus() !== 102) {
            $this->SetStatus(102);
            $this->LogMessage('Gerät wieder erreichbar', KL_MESSAGE);
        }
    }

    private function NoteFailure(string $message): void
    {
        $count = (int) $this->GetBuffer('FailCount') + 1;
        $this->SetBuffer('FailCount', (string) $count);
        $this->SetBuffer('LastError', $message);
        $this->SendDebug('Fehler', $message . ' (' . $count . ' in Folge)', 0);
        if ($count >= max(1, $this->ReadPropertyInteger('FailLimit'))) {
            $this->SetVal('Online', false);
            if ($this->GetStatus() !== 201) {
                $this->SetStatus(201);
                $this->LogMessage('Gerät nicht erreichbar: ' . $message, KL_WARNING);
            }
        }
    }

    /** @return array<string, mixed> Ident aus ANKRegisters::VALUES => Wert */
    private function ReadDevice(): array
    {
        $slowDue = (time() - (int) $this->GetBuffer('LastSlow')) >= max(10, $this->ReadPropertyInteger('SlowInterval'));
        $blocks = [];

        foreach (ANKRegisters::BLOCKS as $name => [$type, $start, $count, $required]) {
            if ($required) {
                $blocks[$name] = $this->MbRead($type, $start, $count);
            }
        }
        if ($slowDue) {
            foreach (ANKRegisters::BLOCKS as $name => [$type, $start, $count, $required]) {
                if ($required) {
                    continue;
                }
                try {
                    $blocks[$name] = $this->MbRead($type, $start, $count);
                } catch (Exception $e) {
                    // Zusatzwerte dürfen fehlen (ältere Firmware) – kein Fehlerstatus
                    $this->SendDebug('Zusatzblock ' . $name, $e->getMessage(), 0);
                }
            }
            $this->SetBuffer('LastSlow', (string) time());
        }

        $data = [];
        foreach (ANKRegisters::VALUES as $ident => [$block, $register, $type, $divisor]) {
            if (!isset($blocks[$block])) {
                continue;
            }
            $offset = $register - ANKRegisters::BLOCKS[$block][1];
            $words = $blocks[$block];
            if ($type === 'u16') {
                $value = $words[$offset] ?? 0;
            } elseif ($type === 'i32') {
                $value = self::MbInt32($words, $offset);
            } elseif ($type === 'u32') {
                $value = self::MbUInt32($words, $offset);
            } else {
                $value = self::MbString($words, $offset, (int) substr($type, 3));
            }
            if ($divisor !== 1 && !is_string($value)) {
                $value = $value / $divisor;
            }
            $data[$ident] = $value;
        }
        return $data;
    }

    private function Publish(array $data): void
    {
        $this->SetVal('Status', (int) $data['BatteryStatus']);
        $this->SetVal('SOC', (int) $data['SOC']);
        $this->SetVal('BatteryPower', -(int) $data['BatteryPowerRaw']);
        $this->SetVal('PVPower', (int) $data['PVPowerOwn'] + (int) $data['PVPowerThird']);
        $this->SetVal('GridPower', (int) $data['GridPower']);
        $this->SetVal('LoadPower', (int) $data['LoadPower']);
        $this->SetVal('ACOutputPower', (int) $data['ACOutputPower']);
        $this->SetVal('DeviceMode', (int) $data['DeviceMode']);
        $this->SetVal('PVEnergy', (float) $data['PVEnergy']);
        $this->SetVal('ChargeEnergy', (float) $data['ChargeEnergy']);
        $this->SetVal('DischargeEnergy', (float) $data['DischargeEnergy']);
        $this->SetVal('RatedEnergy', (float) $data['RatedEnergy']);
        $this->SetVal('DeviceMaxCharge', abs((int) $data['DeviceMaxCharge']));
        $this->SetVal('DeviceMaxDischarge', abs((int) $data['DeviceMaxDischarge']));

        if (isset($data['Serial'])) {
            $this->SetVal('Serial', (string) $data['Serial']);
            $this->SetVal('Firmware', (string) $data['Firmware']);
        }
        if (isset($data['Serial']) || isset($data['ModelCode'])) {
            $model = ANKRegisters::ModelFromSerial((string) ($data['Serial'] ?? $this->GetValue('Serial')));
            if ($model === '') {
                $model = (string) ($data['ModelCode'] ?? '');
            }
            if ($model !== '') {
                $this->SetVal('Model', $model);
            }
        }
        if (isset($data['ChargeLimitSOC'])) {
            $this->SetVal('ChargeLimitSOC', (int) $data['ChargeLimitSOC']);
            $this->SetVal('DischargeLimitSOC', (int) $data['DischargeLimitSOC']);
            $this->SetVal('BackupReserveSOC', (int) $data['BackupReserveSOC']);
            $this->SetVal('BackupEnabled', (int) $data['BackupEnabled'] === 1);
        }
    }

    // ================================================================ Steuerung

    private function ControlStep(array $data): void
    {
        $allow = $this->ReadPropertyBoolean('AllowControl');
        $mode = (int) $data['DeviceMode'];

        // Kurz nach einem eigenen Wechsel der Betriebsart dem geschriebenen Wert glauben
        // (das Gerät meldet die neue Betriebsart teils verzögert).
        $inGrace = (time() - (int) $this->GetBuffer('ModeWriteTime')) < self::MODE_GRACE;
        if ($inGrace) {
            $mode = (int) $this->GetBuffer('ModeWritten');
        }
        $manual = ($mode === ANKRegisters::MODE_THIRD_PARTY);

        if (!$manual && isset(ANKRegisters::ANKER_MODES[$mode])) {
            if ($this->ReadAttributeInteger('AnkerMode') !== $mode) {
                $this->WriteAttributeInteger('AnkerMode', $mode);
            }
            $this->SetVal('AnkerMode', $mode);
        }

        // In der App oder von außen auf Fremdsteuerung gestellt
        if ($manual && $this->GetValue('Control') !== self::CONTROL_MANUAL) {
            if ($allow && $this->ReadPropertyBoolean('StartPaused')) {
                $this->SetVal('Regulation', self::REG_PAUSE);
            }
            $this->ResetRegulator();
        }
        $this->SetVal('Control', $manual ? self::CONTROL_MANUAL : self::CONTROL_ANKER);

        if (!$manual) {
            $this->SetBuffer('LastSent', '');
            $this->SetVal('Setpoint', 0);
            $this->SetVal('ControlInfo', 'Anker regelt selbst (' . (ANKRegisters::MODES[$mode] ?? ('Betriebsart ' . $mode)) . ').');
            return;
        }
        if (!$allow) {
            $this->SetVal('Setpoint', -(int) $data['DeviceSetpointRaw']);
            $this->SetVal('ControlInfo', 'Gerät steht auf Fremdsteuerung, die Steuerung ist im Modul aber nicht erlaubt.');
            return;
        }

        [$target, $info, $expired] = $this->ComputeTarget($data);

        if ($expired && $this->ReadPropertyBoolean('FallbackToAnker')) {
            $this->TryWriteSetpoint(0);
            $this->WriteMode($this->ReadAttributeInteger('AnkerMode'));
            $this->SetBuffer('LastSent', '');
            $this->SetVal('Control', self::CONTROL_ANKER);
            $this->SetVal('Setpoint', 0);
            $this->SetVal('ControlInfo', $info . ' Zurück auf „Anker“ gestellt.');
            $this->LogMessage($info . ' Zurück auf „Anker“ gestellt.', KL_WARNING);
            return;
        }

        $lastSent = $this->GetBuffer('LastSent');
        $age = time() - (int) $this->GetBuffer('LastWrite');
        if ($lastSent === '' || (int) $lastSent !== $target || $age >= self::SETPOINT_REFRESH) {
            $error = $this->TryWriteSetpoint($target);
            if ($error !== '') {
                $info = 'Gerät nimmt die Leistungsvorgabe nicht an: ' . $error;
                if (!$inGrace && $this->GetBuffer('SetpointRefused') !== '1') {
                    $this->SetBuffer('SetpointRefused', '1');
                    $this->LogMessage($info, KL_WARNING);
                }
            } else {
                $this->SetBuffer('SetpointRefused', '');
            }
        }
        $this->SetVal('Setpoint', $target);
        $this->SetVal('ControlInfo', $info);

        $readBack = -(int) $data['DeviceSetpointRaw'];
        if ($lastSent !== '' && $readBack !== (int) $lastSent) {
            $this->SendDebug('Rücklesen 10071', 'Gerät meldet ' . $readBack . ' W, gesendet waren ' . $lastSent . ' W', 0);
        }
    }

    /**
     * Sollleistung bestimmen (positiv = Laden).
     *
     * @return array{0:int,1:string,2:bool} Leistung in W, Hinweistext, Wächter abgelaufen
     */
    private function ComputeTarget(array $data): array
    {
        $regulation = (int) $this->GetValue('Regulation');
        $raw = 0;
        $info = '';
        $expired = false;
        $automatic = false;

        switch ($regulation) {
            case self::REG_CHARGE:
                $raw = max(0, (int) $this->GetValue('ChargePower'));
                $info = 'Laden von Hand';
                break;
            case self::REG_DISCHARGE:
                $raw = -max(0, (int) $this->GetValue('DischargePower'));
                $info = 'Entladen von Hand';
                break;
            case self::REG_GRID:
                [$raw, $info, $expired] = $this->GridRegulator();
                $automatic = true;
                break;
            case self::REG_EXTERN:
                [$raw, $info, $expired] = $this->ExternalTarget();
                $automatic = true;
                break;
            default:
                $info = 'Pause';
        }

        $notes = [];

        // Oberste Grenzen: eigene Variablen und Gerätegrenze, der kleinere Wert zählt
        $maxCharge = min($this->PowerRange(), max(0, (int) $this->GetValue('MaxChargePower')));
        $maxDischarge = min($this->PowerRange(), max(0, (int) $this->GetValue('MaxDischargePower')));
        $deviceCharge = abs((int) $data['DeviceMaxCharge']);
        $deviceDischarge = abs((int) $data['DeviceMaxDischarge']);
        if ($deviceCharge > 0) {
            $maxCharge = min($maxCharge, $deviceCharge);
        }
        if ($deviceDischarge > 0) {
            $maxDischarge = min($maxDischarge, $deviceDischarge);
        }
        if ($raw > $maxCharge) {
            $raw = $maxCharge;
            $notes[] = 'begrenzt auf max. Ladeleistung';
        } elseif ($raw < -$maxDischarge) {
            $raw = -$maxDischarge;
            $notes[] = 'begrenzt auf max. Entladeleistung';
        }

        // Ladezustand
        $soc = (int) $data['SOC'];
        $maxSOC = $this->ReadPropertyInteger('MaxSOC');
        $minSOC = $this->ReadPropertyInteger('MinSOC');
        if ($raw > 0 && $maxSOC < 100 && $soc >= $maxSOC) {
            $raw = 0;
            $notes[] = 'Laden gesperrt, Akku bei ' . $soc . ' %';
        } elseif ($raw < 0 && $minSOC > 0 && $soc <= $minSOC) {
            $raw = 0;
            $notes[] = 'Entladen gesperrt, Akku bei ' . $soc . ' %';
        }

        // Mindestleistung – nur bei Netzregelung und Extern (von Hand beginnt der Regler am Minimum)
        if ($automatic && $raw !== 0) {
            $min = $raw > 0 ? $this->ReadPropertyInteger('MinChargePower') : $this->ReadPropertyInteger('MinDischargePower');
            $limit = $raw > 0 ? $maxCharge : $maxDischarge;
            if (abs($raw) < $min) {
                if ($this->ReadPropertyInteger('BelowMinMode') === 1 && $min <= $limit) {
                    $raw = $raw > 0 ? $min : -$min;
                    $notes[] = 'auf Mindestleistung aufgerundet';
                } else {
                    $raw = 0;
                    $notes[] = 'unter Mindestleistung';
                }
            }
        }

        if ($regulation === self::REG_GRID) {
            $this->SetBuffer('RegState', (string) $raw);
        }

        if ($raw > 0) {
            $info .= ' → Laden ' . $raw . ' W';
        } elseif ($raw < 0) {
            $info .= ' → Entladen ' . abs($raw) . ' W';
        } else {
            $info .= ' → 0 W';
        }
        if ($notes) {
            $info .= ' (' . implode(', ', $notes) . ')';
        }
        return [$raw, $info, $expired];
    }

    /** @return array{0:int,1:string,2:bool} */
    private function ExternalTarget(): array
    {
        $timeout = $this->ReadPropertyInteger('ExternalTimeout');
        $age = time() - (int) $this->GetBuffer('ExtTime');
        if ($timeout > 0 && $age > $timeout) {
            return [0, 'Extern: seit über ' . $timeout . ' s keine neue Vorgabe', true];
        }
        return [(int) $this->GetValue('ExternalSetpoint'), 'Extern', false];
    }

    /**
     * Eingebauter Regler: führt die Leistung so nach, dass die Netzleistung das Ziel erreicht.
     *
     * @return array{0:int,1:string,2:bool}
     */
    private function GridRegulator(): array
    {
        $id = $this->ReadPropertyInteger('GridVariable');
        if ($id < 10000 || !IPS_VariableExists($id)) {
            return [0, 'Netzregelung: keine Netzleistungs-Variable gewählt', false];
        }
        $updated = (int) IPS_GetVariable($id)['VariableUpdated'];
        $timeout = $this->ReadPropertyInteger('GridTimeout');
        if ($timeout > 0 && (time() - $updated) > $timeout) {
            $this->SetBuffer('RegState', '');
            return [0, 'Netzregelung: Netzleistung seit über ' . $timeout . ' s nicht aktualisiert', true];
        }

        $grid = $this->ReadGridPower($id);
        $targetGrid = $this->ReadPropertyInteger('TargetGrid');
        $previous = (int) $this->GetBuffer('RegState');
        $new = $previous;
        $text = sprintf('Netzregelung: Netz %+d W, Ziel %d W', $grid, $targetGrid);

        // Nur auf einen neuen Messwert reagieren, sonst wird derselbe Wert doppelt ausgeregelt
        if ($this->GetBuffer('RegSeen') !== (string) $updated) {
            $this->SetBuffer('RegSeen', (string) $updated);
            $error = $grid - $targetGrid;   // positiv = zu viel Bezug → weniger laden / mehr entladen
            if (abs($error) >= max(0, $this->ReadPropertyInteger('Deadband'))) {
                $gain = $previous === 0 ? 1.0 : max(10, min(100, $this->ReadPropertyInteger('RegGain'))) / 100;
                $new = (int) (round(($previous - $error * $gain) / 10) * 10);
            }
        }

        $direction = $this->ReadPropertyInteger('RegDirection');
        if ($direction === 1) {
            $new = max(0, $new);
        } elseif ($direction === 2) {
            $new = min(0, $new);
        }
        return [$new, $text, false];
    }

    /** Netzleistung in Watt, positiv = Bezug. */
    private function ReadGridPower(int $variableID): int
    {
        $value = (float) GetValue($variableID);
        if ($this->ReadPropertyInteger('GridUnit') === 1) {
            $value *= 1000;
        }
        if ($this->ReadPropertyInteger('GridSign') === 1) {
            $value = -$value;
        }
        return (int) round($value);
    }

    private function ResetRegulator(): void
    {
        $this->SetBuffer('RegState', '');
        $this->SetBuffer('RegSeen', '');
    }

    // ---------------------------------------------------------------- Schreiben

    /** Betriebsart schreiben und zurücklesen. Abweichung wird gemeldet, nicht als Fehler gewertet. */
    private function WriteMode(int $mode): void
    {
        $this->SendDebug('Schreiben 10064', 'Betriebsart ' . $mode . ' (' . (ANKRegisters::MODES[$mode] ?? '?') . ')', 0);
        $this->MbWriteSingle(ANKRegisters::REG_MODE, $mode);
        $this->SetBuffer('ModeWritten', (string) $mode);
        $this->SetBuffer('ModeWriteTime', (string) time());
        $back = $this->MbRead(ANKRegisters::HOLDING, ANKRegisters::REG_MODE, 1)[0];
        if ($back !== $mode) {
            $this->SendDebug('Rücklesen 10064', 'Gerät meldet noch ' . $back . ' – wird in den nächsten Sekunden erneut geprüft', 0);
        }
    }

    /** Leistungsvorgabe schreiben (positiv = Laden). Liefert '' oder den Fehlertext. */
    private function TryWriteSetpoint(int $watt): string
    {
        try {
            $this->SendDebug('Schreiben 10071', sprintf('%+d W (ans Gerät: %d)', $watt, -$watt), 0);
            $this->MbWriteMultiple(ANKRegisters::REG_SETPOINT, self::MbFromInt32(-$watt));
            $this->SetBuffer('LastSent', (string) $watt);
            $this->SetBuffer('LastWrite', (string) time());
            return '';
        } catch (Exception $e) {
            // Kein Abbruch der Runde: Eine gestörte Verbindung fällt beim nächsten Lesen ohnehin auf.
            $this->SendDebug('Schreiben 10071', 'nicht angenommen: ' . $e->getMessage(), 0);
            return $e->getMessage();
        }
    }

    // ---------------------------------------------------------------- Helfer

    private function RequireControl(): void
    {
        if (!$this->ReadPropertyBoolean('AllowControl')) {
            throw new Exception('Die Steuerung ist nicht erlaubt. Bitte in der Instanz unter „Steuerung“ das Häkchen „Steuerung erlauben“ setzen.');
        }
    }

    private function RequireGateway(): void
    {
        if (!$this->HasGateway()) {
            throw new Exception('Es ist noch kein ModBus Gateway verbunden.');
        }
    }

    private function PowerRange(): int
    {
        return max(100, $this->ReadPropertyInteger('PowerRange'));
    }

    private function Clamp(int $value, int $min, int $max): int
    {
        return max($min, min(max($min, $max), $value));
    }

    private function SemaphoreKey(): string
    {
        return 'ANKERlocal_' . $this->InstanceID;
    }
}
