<?php
class BlazePowerZoneConnectConfigurator extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('ScanSubnet', '192.168.1.0/24');
        $this->RegisterPropertyInteger('Port', 7621);

        // robust: Scan-Ergebnisse als Attribute
        $this->RegisterAttributeString('Discovered', '[]');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        if (!is_array($form)) $form = array();

        $values = json_decode($this->ReadAttributeString('Discovered'), true);
        if (!is_array($values)) $values = array();

        if (!isset($form['actions']) || !is_array($form['actions'])) $form['actions'] = array();

        for ($i = 0; $i < count($form['actions']); $i++) {
            if (isset($form['actions'][$i]['type']) && $form['actions'][$i]['type'] == 'Configurator'
                && isset($form['actions'][$i]['name']) && $form['actions'][$i]['name'] == 'Devices') {

                $form['actions'][$i]['columns'] = array(
                    array('caption' => 'IP',    'name' => 'address', 'width' => '140px'),
                    array('caption' => 'Model', 'name' => 'model',   'width' => '220px'),
                    array('caption' => 'Info',  'name' => 'info',    'width' => 'auto')
                );
                $form['actions'][$i]['values'] = $values;
            }
        }

        return json_encode($form);
    }

    public function Scan()
    {
        $subnet = trim($this->ReadPropertyString('ScanSubnet'));
        $port   = (int)$this->ReadPropertyInteger('Port');

        $ips = $this->ExpandCIDR($subnet, 2048);
        $found = array();

        foreach ($ips as $ip) {
            $probe = $this->ProbeDevice($ip, $port);
            if ($probe['ok']) {
                $found[] = $this->BuildRow($ip, $port, $probe['model']);
            }
        }

        $this->WriteAttributeString('Discovered', json_encode($found));
        // Hinweis: kein Live-Refresh per API; Form schließen/öffnen ist stabiler Standard.
    }

    private function BuildRow($ip, $port, $model)
{
    $deviceModuleID       = '{B4D9D0D1-7A92-4EBA-A9AF-1C1E29721B62}';
    $clientSocketModuleID = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';

    // Create-Kette MUSS Child -> Parent sein:
    // 1) Device (Child)
    // 2) Client Socket (Parent)
    $createChain = array(
        array(
            'moduleID' => $deviceModuleID,
            'configuration' => array(
                'EnableSubscribe' => true,
                'SubscribeFreq' => '0.5',
                'EnableZoneMute' => true,
                'EnableMetering' => false,
                'MeteringFreq' => '5.0',
                'MeteringRegex' => '^ZONE-[A-H]\\.(LEVEL|METER|VU|RMS|PEAK)',
                'MeteringMin' => -80,
                'MeteringMax' => 0,
                'IncludeSPDIF' => false,
                'IncludeDante' => true,
                'IncludeNoise' => true,
                'PollSlow' => 15,
                'PollFast' => 5,
                'FastAfterChange' => 30
            )
        ),
        array(
            'moduleID' => $clientSocketModuleID,
            'configuration' => array(
                'Host' => $ip,
                'Port' => $port,
                'Open' => true
            )
        )
    );

    return array(
        'address' => $ip,
        'model' => $model,
        'info' => 'TCP/' . $port,
        'instanceID' => 0,
        'create' => $createChain
    );
}


    private function ProbeDevice($ip, $port)
    {
        $timeout = 0.12;
        $fp = @fsockopen($ip, $port, $errno, $errstr, $timeout);
        if (!$fp) return array('ok' => false);

        stream_set_timeout($fp, 0, 150000);

        // Fingerprint: erst STATUS, dann MODEL_NAME (reduziert Phantom-Geräte)
        $ok1 = $this->QueryHasRegister($fp, "GET SYSTEM.STATUS.STATE", "+SYSTEM.STATUS.STATE ");
        $model = $this->QueryValue($fp, "GET SYSTEM.DEVICE.MODEL_NAME", "+SYSTEM.DEVICE.MODEL_NAME ");
        fclose($fp);

        if (!$ok1) return array('ok' => false);

        if ($model === null || trim($model) === '') {
            $model = 'PowerZone Connect';
        }

        return array('ok' => true, 'model' => $model);
    }

    private function QueryHasRegister($fp, $cmd, $needle)
    {
        @fwrite($fp, $cmd . "\n");
        $endToken = '*' . $cmd;

        $buf = '';
        $start = microtime(true);
        while (microtime(true) - $start < 0.18) {
            $chunk = fread($fp, 4096);
            if ($chunk !== false && $chunk !== '') {
                $buf .= $chunk;
                if (strpos($buf, $endToken) !== false) break;
            } else {
                $meta = stream_get_meta_data($fp);
                if (isset($meta['timed_out']) && $meta['timed_out']) break;
            }
        }

        return (strpos($buf, $needle) !== false);
    }

    private function QueryValue($fp, $cmd, $prefix)
    {
        @fwrite($fp, $cmd . "\n");
        $endToken = '*' . $cmd;

        $buf = '';
        $start = microtime(true);
        while (microtime(true) - $start < 0.18) {
            $chunk = fread($fp, 4096);
            if ($chunk !== false && $chunk !== '') {
                $buf .= $chunk;
                if (strpos($buf, $endToken) !== false) break;
            } else {
                $meta = stream_get_meta_data($fp);
                if (isset($meta['timed_out']) && $meta['timed_out']) break;
            }
        }

        $lines = explode("\n", $buf);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if ($line[0] == '#') return null;

            if (strpos($line, $prefix) === 0) {
                $raw = trim(substr($line, strlen($prefix)));
                if (strlen($raw) >= 2 && $raw[0] == '"' && substr($raw, -1) == '"') {
                    return stripcslashes(substr($raw, 1, -1));
                }
                return $raw;
            }
        }
        return null;
    }

    private function ExpandCIDR($cidr, $limit)
    {
        $cidr = trim($cidr);
        $parts = explode('/', $cidr);
        if (count($parts) != 2) return array();

        $base = trim($parts[0]);
        $mask = (int)$parts[1];
        if ($mask < 0 || $mask > 32) return array();

        $baseLong = ip2long($base);
        if ($baseLong === false) return array();

        $hostBits = 32 - $mask;
        $count = 1 << $hostBits;
        if ($count < 0) $count = 0;

        if ($count > (int)$limit) $count = (int)$limit;

        $netLong = $baseLong & (-1 << $hostBits);

        $ips = array();
        for ($i = 1; $i < $count - 1; $i++) {
            $ips[] = long2ip($netLong + $i);
        }

        if (count($ips) == 0) $ips[] = $base;
        return $ips;
    }
}
