<?php
class BlazePowerZoneConnect extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Verbindung
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 7621);
        $this->RegisterPropertyBoolean('Open', true);

        // Subscribe
        $this->RegisterPropertyBoolean('EnableSubscribe', true);
        $this->RegisterPropertyString('SubscribeFreq', '0.5');

        // Zone Mute
        $this->RegisterPropertyBoolean('EnableZoneMute', true);

        // Input Filter
        $this->RegisterPropertyBoolean('IncludeSPDIF', false);
        $this->RegisterPropertyBoolean('IncludeDante', true);
        $this->RegisterPropertyBoolean('IncludeNoise', true);
        $this->RegisterPropertyBoolean('IncludeMixes', false);

        // Metering
        $this->RegisterPropertyBoolean('EnableMetering', false);
        $this->RegisterPropertyString('MeteringFreq', '5.0');
        $this->RegisterPropertyString('MeteringRegex', '^ZONE-[A-H]\\.(LEVEL|METER|VU|RMS|PEAK)');
        $this->RegisterPropertyInteger('MeteringMin', -80);
        $this->RegisterPropertyInteger('MeteringMax', 0);

        // Polling Watchdog
        $this->RegisterPropertyInteger('PollSlow', 15);
        $this->RegisterPropertyInteger('PollFast', 5);
        $this->RegisterPropertyInteger('FastAfterChange', 30);

        $this->RegisterTimer('PollTimer', 0, 'BLAZE_Poll($_IPS["TARGET"]);');

        // Buffers
        $this->SetBuffer('RxBuffer', '');
        $this->SetBuffer('Topology', '');
        $this->SetBuffer('Pending', '');
        $this->SetBuffer('FastUntil', '0');
        $this->RegisterAttributeInteger('ParentSocketID', 0);

        // Diagnose
        $this->MaintainVariable('Online', 'Online', VARIABLETYPE_BOOLEAN, '~Switch', 1, true);
        $this->MaintainVariable('LastError', 'LastError', VARIABLETYPE_STRING, '', 90, true);
        $this->MaintainVariable('ErrorCounter', 'ErrorCounter', VARIABLETYPE_INTEGER, '', 91, true);
        $this->MaintainVariable('LastOKTimestamp', 'LastOKTimestamp', VARIABLETYPE_INTEGER, '~UnixTimestamp', 92, true);

        // Power
        $this->MaintainVariable('Power', 'Power', VARIABLETYPE_BOOLEAN, '~Switch', 2, true);
        $this->EnableAction('Power');

        // Profile: Sources / Mute
        $this->EnsureSourceProfile();
        $this->EnsureMuteProfile($this->GetInstanceMuteProfileName());
    }

    public function Destroy()
    {
        $this->DeleteInstanceProfiles();
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->EnsureSourceProfile();
        $this->EnsureMuteProfile($this->GetInstanceMuteProfileName());
        $this->UpdatePollTimer();
        $this->MaintainVariable('PowerState', '', VARIABLETYPE_STRING, '', 0, false);

        if (IPS_GetKernelRunlevel() == KR_READY) {
            if ($this->EnsureParentSocket(false)) {
                $this->SetFastPolling(10);
            } else {
                // Parent Socket konnte nicht erstellt/konfiguriert werden
                $this->SetValueBooleanSafe('Online', false);
            }
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

        if ($Message == IM_CHANGESTATUS) {
            $parentID = $this->GetParentID();
            if ($SenderID == $parentID) {
                // Parent Socket Status hat sich geändert
                if ($Data[0] == 102) {
                    // Status = IS_ACTIVE (102)
                    $this->SetValueBooleanSafe('Online', true);
                    $this->ClearErrorIfAny();
                } else {
                    // Andere Status (inaktiv, Fehler, etc.)
                    $this->SetValueBooleanSafe('Online', false);
                }
            }
        }
    }

    // ---------- UI Buttons ----------
    public function ConnectParentSocket()
    {
        if (IPS_GetKernelRunlevel() != KR_READY) return;

        if ($this->EnsureParentSocket(true)) {
            $this->ClearErrorIfAny();
            $this->SetFastPolling((int)$this->ReadPropertyInteger('FastAfterChange'));
            $this->Subscribe();
            $this->Poll();
        }
    }

    // ---------- Public ----------
    public function Poll()
    {
        if (!$this->Lock()) return;

        $this->UpdatePollTimer();

        $this->SendCommand("GET SYSTEM.STATUS.STATE");

        $top = $this->GetTopology();
        if ($top != null) {
            $this->SendCommand("GET ZONE-*.PRIMARY_SRC");
            $this->SendCommand("GET ZONE-*.GAIN");
            if ($this->ReadPropertyBoolean('EnableZoneMute')) {
                $this->SendCommand("GET ZONE-*.MUTE");
            }
        }

        $this->Unlock();
    }

    public function Discover()
    {
        if (!$this->Lock()) return;

        // Parent sicherstellen
        $parentID = $this->GetParentID();
        if ($parentID <= 0) {
            $this->EnsureParentSocket(true);
            $parentID = $this->GetParentID();
        }
        if ($parentID <= 0) {
            $this->SetError('Kein Parent (Client Socket) verbunden');
            $this->Unlock();
            return;
        }

        $cfg = @json_decode(@IPS_GetConfiguration($parentID), true);
        if (!is_array($cfg) || !isset($cfg['Host']) || !isset($cfg['Port'])) {
            $this->SetError('Parent-Konfiguration (Host/Port) nicht lesbar');
            $this->Unlock();
            return;
        }

        $host = trim($cfg['Host']);
        $port = (int)$cfg['Port'];
        if ($host === '' || $port <= 0) {
            $this->SetError('Parent Host/Port ungültig');
            $this->Unlock();
            return;
        }

        // Zonen ermitteln
        $zones = array();
        $zoneNames = array();
        $candidateZones = array('A','B','C','D','E','F','G','H');

        foreach ($candidateZones as $z) {
            $reply = $this->DirectQuery($host, $port, "GET ZONE-" . $z . ".NAME");
            if ($reply['ok']) {
                $zones[] = $z;
                if (isset($reply['registers']["ZONE-" . $z . ".NAME"])) {
                    $zoneNames[$z] = $reply['registers']["ZONE-" . $z . ".NAME"];
                }
            }
        }

        // Inputs ermitteln
        $includeSPDIF = $this->ReadPropertyBoolean('IncludeSPDIF');
        $includeDante = $this->ReadPropertyBoolean('IncludeDante');
        $includeNoise = $this->ReadPropertyBoolean('IncludeNoise');
        $includeMixes = $this->ReadPropertyBoolean('IncludeMixes');
        $mixStart = 500;
        $mixEnd = 507;

        $candidates = array();
        for ($i = 100; $i <= 107; $i++) $candidates[] = $i;
        if ($includeSPDIF) { $candidates[] = 200; $candidates[] = 201; }
        if ($includeDante) { $candidates[] = 300; $candidates[] = 301; $candidates[] = 302; $candidates[] = 303; }
        if ($includeNoise) { $candidates[] = 400; }
        if ($includeMixes) {
            for ($i = $mixStart; $i <= $mixEnd; $i++) $candidates[] = $i;
        }

        $sources = array();
        $sourceNames = array();

        foreach ($candidates as $iid) {
            $isMix = $includeMixes && ($iid >= $mixStart) && ($iid <= $mixEnd);
            $reply = $this->DirectQuery($host, $port, "GET IN-" . $iid . ".NAME");
            $name = '';

            if ($reply['ok']) {
                $sources[] = $iid;
                if (isset($reply['registers']["IN-" . $iid . ".NAME"])) {
                    $name = trim($reply['registers']["IN-" . $iid . ".NAME"]);
                }
            } elseif ($isMix) {
                // Mixes are valid sources even if name lookup fails.
                $sources[] = $iid;
            }

            if ($isMix) {
                if ($name === '' || stripos($name, 'GENERATOR') !== false) {
                    $name = 'Mix ' . ($iid - $mixStart + 1);
                }
            }

            if ($name !== '') {
                $sourceNames[$iid] = $name;
            }
        }

        $top = array(
            'zones' => $zones,
            'zoneNames' => $zoneNames,
            'sources' => $sources,
            'sourceNames' => $sourceNames
        );
        $this->SetBuffer('Topology', json_encode($top));

        $this->RebuildZoneVariables();
        $this->UpdateSourceProfileAssociations();

        $this->Unlock();

        $this->Subscribe();
        $this->SetFastPolling((int)$this->ReadPropertyInteger('FastAfterChange'));
        $this->Poll();
    }

    public function Subscribe()
    {
        if (!$this->Lock()) return;

        if (!$this->ReadPropertyBoolean('EnableSubscribe')) {
            $this->Unlock();
            return;
        }

        $freq = $this->ToFloat($this->ReadPropertyString('SubscribeFreq'), 0.5);
        $cmd = "SUBSCRIBE REG";
        if ($freq > 0) $cmd .= " " . $this->FormatFloat($freq);
        $this->SendCommand($cmd);

        if ($this->ReadPropertyBoolean('EnableZoneMute')) {
            $this->SendCommand("GET ZONE-*.MUTE");
        }

        $this->Unlock();
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident == 'Power') {
            $this->SetPendingPower((bool)$Value);
            $this->SendCommand(((bool)$Value) ? "POWER_ON" : "POWER_OFF");
            $this->SetFastPolling((int)$this->ReadPropertyInteger('FastAfterChange'));
            return;
        }

        if (strpos($Ident, 'ZONE_') === 0) {
            $parts = explode('_', $Ident);
            if (count($parts) >= 3) {
                $zone = $parts[1];
                $what = $parts[2];

                if ($what == 'Source') {
                    $this->SetPendingZoneSource($zone, (int)$Value);
                    $this->SendCommand("SET ZONE-" . $zone . ".PRIMARY_SRC " . (int)$Value);
                    $this->SetFastPolling((int)$this->ReadPropertyInteger('FastAfterChange'));
                    return;
                }

                if ($what == 'Gain') {
                    $gain = (float)$Value;
                    $this->SendCommand("SET ZONE-" . $zone . ".GAIN " . $this->FormatFloat($gain));
                    $this->SetFastPolling((int)$this->ReadPropertyInteger('FastAfterChange'));
                    return;
                }

                if ($what == 'Mute') {
                    $mute = (bool)$Value;
                    $this->SendCommand("SET ZONE-" . $zone . ".MUTE " . ($mute ? "1" : "0"));
                    $this->SetValueBooleanSafeByIdent('ZONE_' . $zone . '_Mute', $mute);
                    $this->SetFastPolling((int)$this->ReadPropertyInteger('FastAfterChange'));
                    return;
                }
            }
        }
    }

    // ---------- Parent Socket Handling ----------
    private function EnsureParentSocket($log)
    {
        $host = trim($this->ReadPropertyString('Host'));
        $port = (int)$this->ReadPropertyInteger('Port');
        $open = (bool)$this->ReadPropertyBoolean('Open');

        if ($host === '' || $port <= 0) {
            if ($log) $this->SetError('Host/Port im Device nicht gesetzt (Konfiguration speichern, dann Parent verbinden)');
            return false;
        }

        $parentID = $this->GetParentID();
        $clientSocketModuleID = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
        $clientSocketIdent = 'BlazeClientSocket_' . $this->InstanceID;

        $socketID = 0;

        if ($parentID > 0) {
            $inst = @IPS_GetInstance($parentID);
            if (is_array($inst) && isset($inst['ModuleInfo']['ModuleID'])) {
                if (strtoupper($inst['ModuleInfo']['ModuleID']) == strtoupper($clientSocketModuleID)) {
                    $socketID = $parentID;
                }
            }
        }

        if ($socketID <= 0) {
            $storedID = (int)$this->ReadAttributeInteger('ParentSocketID');
            if ($storedID > 0 && @IPS_InstanceExists($storedID)) {
                $inst = @IPS_GetInstance($storedID);
                if (is_array($inst) && isset($inst['ModuleInfo']['ModuleID'])) {
                    if (strtoupper($inst['ModuleInfo']['ModuleID']) == strtoupper($clientSocketModuleID)) {
                        $socketID = $storedID;
                    }
                }
            }
        }

        if ($socketID <= 0) {
            $myParent = (int)@IPS_GetParent($this->InstanceID);
            $existingID = 0;
            if ($myParent > 0) {
                $existingID = (int)@IPS_GetObjectIDByIdent($clientSocketIdent, $myParent);
            }

            if ($existingID > 0 && @IPS_InstanceExists($existingID)) {
                $inst = @IPS_GetInstance($existingID);
                if (is_array($inst) && isset($inst['ModuleInfo']['ModuleID'])) {
                    if (strtoupper($inst['ModuleInfo']['ModuleID']) == strtoupper($clientSocketModuleID)) {
                        $socketID = $existingID;
                    }
                }
            }
        }

        if ($socketID <= 0) {
            $socketID = @IPS_CreateInstance($clientSocketModuleID);
            if ($socketID <= 0) {
                if ($log) $this->SetError('Client Socket konnte nicht erstellt werden');
                return false;
            }

            $myParent = (int)@IPS_GetParent($this->InstanceID);
            if ($myParent > 0) @IPS_SetParent($socketID, $myParent);

            @IPS_SetName($socketID, 'Blaze Client Socket');
            @IPS_SetIdent($socketID, $clientSocketIdent);
        }

        if ($socketID <= 0) {
            if ($log) $this->SetError('Client Socket konnte nicht ermittelt werden');
            return false;
        }

        $this->WriteAttributeInteger('ParentSocketID', $socketID);

        // Parent konfigurieren (nur wenn nötig)
        $cfg = @json_decode(@IPS_GetConfiguration($socketID), true);
        if (!is_array($cfg)) $cfg = array();

        $needApply = false;

        if (!isset($cfg['Host']) || (string)$cfg['Host'] !== (string)$host) {
            @IPS_SetProperty($socketID, 'Host', $host);
            $needApply = true;
        }
        if (!isset($cfg['Port']) || (int)$cfg['Port'] !== (int)$port) {
            @IPS_SetProperty($socketID, 'Port', $port);
            $needApply = true;
        }
        if (!isset($cfg['Open']) || (bool)$cfg['Open'] !== (bool)$open) {
            @IPS_SetProperty($socketID, 'Open', $open);
            $needApply = true;
        }

        if ($needApply) {
            @IPS_ApplyChanges($socketID);
        }

        if ($parentID > 0 && $parentID != $socketID) {
            @IPS_DisconnectInstance($this->InstanceID);
            $parentID = 0;
        }

        if ($parentID != $socketID) {
            if (!@IPS_ConnectInstance($this->InstanceID, $socketID)) {
                if ($log) $this->SetError('Client Socket konnte nicht verbunden werden');
                return false;
            }

            // sicherheitshalber neu lesen
            $parentID = $this->GetParentID();
            if ($parentID <= 0) {
                usleep(150000);
                $parentID = $this->GetParentID();
            }
            if ($parentID <= 0) {
                if ($log) $this->SetError('Client Socket erstellt, aber nicht verbunden (ConnectionID blieb 0)');
                return false;
            }
        }

        $this->RegisterMessage($parentID, IM_CHANGESTATUS);
        return true;
    }

    // ---------- Receive ----------
    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || !isset($data['Buffer'])) return;

        $chunk = utf8_decode($data['Buffer']);
        $buf = $this->GetBuffer('RxBuffer') . $chunk;

        $lines = explode("\n", $buf);
        $remainder = array_pop($lines);
        $this->SetBuffer('RxBuffer', $remainder);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $this->HandleLine($line);
        }
    }

    private function HandleLine($line)
    {
        if ($line === '') return;

        if ($line[0] == '#') {
            $this->SetError(substr($line, 1));
            return;
        }
        if ($line[0] == '*') {
            return;
        }
        if ($line[0] == '+') {
            $this->SetValueBooleanSafe('Online', true);
            $this->SetValueIntegerSafe('LastOKTimestamp', time());
            $this->ClearErrorIfAny();

            $spacePos = strpos($line, ' ');
            if ($spacePos === false) return;

            $reg = substr($line, 1, $spacePos - 1);
            $raw = trim(substr($line, $spacePos + 1));
            $val = $this->ParseValue($raw);

            $this->ApplyRegister($reg, $val);
        }
    }

    private function ApplyRegister($reg, $val)
    {
        if ($reg == 'SYSTEM.STATUS.STATE') {
            $state = (string)$val;
            if ($state == 'ON') {
                $this->SetValueBooleanSafe('Power', true);
                $this->ClearPendingPower();
            } elseif ($state == 'STANDBY') {
                $this->SetValueBooleanSafe('Power', false);
                $this->ClearPendingPower();
            }

            $this->SetFastPolling((int)$this->ReadPropertyInteger('FastAfterChange'));
            return;
        }

        if (preg_match('/^ZONE\-([A-H])\.PRIMARY_SRC$/', $reg, $m)) {
            $z = $m[1];

            $pending = $this->GetPending();
            $pendingSrc = null;
            if (isset($pending['zoneSources']) && isset($pending['zoneSources'][$z])) {
                $pendingSrc = (int)$pending['zoneSources'][$z];
            }

            $ident = 'ZONE_' . $z . '_Source';
            if ($pendingSrc !== null) {
                if ((int)$val == $pendingSrc) {
                    $this->ClearPendingZoneSource($z);
                    $this->SetValueIntegerSafeByIdent($ident, (int)$val);
                }
            } else {
                $this->SetValueIntegerSafeByIdent($ident, (int)$val);
            }
            return;
        }

        if (preg_match('/^ZONE\-([A-H])\.GAIN$/', $reg, $m)) {
            $ident = 'ZONE_' . $m[1] . '_Gain';
            $this->SetValueFloatSafeByIdent($ident, (float)$val);
            return;
        }

        if ($this->ReadPropertyBoolean('EnableZoneMute') && preg_match('/^ZONE\-([A-H])\.MUTE$/', $reg, $m)) {
            $ident = 'ZONE_' . $m[1] . '_Mute';
            $this->SetValueBooleanSafeByIdent($ident, ((int)$val) == 1);
            return;
        }
    }

    // ---------- Variables / Profiles ----------
    private function EnsureSourceProfile()
    {
        $p = $this->GetInstanceSourceProfileName();
        if (!IPS_VariableProfileExists($p)) {
            IPS_CreateVariableProfile($p, VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon($p, 'Speaker');
        }
    }

    private function EnsureMuteProfile($p)
    {
        if (!IPS_VariableProfileExists($p)) {
            IPS_CreateVariableProfile($p, VARIABLETYPE_BOOLEAN);
            IPS_SetVariableProfileAssociation($p, 0, 'Unmute', '', 0x00FF00);
            IPS_SetVariableProfileAssociation($p, 1, 'Mute', '', 0xFF0000);
        }
    }

    private function UpdateSourceProfileAssociations()
    {
        $p = $this->GetInstanceSourceProfileName();
        $this->EnsureSourceProfile();

        $top = $this->GetTopology();
        if ($top == null) return;

        $sources = isset($top['sources']) && is_array($top['sources']) ? $top['sources'] : array();
        $names = isset($top['sourceNames']) && is_array($top['sourceNames']) ? $top['sourceNames'] : array();

        if (count($sources) === 0) return;

        $prof = IPS_GetVariableProfile($p);
        if (isset($prof['Associations']) && is_array($prof['Associations'])) {
            foreach ($prof['Associations'] as $a) {
                IPS_SetVariableProfileAssociation($p, (int)$a['Value'], '', '', -1);
            }
        }

        sort($sources);
        foreach ($sources as $sid) {
            $label = (isset($names[$sid]) && $names[$sid] !== '') ? $names[$sid] : ('Input ' . $sid);
            IPS_SetVariableProfileAssociation($p, (int)$sid, $label, '', -1);
        }
    }

    private function EnsureGainProfile($p)
    {
        if (!IPS_VariableProfileExists($p)) {
            IPS_CreateVariableProfile($p, VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileValues($p, -80.0, 20.0, 0.5);
            IPS_SetVariableProfileText($p, '', ' dB');
            IPS_SetVariableProfileIcon($p, 'Intensity');
        }
    }

    private function RebuildZoneVariables()
    {
        $top = $this->GetTopology();
        $zones = array();
        if ($top != null && isset($top['zones']) && is_array($top['zones'])) {
            $zones = $top['zones'];
        }

        $zonesCat = $this->CreateCategoryByIdent('Zones', 'Zones', 10, $this->InstanceID);

        $candidateZones = array('A','B','C','D','E','F','G','H');
        foreach ($candidateZones as $z) {
            $exists = in_array($z, $zones);
            $zoneCatIdent = 'ZONECAT_' . $z;

            if ($exists) {
                $zoneLabel = 'Zone ' . $z;
                if ($top != null && isset($top['zoneNames']) && isset($top['zoneNames'][$z]) && $top['zoneNames'][$z] !== '') {
                    $zoneLabel .= ' (' . $top['zoneNames'][$z] . ')';
                }

                $zoneCat = $this->CreateCategoryByIdent($zoneCatIdent, $zoneLabel, 100 + ord($z), $zonesCat);

                $srcIdent = 'ZONE_' . $z . '_Source';
                $srcProfile = $this->GetInstanceSourceProfileName();
                $this->EnsureZoneVariable($zoneCat, $srcIdent, 'Quelle', VARIABLETYPE_INTEGER, $srcProfile, 1, true);

                $gainIdent = 'ZONE_' . $z . '_Gain';
                $gainProfile = $this->GetInstanceGainProfileName($z);
                $this->EnsureGainProfile($gainProfile);
                $this->EnsureZoneVariable($zoneCat, $gainIdent, 'Lautstärke (dB)', VARIABLETYPE_FLOAT, $gainProfile, 2, true);

                if ($this->ReadPropertyBoolean('EnableZoneMute')) {
                    $muteIdent = 'ZONE_' . $z . '_Mute';
                    $muteProfile = $this->GetInstanceMuteProfileName();
                    $this->EnsureMuteProfile($muteProfile);
                    $this->EnsureZoneVariable($zoneCat, $muteIdent, 'Mute', VARIABLETYPE_BOOLEAN, $muteProfile, 3, true);
                } else {
                    $this->DeleteObjectByIdent('ZONE_' . $z . '_Mute', $zoneCat);
                    $this->DeleteObjectByIdent('ZONE_' . $z . '_Mute', $this->InstanceID);
                }

            } else {
                $zoneCat = @IPS_GetObjectIDByIdent($zoneCatIdent, $zonesCat);
                if ($zoneCat > 0) {
                    $this->DeleteObjectByIdent('ZONE_' . $z . '_Source', $zoneCat);
                    $this->DeleteObjectByIdent('ZONE_' . $z . '_Gain', $zoneCat);
                    $this->DeleteObjectByIdent('ZONE_' . $z . '_Mute', $zoneCat);
                }
                $this->DeleteObjectByIdent('ZONE_' . $z . '_Source', $this->InstanceID);
                $this->DeleteObjectByIdent('ZONE_' . $z . '_Gain', $this->InstanceID);
                $this->DeleteObjectByIdent('ZONE_' . $z . '_Mute', $this->InstanceID);
            }
        }
    }

    private function GetInstanceSourceProfileName()
    {
        return 'BLAZE.' . $this->InstanceID . '.Sources';
    }

    private function GetInstanceMuteProfileName()
    {
        return 'BLAZE.' . $this->InstanceID . '.Mute';
    }

    private function GetInstanceGainProfileName($zone)
    {
        return 'BLAZE.' . $this->InstanceID . '.Gain.' . $zone;
    }

    private function DeleteInstanceProfiles()
    {
        $prefix = 'BLAZE.' . $this->InstanceID . '.';
        $list = IPS_GetVariableProfileList();
        foreach ($list as $p) {
            if (strpos($p, $prefix) === 0) {
                @IPS_DeleteVariableProfile($p);
            }
        }
    }

    // ---------- Networking ----------
    private function SendCommand($cmd)
    {
        $parentID = $this->GetParentID();
        if ($parentID <= 0) {
            $this->EnsureParentSocket(true);
            $parentID = $this->GetParentID();
            if ($parentID <= 0) return false;
        }

        $st = @IPS_GetInstance($parentID);
        if (is_array($st) && isset($st['InstanceStatus'])) {
            if ((int)$st['InstanceStatus'] != 102) {
                return false;
            }
        }

        $payload = array(
            'DataID'  => '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}',
            'Buffer'  => utf8_encode($cmd . "\n"),
            'Type'    => 0
        );
        return @$this->SendDataToParent(json_encode($payload));
    }

    private function DirectQuery($host, $port, $cmd)
    {
        $registers = array();

        $timeout = 0.25;
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (!$fp) return array('ok' => false, 'registers' => array());

        stream_set_timeout($fp, 0, 250000);
        @fwrite($fp, $cmd . "\n");

        $endToken = '*' . $cmd;
        $buf = '';
        $start = microtime(true);
        while (microtime(true) - $start < 0.35) {
            $chunk = @fread($fp, 8192);
            if ($chunk !== false && $chunk !== '') {
                $buf .= $chunk;
                if (strpos($buf, $endToken) !== false) break;
            } else {
                $meta = stream_get_meta_data($fp);
                if (isset($meta['timed_out']) && $meta['timed_out']) break;
            }
        }

        @fclose($fp);

        $lines = explode("\n", $buf);
        $ok = false;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if ($line[0] == '#') return array('ok' => false, 'registers' => array());
            if ($line[0] == '*') $ok = true;

            if ($line[0] == '+') {
                $spacePos = strpos($line, ' ');
                if ($spacePos !== false) {
                    $reg = substr($line, 1, $spacePos - 1);
                    $raw = trim(substr($line, $spacePos + 1));
                    $registers[$reg] = $this->ParseValue($raw);
                }
            }
        }

        return array('ok' => $ok, 'registers' => $registers);
    }

    // ---------- Pending ----------
    private function GetPending()
    {
        $p = $this->GetBuffer('Pending');
        if ($p === '') return array('power' => null, 'zoneSources' => array());

        $a = json_decode($p, true);
        if (!is_array($a)) return array('power' => null, 'zoneSources' => array());

        if (!isset($a['zoneSources']) || !is_array($a['zoneSources'])) $a['zoneSources'] = array();
        if (!array_key_exists('power', $a)) $a['power'] = null;

        return $a;
    }

    private function SetPending($a)
    {
        $this->SetBuffer('Pending', json_encode($a));
    }

    private function SetPendingPower($on)
    {
        $p = $this->GetPending();
        $p['power'] = $on ? 1 : 0;
        $this->SetValueBooleanSafe('Power', (bool)$on);
        $this->SetPending($p);
    }

    private function ClearPendingPower()
    {
        $p = $this->GetPending();
        $p['power'] = null;
        $this->SetPending($p);
    }

    private function SetPendingZoneSource($zone, $src)
    {
        $p = $this->GetPending();
        $p['zoneSources'][$zone] = (int)$src;

        // stabile UI: Sollwert sofort anzeigen
        $this->SetValueIntegerSafeByIdent('ZONE_' . $zone . '_Source', (int)$src);

        $this->SetPending($p);
    }

    private function ClearPendingZoneSource($zone)
    {
        $p = $this->GetPending();
        if (isset($p['zoneSources'][$zone])) unset($p['zoneSources'][$zone]);
        $this->SetPending($p);
    }

    // ---------- Polling ----------
    private function SetFastPolling($seconds)
    {
        $until = time() + max(0, (int)$seconds);
        $this->SetBuffer('FastUntil', (string)$until);
        $this->UpdatePollTimer();
    }

    private function UpdatePollTimer()
    {
        $slow = max(1, (int)$this->ReadPropertyInteger('PollSlow'));
        $fast = max(1, (int)$this->ReadPropertyInteger('PollFast'));
        $until = (int)$this->GetBuffer('FastUntil');

        $interval = ($until > time()) ? $fast : $slow;
        $this->SetTimerInterval('PollTimer', $interval * 1000);
    }

    // ---------- Lock ----------
    private function Lock()
    {
        $ok = @IPS_SemaphoreEnter('BLAZE_LOCK_' . $this->InstanceID, 200);
        if (!$ok) {
            $this->SetFastPolling((int)$this->ReadPropertyInteger('FastAfterChange'));
            return false;
        }
        return true;
    }

    private function Unlock()
    {
        @IPS_SemaphoreLeave('BLAZE_LOCK_' . $this->InstanceID);
    }

    // ---------- Helpers ----------
    private function GetTopology()
    {
        $t = $this->GetBuffer('Topology');
        if ($t === '') return null;

        $a = json_decode($t, true);
        if (!is_array($a)) return null;

        return $a;
    }

    private function CreateCategoryByIdent($ident, $name, $pos, $parent)
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parent);
        if ($id > 0) {
            @IPS_SetName($id, $name);
            @IPS_SetPosition($id, (int)$pos);
            return $id;
        }

        $id = IPS_CreateCategory();
        IPS_SetParent($id, $parent);
        IPS_SetIdent($id, $ident);
        IPS_SetName($id, $name);
        IPS_SetPosition($id, (int)$pos);
        return $id;
    }

    private function EnsureZoneVariable($parent, $ident, $name, $type, $profile, $pos, $withAction)
    {
        if ($parent <= 0) return 0;

        $varID = $this->FindVariableIDByIdent($ident);

        if ($varID > 0) {
            $obj = @IPS_GetObject($varID);
            if (!is_array($obj) || (int)$obj['ObjectType'] !== OBJECTTYPE_VARIABLE) {
                @IPS_DeleteObject($varID);
                $varID = 0;
            }
        }

        if ($varID > 0) {
            $var = @IPS_GetVariable($varID);
            if (!is_array($var) || (int)$var['VariableType'] !== (int)$type) {
                @IPS_DeleteObject($varID);
                $varID = 0;
            }
        }

        if ($varID > 0) {
            @IPS_SetParent($varID, $this->InstanceID);
        }

        $this->MaintainVariable($ident, $name, $type, $profile, $pos, true);
        $this->MaintainAction($ident, $withAction);

        $varID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($varID > 0 && $parent > 0) {
            @IPS_SetParent($varID, $parent);
        }

        $this->RemoveDuplicateVariables($ident, $varID);
        return $varID;
    }

    private function DeleteObjectByIdent($ident, $parent)
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parent);
        if ($id > 0) {
            @IPS_DeleteObject($id);
        }
    }

    private function FindVariableIDByIdent($ident)
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id > 0) return $id;

        return $this->FindObjectIDByIdentRecursive($ident, $this->InstanceID);
    }

    private function RemoveDuplicateVariables($ident, $keepID)
    {
        if ($keepID <= 0) return;

        $ids = $this->FindObjectIDsByIdentRecursive($ident, $this->InstanceID);
        foreach ($ids as $id) {
            if ($id != $keepID) {
                @IPS_DeleteObject($id);
            }
        }
    }

    private function FindObjectIDByIdentRecursive($ident, $parent)
    {
        $children = @IPS_GetChildrenIDs($parent);
        if (!is_array($children)) return 0;

        foreach ($children as $childID) {
            $obj = @IPS_GetObject($childID);
            if (!is_array($obj)) continue;

            if (isset($obj['ObjectIdent']) && (string)$obj['ObjectIdent'] === (string)$ident) {
                if ((int)$obj['ObjectType'] === OBJECTTYPE_VARIABLE) {
                    return $childID;
                }
            }

            if ((int)$obj['ObjectType'] === OBJECTTYPE_CATEGORY) {
                $found = $this->FindObjectIDByIdentRecursive($ident, $childID);
                if ($found > 0) return $found;
            }
        }

        return 0;
    }

    private function FindObjectIDsByIdentRecursive($ident, $parent)
    {
        $matches = array();
        $children = @IPS_GetChildrenIDs($parent);
        if (!is_array($children)) return $matches;

        foreach ($children as $childID) {
            $obj = @IPS_GetObject($childID);
            if (!is_array($obj)) continue;

            if (isset($obj['ObjectIdent']) && (string)$obj['ObjectIdent'] === (string)$ident) {
                if ((int)$obj['ObjectType'] === OBJECTTYPE_VARIABLE) {
                    $matches[] = $childID;
                }
            }

            if ((int)$obj['ObjectType'] === OBJECTTYPE_CATEGORY) {
                $matches = array_merge($matches, $this->FindObjectIDsByIdentRecursive($ident, $childID));
            }
        }

        return $matches;
    }

    private function ParseValue($raw)
    {
        $raw = trim($raw);

        if (strlen($raw) >= 2 && $raw[0] == '"' && substr($raw, -1) == '"') {
            return stripcslashes(substr($raw, 1, -1));
        }

        if (preg_match('/^[A-Z_]+$/', $raw)) return $raw;

        if (is_numeric($raw)) {
            if (strpos($raw, '.') !== false) return (float)$raw;
            return (int)$raw;
        }

        return $raw;
    }

    private function FormatFloat($f)
    {
        $s = sprintf('%.2f', (float)$f);
        $s = str_replace(',', '.', $s);
        $s = rtrim($s, '0');
        $s = rtrim($s, '.');
        if ($s === '-0') $s = '0';
        return $s;
    }

    private function ToFloat($s, $fallback)
    {
        $s = trim((string)$s);
        if ($s === '') return (float)$fallback;

        $s = str_replace(',', '.', $s);
        if (!is_numeric($s)) return (float)$fallback;

        return (float)$s;
    }

    private function SetError($msg)
    {
        $msg = trim((string)$msg);
        if ($msg === '') $msg = 'Unknown error';

        $vid = @IPS_GetObjectIDByIdent('LastError', $this->InstanceID);
        if ($vid > 0) {
            $cur = (string)@GetValueString($vid);
            if ($cur !== $msg) @SetValueString($vid, $msg);
        }

        $cid = @IPS_GetObjectIDByIdent('ErrorCounter', $this->InstanceID);
        if ($cid > 0) {
            $cnt = (int)@GetValueInteger($cid);
            @SetValueInteger($cid, $cnt + 1);
        }

        $this->SetValueBooleanSafe('Online', false);
    }

    private function ClearErrorIfAny()
    {
        $vid = @IPS_GetObjectIDByIdent('LastError', $this->InstanceID);
        if ($vid > 0) {
            $cur = (string)@GetValueString($vid);
            if ($cur !== '') @SetValueString($vid, '');
        }
    }

    private function SetValueBooleanSafe($ident, $val)
    {
        $vid = $this->FindVariableIDByIdent($ident);
        if ($vid > 0) {
            $cur = (bool)@GetValueBoolean($vid);
            if ($cur !== (bool)$val) @SetValueBoolean($vid, (bool)$val);
        }
    }

    private function SetValueIntegerSafe($ident, $val)
    {
        $vid = $this->FindVariableIDByIdent($ident);
        if ($vid > 0) {
            $cur = (int)@GetValueInteger($vid);
            if ($cur !== (int)$val) @SetValueInteger($vid, (int)$val);
        }
    }

    private function SetValueStringSafe($ident, $val)
    {
        $vid = $this->FindVariableIDByIdent($ident);
        if ($vid > 0) {
            $cur = (string)@GetValueString($vid);
            if ($cur !== (string)$val) @SetValueString($vid, (string)$val);
        }
    }

    private function SetValueIntegerSafeByIdent($ident, $val)
    {
        $vid = $this->FindVariableIDByIdent($ident);
        if ($vid > 0) {
            $cur = (int)@GetValueInteger($vid);
            if ($cur !== (int)$val) @SetValueInteger($vid, (int)$val);
        }
    }

    private function SetValueFloatSafeByIdent($ident, $val)
    {
        $vid = $this->FindVariableIDByIdent($ident);
        if ($vid > 0) {
            $cur = (float)@GetValueFloat($vid);
            if (abs($cur - (float)$val) > 0.001) @SetValueFloat($vid, (float)$val);
        }
    }

    private function SetValueBooleanSafeByIdent($ident, $val)
    {
        $vid = $this->FindVariableIDByIdent($ident);
        if ($vid > 0) {
            $cur = (bool)@GetValueBoolean($vid);
            if ($cur !== (bool)$val) @SetValueBoolean($vid, (bool)$val);
        }
    }

    private function GetParentID()
    {
        $ins = @IPS_GetInstance($this->InstanceID);
        if (!is_array($ins)) return 0;
        return (int)$ins['ConnectionID'];
    }
}
