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

        // Gerät und Werte
        $this->RegisterPropertyInteger('DeviceFamily', 0);  // 0 = automatisch, 1 = Solarbank / XE, 2 = X1
        $this->RegisterPropertyString('ValueSelection', '[]');
        $this->RegisterPropertyInteger('X1Watchdog', 0);    // Sekunden, 0 = Einstellung des Geräts nicht ändern

        $this->RegisterAttributeInteger('AnkerMode', 0);
        $this->RegisterAttributeString('Family', '');       // erkannte Gerätefamilie ('' = noch unbekannt)
        $this->RegisterAttributeBoolean('FamilyForced', false);
        $this->RegisterAttributeInteger('HasPV', -1);       // eigene PV-Eingänge: -1 unbekannt, 0 nein, 1 ja
        $this->RegisterAttributeInteger('LimitCharge', 0);  // Höchstleistung laut Typenschild (nur X1), 0 = unbekannt
        $this->RegisterAttributeInteger('LimitDischarge', 0);

        $this->RegisterTimer('Update', 0, 'ANK_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        // Fest eingestellte Gerätefamilie sofort übernehmen
        $forced = [1 => ANKRegisters::FAMILY_SB, 2 => ANKRegisters::FAMILY_X1][$this->ReadPropertyInteger('DeviceFamily')] ?? '';
        if ($forced !== '') {
            if ($this->ReadAttributeString('Family') !== $forced) {
                $this->WriteAttributeString('Family', $forced);
                $this->WriteAttributeInteger('HasPV', -1);
                $this->SetBuffer('LastModel', '');
            }
            $this->WriteAttributeBoolean('FamilyForced', true);
        } elseif ($this->ReadAttributeBoolean('FamilyForced')) {
            // zurück auf „automatisch“: neu erkennen lassen
            $this->WriteAttributeBoolean('FamilyForced', false);
            $this->WriteAttributeString('Family', '');
            $this->WriteAttributeInteger('HasPV', -1);
            $this->SetBuffer('LastModel', '');
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
        if (!isset($this->Profile()['ankerModes'][$Mode])) {
            throw new Exception('Ungültige Anker-Betriebsart für dieses Gerät: ' . $Mode);
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
            'MaxChargePower', 'MaxDischargePower', 'SOC', 'BatteryPower', 'PVPower', 'PVPowerThird', 'GridPower', 'LoadPower',
            'ACOutputPower', 'Status', 'DeviceMode', 'Model'];
        $summary = [];
        foreach ($keys as $key) {
            $summary[$key] = $this->Val($key);   // null = Variable ist abgewählt
        }
        $summary['Family'] = $this->ReadAttributeString('Family');
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
            $text = 'Verbindung in Ordnung – ' . $this->DeviceLabel() . ', Akku ' . $this->GetBuffer('LastSOC') . ' %, Betriebsart „'
                . $this->ModeText((int) $this->GetBuffer('LastMode')) . '“.';
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

    /** Formular-Knöpfe „Alle anhaken“ / „Alle Häkchen entfernen“ – wirksam erst nach „Änderungen übernehmen“. */
    public function SelectAllValues(bool $Show): bool
    {
        $rows = $this->SelectionRows();
        foreach ($rows as &$row) {
            $row['Show'] = $Show;
        }
        unset($row);
        $this->UpdateFormField('ValueSelection', 'values', json_encode($rows));
        return true;
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
            'FamilyInfo'   => ['caption' => $this->FamilyText()],
            'X1Watchdog'   => ['visible' => $this->EffFamily() === ANKRegisters::FAMILY_X1],
            'X1WatchdogInfo' => ['visible' => $this->EffFamily() === ANKRegisters::FAMILY_X1],
            'PanelValues'  => ['caption' => $this->ValuesCaption()],
            'ValueSelection' => ['values' => $this->SelectionRows()],
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
        $parts[] = str_replace('Anker SOLIX ', '', $this->DeviceLabel());
        $parts[] = 'Akku ' . $this->GetBuffer('LastSOC') . ' %';
        if ($this->GetValue('Control') === self::CONTROL_MANUAL) {
            $parts[] = 'Manuell: ' . (self::REG_TEXT[$this->GetValue('Regulation')] ?? '?');
            $parts[] = 'Soll ' . sprintf('%+d', $this->GetValue('Setpoint')) . ' W';
        } else {
            $parts[] = 'Anker regelt (' . $this->ModeText((int) $this->GetBuffer('LastMode')) . ')';
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
        $family = $this->EffFamily();
        $profile = $this->Profile();

        // --- Bedienung (immer vorhanden)
        $this->RegisterVariableInteger('Control', 'Steuerung', $this->PresChoice(self::CONTROL_TEXT, $allow), 10);
        $this->RegisterVariableInteger('Regulation', 'Regelung', $this->PresChoice(self::REG_TEXT, $allow), 11);
        $this->RegisterVariableInteger('ChargePower', 'Ladeleistung', $this->PresPower($minCharge, $range, $allow), 12);
        $this->RegisterVariableInteger('DischargePower', 'Entladeleistung', $this->PresPower($minDischarge, $range, $allow), 13);
        $this->RegisterVariableInteger('ExternalSetpoint', 'Externe Vorgabe', $this->PresPower(-$range, $range, $allow), 14);
        $this->RegisterVariableInteger('MaxChargePower', 'Max. Ladeleistung', $this->PresPower(0, $range, $allow), 15);
        $this->RegisterVariableInteger('MaxDischargePower', 'Max. Entladeleistung', $this->PresPower(0, $range, $allow), 16);
        $this->RegisterVariableInteger('AnkerMode', 'Anker-Betriebsart', $this->PresChoice($profile['ankerModes'], $allow), 17);
        $this->RegisterVariableInteger('Setpoint', 'Sollleistung am Gerät', $this->PresValue(' W'), 18);
        $this->RegisterVariableString('ControlInfo', 'Regelung – Hinweis', '', 19);
        $this->RegisterVariableBoolean('Online', 'Erreichbar', $this->PresBool('Nein', 'Ja'), 83);

        foreach (['Control', 'Regulation', 'ChargePower', 'DischargePower', 'ExternalSetpoint', 'MaxChargePower', 'MaxDischargePower', 'AnkerMode'] as $ident) {
            if ($allow) {
                $this->EnableAction($ident);
            } else {
                $this->DisableAction($ident);
            }
        }

        // --- Messwerte: je nach Gerätefamilie und Auswahl im Formular
        $types = ['i' => VARIABLETYPE_INTEGER, 'f' => VARIABLETYPE_FLOAT, 's' => VARIABLETYPE_STRING, 'b' => VARIABLETYPE_BOOLEAN];
        foreach (ANKRegisters::CATALOG as $ident => [$name, $type, $kind, $position, $families, $raw, $convert, $default]) {
            $wanted = in_array($family, $families, true) && $this->ValueWanted($ident, $default);
            // Archivierte Variablen bleiben erhalten, auch wenn sie abgewählt sind
            $keep = $wanted || $this->IsArchived($ident);
            $this->MaintainVariable($ident, $name, $types[$type], $this->PresKind($kind, $profile), $position, $keep);
        }

        // Startwerte nur beim allerersten Mal
        $this->InitValue('MaxChargePower', $range);
        $this->InitValue('MaxDischargePower', $range);
        $this->InitValue('ChargePower', $minCharge);
        $this->InitValue('DischargePower', $minDischarge);
        $this->InitValue('AnkerMode', $this->ReadAttributeInteger('AnkerMode'));
    }

    /** Darstellung zu einer Katalog-Art */
    private function PresKind(string $kind, array $profile)
    {
        switch ($kind) {
            case 'W':
                return $this->PresValue(' W');
            case '%':
                return $this->PresValue(' %');
            case 's':
                return $this->PresValue(' s');
            case 'V':
                return $this->PresValue(' V', 1);
            case '°C':
                return $this->PresValue(' °C', 1);
            case 'kWh':
                return $this->PresValue(' kWh', 1);
            case 'count':
                return $this->PresValue('');
            case 'bool':
                return $this->PresBool('Nein', 'Ja');
            case 'status':
                return $this->PresText(ANKRegisters::BATTERY_STATUS);
            case 'plant':
                return $this->PresText(ANKRegisters::PLANT_STATUS);
            case 'mode':
                return $this->PresText($profile['modes']);
            default:
                return '';
        }
    }

    // ---------------------------------------------------------------- Gerätefamilie und Werte-Auswahl

    /** Erkannte oder fest eingestellte Familie; solange unbekannt, gilt die Solarbank-Familie. */
    private function EffFamily(): string
    {
        $family = $this->ReadAttributeString('Family');
        return $family !== '' ? $family : ANKRegisters::FAMILY_SB;
    }

    private function Profile(): array
    {
        return ANKRegisters::Profile($this->EffFamily());
    }

    private function ModeText(int $mode): string
    {
        return $this->Profile()['modes'][$mode] ?? ('Betriebsart ' . $mode);
    }

    private function DeviceLabel(): string
    {
        $model = (string) $this->GetBuffer('LastModel');
        return $model !== '' ? $model : 'Gerät';
    }

    private function FamilyText(): string
    {
        $family = $this->ReadAttributeString('Family');
        if ($family === '') {
            return 'Erkannt: noch nichts – das Gerät wird bei der ersten Abfrage erkannt.';
        }
        $text = 'Erkannt: ' . ANKRegisters::FAMILY_NAMES[$family];
        $model = (string) $this->GetBuffer('LastModel');
        if ($model !== '') {
            $text .= ' (' . $model . ')';
        }
        $pv = $this->ReadAttributeInteger('HasPV');
        if ($pv === 0) {
            $text .= ' · ohne eigene PV-Eingänge';
        }
        return $text;
    }

    /** Gespeicherte Auswahl als Ident => bool */
    private function SavedSelection(): array
    {
        $saved = [];
        $rows = json_decode($this->ReadPropertyString('ValueSelection'), true);
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (isset($row['Ident'])) {
                $saved[(string) $row['Ident']] = !empty($row['Show']);
            }
        }
        return $saved;
    }

    /** Soll die Variable angelegt sein? Gespeicherte Auswahl hat Vorrang, sonst der Standard des Katalogs. */
    private function ValueWanted(string $ident, $default): bool
    {
        $saved = $this->SavedSelection();
        if (array_key_exists($ident, $saved)) {
            return $saved[$ident];
        }
        if ($default === 'pv') {
            return $this->ReadAttributeInteger('HasPV') !== 0;
        }
        return (bool) $default;
    }

    private function VarID(string $ident): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        return $id === false ? 0 : (int) $id;
    }

    /** Wert einer eigenen Variable oder null, wenn sie nicht angelegt ist. */
    private function Val(string $ident)
    {
        $id = $this->VarID($ident);
        return $id > 0 ? GetValue($id) : null;
    }

    /** Wird die Variable im Archiv aufgezeichnet? (nur lesend) */
    private function IsArchived(string $ident): bool
    {
        $id = $this->VarID($ident);
        if ($id === 0) {
            return false;
        }
        $archives = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        return count($archives) > 0 && AC_GetLoggingStatus($archives[0], $id);
    }

    /** Zeilen der Liste „Werte auswählen“ – aus dem Katalog, die gespeicherte Auswahl per Ident darübergelegt. */
    private function SelectionRows(): array
    {
        $family = $this->EffFamily();
        $rows = [];
        foreach (ANKRegisters::CATALOG as $ident => [$name, $type, $kind, $position, $families, $raw, $convert, $default]) {
            if (!in_array($family, $families, true)) {
                continue;
            }
            $id = $this->VarID($ident);
            $archived = $this->IsArchived($ident);
            $rows[] = [
                'Show'    => $this->ValueWanted($ident, $default),
                'Name'    => $name,
                'Value'   => $id > 0 ? GetValueFormatted($id) : '–',
                'Archive' => $archived ? 'archiviert – bleibt erhalten' : '',
                'Ident'   => $ident
            ];
        }
        return $rows;
    }

    private function ValuesCaption(): string
    {
        $rows = $this->SelectionRows();
        $shown = count(array_filter($rows, static fn($row) => $row['Show']));
        return 'Werte auswählen (' . $shown . ' von ' . count($rows) . ' angezeigt)';
    }

    private function InitValue(string $ident, int $value): void
    {
        $id = $this->GetIDForIdent($ident);
        if ((int) IPS_GetVariable($id)['VariableUpdated'] === 0) {
            $this->SetValue($ident, $value);
        }
    }

    /** Nur schreiben, wenn sich der Wert ändert – oder beim allerersten Mal. Abgewählte Variablen werden übersprungen. */
    private function SetVal(string $ident, $value): void
    {
        $id = $this->VarID($ident);
        if ($id === 0) {
            return;
        }
        if (GetValue($id) !== $value || (int) IPS_GetVariable($id)['VariableUpdated'] === 0) {
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
            $family = $this->DetectFamily();
            $data = $this->ReadDevice($family);
            $this->AdoptDevice($family, $data);
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

    /**
     * Gerätefamilie bestimmen: fest eingestellt, schon erkannt oder jetzt am Modellnamen der X1 erkennen.
     * Übernommen wird das Ergebnis erst, wenn die Abfrage danach gelingt (AdoptDevice).
     */
    private function DetectFamily(): string
    {
        $family = $this->ReadAttributeString('Family');
        if ($family !== '') {
            return $family;
        }
        try {
            $words = $this->MbRead(ANKRegisters::INPUT, ANKRegisters::X1_MODEL_REGISTER, ANKRegisters::X1_MODEL_COUNT);
            $text = self::MbString($words, 0, count($words), false) . ' ' . self::MbString($words, 0, count($words), true);
            if (stripos($text, 'X1-') !== false) {
                $this->SendDebug('Erkennung', 'X1 erkannt: ' . trim($text), 0);
                return ANKRegisters::FAMILY_X1;
            }
        } catch (Exception $e) {
            $this->SendDebug('Erkennung', 'Kein X1-Modellname lesbar (' . $e->getMessage() . ')', 0);
        }
        return ANKRegisters::FAMILY_SB;
    }

    /** Nach gelungener Abfrage: Familie, Modell und PV-Ausstattung merken; bei Änderung die Variablen anpassen. */
    private function AdoptDevice(string $family, array $data): void
    {
        $changed = false;
        if ($this->ReadAttributeString('Family') !== $family) {
            $this->WriteAttributeString('Family', $family);
            $this->LogMessage('Gerätefamilie erkannt: ' . ANKRegisters::FAMILY_NAMES[$family], KL_MESSAGE);
            $changed = true;
        }

        $model = '';
        $hasPV = $this->ReadAttributeInteger('HasPV');
        if ($family === ANKRegisters::FAMILY_X1) {
            if (isset($data['ModelName']) && $data['ModelName'] !== '') {
                $model = 'Anker SOLIX ' . $data['ModelName'];
            }
            $hasPV = 1;
            foreach (['LimitCharge' => 'RatedPower', 'LimitDischarge' => 'MaxActivePower'] as $attribute => $key) {
                if (isset($data[$key]) && abs((int) $data[$key]) > 0 && $this->ReadAttributeInteger($attribute) !== abs((int) $data[$key])) {
                    $this->WriteAttributeInteger($attribute, abs((int) $data[$key]));
                }
            }
        } elseif (isset($data['Serial'])) {
            $product = ANKRegisters::ProductFromSerial((string) $data['Serial']);
            if ($product !== null) {
                $model = $product[0];
                $hasPV = $product[1] ? 1 : 0;
            } elseif (($data['ModelCode'] ?? '') !== '') {
                $model = (string) $data['ModelCode'];
            }
        }
        if ($model !== '') {
            $this->SetBuffer('LastModel', $model);
        }
        if ($hasPV !== $this->ReadAttributeInteger('HasPV')) {
            $this->WriteAttributeInteger('HasPV', $hasPV);
            $changed = true;
        }
        if ($changed) {
            $this->MaintainVariables();
        }
    }

    /** @return array<string, mixed> Rohwerte laut Registerkarte der Familie */
    private function ReadDevice(string $family): array
    {
        $profile = ANKRegisters::Profile($family);
        $slowDue = (time() - (int) $this->GetBuffer('LastSlow')) >= max(10, $this->ReadPropertyInteger('SlowInterval'));
        $blocks = [];

        foreach ($profile['blocks'] as $name => [$type, $start, $count, $required]) {
            if ($required) {
                $blocks[$name] = $this->MbRead($type, $start, $count);
            }
        }
        if ($slowDue) {
            foreach ($profile['blocks'] as $name => [$type, $start, $count, $required]) {
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

        $lowWord = $profile['lowWordFirst'];
        $lowByte = $profile['lowByteFirst'];
        $data = [];
        foreach ($profile['values'] as $key => [$block, $register, $type]) {
            if (!isset($blocks[$block])) {
                continue;
            }
            $offset = $register - $profile['blocks'][$block][1];
            $words = $blocks[$block];
            if ($type === 'u16') {
                $data[$key] = $words[$offset] ?? 0;
            } elseif ($type === 'i16') {
                $value = $words[$offset] ?? 0;
                $data[$key] = $value >= 0x8000 ? $value - 0x10000 : $value;
            } elseif ($type === 'i32') {
                $data[$key] = self::MbInt32($words, $offset, $lowWord);
            } elseif ($type === 'u32') {
                $data[$key] = self::MbUInt32($words, $offset, $lowWord);
            } else {
                $data[$key] = self::MbString($words, $offset, (int) substr($type, 3), $lowByte);
            }
        }
        return $data;
    }

    private function Publish(array $data): void
    {
        $divisor = $this->Profile()['energyDivisor'];
        foreach (ANKRegisters::CATALOG as $ident => [$name, $type, $kind, $position, $families, $raw, $convert, $default]) {
            if ($raw === '' || !isset($data[$raw])) {
                continue;
            }
            $value = $data[$raw];
            switch ($convert) {
                case 'neg':
                    $value = -(int) $value;
                    break;
                case 'abs':
                    $value = abs((int) $value);
                    break;
                case 'energy':
                    $value = $value / $divisor;
                    break;
                case 'div10':
                    $value = $value / 10;
                    break;
                case 'is1':
                    $value = (int) $value === 1;
                    break;
            }
            if ($type === 'i') {
                $value = (int) $value;
            } elseif ($type === 'f') {
                $value = (float) $value;
            } elseif ($type === 's') {
                $value = (string) $value;
            }
            $this->SetVal($ident, $value);
        }
        $model = (string) $this->GetBuffer('LastModel');
        if ($model !== '') {
            $this->SetVal('Model', $model);
        }
        // Für Überblick und „Verbindung prüfen“ – unabhängig davon, welche Variablen angelegt sind
        $this->SetBuffer('LastSOC', (string) (int) $data['SOC']);
        $this->SetBuffer('LastMode', (string) (int) $data['DeviceMode']);
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

        if (!$manual && isset($this->Profile()['ankerModes'][$mode])) {
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
            $this->SetVal('ControlInfo', 'Anker regelt selbst (' . $this->ModeText($mode) . ').');
            return;
        }
        if (!$allow) {
            $this->SetVal('Setpoint', -(int) $data['DeviceSetpointRaw']);
            $this->SetVal('ControlInfo', 'Gerät steht auf Fremdsteuerung, die Steuerung ist im Modul aber nicht erlaubt.');
            return;
        }

        $this->MaintainDeviceWatchdog($data);
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

        // Oberste Grenzen: die eigenen Variablen, bei der X1 zusätzlich die Nennleistung laut Gerät.
        // Die Register „ladbare / entladbare Leistung“ (10036/10038) werden bewusst nicht als Grenze
        // benutzt: Sie folgen bei Fremdsteuerung der laufenden Vorgabe (an einer X1 gemessen: 40 W im
        // Stillstand) und würden jede neue Vorgabe abwürgen. Das Gerät begrenzt ohnehin selbst.
        $maxCharge = min($this->PowerRange(), max(0, (int) $this->GetValue('MaxChargePower')));
        $maxDischarge = min($this->PowerRange(), max(0, (int) $this->GetValue('MaxDischargePower')));
        $deviceCharge = $this->ReadAttributeInteger('LimitCharge');
        $deviceDischarge = $this->ReadAttributeInteger('LimitDischarge');
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

    /**
     * Nur X1: Der Wechselrichter fällt von selbst auf Eigenverbrauch zurück, wenn so viele Sekunden lang
     * kein Modbus-Verkehr mehr kommt (Register 10080, Werkswert 1800). Auf Wunsch stellt das Modul den Wert ein.
     */
    private function MaintainDeviceWatchdog(array $data): void
    {
        $wanted = $this->ReadPropertyInteger('X1Watchdog');
        if ($wanted <= 0 || $this->EffFamily() !== ANKRegisters::FAMILY_X1 || !isset($data['DeviceWatchdog'])) {
            return;
        }
        $wanted = $this->Clamp($wanted, 30, 3600);
        if ((int) $data['DeviceWatchdog'] === $wanted || (time() - (int) $this->GetBuffer('WatchdogWrite')) < 60) {
            return;
        }
        $this->SetBuffer('WatchdogWrite', (string) time());
        try {
            $this->SendDebug('Schreiben 10080', 'Geräte-Wächter ' . $wanted . ' s', 0);
            $this->MbWriteSingle(ANKRegisters::REG_WATCHDOG, $wanted);
        } catch (Exception $e) {
            $this->SendDebug('Schreiben 10080', 'nicht angenommen: ' . $e->getMessage(), 0);
        }
    }

    /** Betriebsart schreiben und zurücklesen. Abweichung wird gemeldet, nicht als Fehler gewertet. */
    private function WriteMode(int $mode): void
    {
        $this->SendDebug('Schreiben 10064', 'Betriebsart ' . $mode . ' (' . $this->ModeText($mode) . ')', 0);
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
            $this->MbWriteMultiple(ANKRegisters::REG_SETPOINT, self::MbFromInt32(-$watt, $this->Profile()['lowWordFirst']));
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
