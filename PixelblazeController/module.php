<?php

declare(strict_types=1);

class PixelblazeController extends IPSModuleStrict
{

    public function Create(): void
    {
        parent::Create();

        // Fordere WebSocket Client als Parent an
        

        // Properties
        $this->RegisterPropertyInteger('AutoReconnectInterval', 30);

        // Internes Attribut für die letzte Helligkeit vor dem Ausschalten
        $this->RegisterAttributeInteger('LastBrightness', 50);
        // Internes Attribut für die Programmliste (Map von Index -> String ID)
        $this->RegisterAttributeString('ProgramMap', '[]');

        // Variablen
        $this->RegisterVariableBoolean('Power', '💡 Status', '', 10);
        $this->EnableAction('Power');

        $this->RegisterVariableInteger('Brightness', '🔆 Helligkeit', '', 20);
        $this->EnableAction('Brightness');
            
            

        $this->RegisterVariableInteger('ActiveProgram', '🎨 Programm', '', 30);
        $this->EnableAction('ActiveProgram');

        // Timer für Auto-Reconnect
        $this->RegisterTimer('ReconnectTimer', 0, 'PB_Reconnect($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Alte String-Variable löschen falls vorhanden
        $oldVar = @$this->GetIDForIdent('ActiveProgramID');
        if ($oldVar > 0) {
            $this->UnregisterVariable('ActiveProgramID');
        }

        $interval = $this->ReadPropertyInteger('AutoReconnectInterval');
        $this->SetTimerInterval('ReconnectTimer', $interval * 1000);

        IPS_SetVariableCustomPresentation($this->GetIDForIdent('Power'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON' => 'Power'
        ]);

        IPS_SetVariableCustomPresentation($this->GetIDForIdent('Brightness'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'ICON' => 'Sun',
            'MIN' => 0.0,
            'MAX' => 100.0,
            'STEP' => 1.0,
            'SUFFIX' => ' %'
        ]);

        $mapRaw = $this->ReadAttributeString('ProgramMap');
        $map = json_decode($mapRaw, true);
        
        if (is_array($map)) {
            foreach ($map as $i => $prog) {
                IPS_SetVariableProfileAssociation('Pixelblaze.Program', $i, $prog['name'], '', -1);
            }
        }
        
        $this->UpdateVisibility($this->GetValue('Power'));
    }

    private function UpdateVisibility(bool $isVisible): void
    {
        $hidden = !$isVisible;
        $this->SetHiddenSafe('Brightness', $hidden);
        $this->SetHiddenSafe('ActiveProgram', $hidden);
    }

    private function SetHiddenSafe(string $ident, bool $hidden): void
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id > 0) {
            IPS_SetHidden($id, $hidden);
        }
    }

    protected function LogMessage(string $Message, int $Type = KL_MESSAGE): bool
    {
        parent::LogMessage('PixelblazeController: ' . $Message, $Type);
        return true;
    }

    public function RequestAction(string $Ident, $Value): void
    {
        switch ($Ident) {
            case 'Power':
                if ($Value) {
                    // Einschalten -> Letzte Helligkeit wiederherstellen
                    $brightness = $this->ReadAttributeInteger('LastBrightness');
                    if ($brightness <= 0) {
                        $brightness = 100;
                    }
                    $this->SetBrightness((float)$brightness);
                    $this->SetValue('Power', true);
                    $this->SetValue('Brightness', $brightness);
                    $this->UpdateVisibility(true);
                    $this->LogMessage("Angeschaltet mit Helligkeit: " . $brightness . "%");
                } else {
                    // Ausschalten -> Aktuelle Helligkeit speichern, dann auf 0 setzen
                    $current = $this->GetValue('Brightness');
                    if ($current > 0) {
                        $this->WriteAttributeInteger('LastBrightness', $current);
                    }
                    $this->SetBrightness(0.0);
                    $this->SetValue('Power', false);
                    $this->SetValue('Brightness', 0);
                    $this->UpdateVisibility(false);
                    $this->LogMessage("Ausgeschaltet. Letzte Helligkeit " . $current . "% gespeichert.");
                }
                break;

            case 'Brightness':
                $this->SetBrightness((float)$Value);
                $this->SetValue('Brightness', (int)$Value);
                
                if ($Value > 0) {
                    $this->SetValue('Power', true);
                    $this->UpdateVisibility(true);
                    $this->LogMessage("Helligkeit auf " . $Value . "% gesetzt (Gerät AN).");
                } else {
                    $this->SetValue('Power', false);
                    $this->UpdateVisibility(false);
                    $this->LogMessage("Helligkeit auf 0% gesetzt (Gerät AUS).");
                }
                break;

            case 'ActiveProgram':
                $mapRaw = $this->ReadAttributeString('ProgramMap');
                $map = json_decode($mapRaw, true);
                if (is_array($map) && isset($map[(int)$Value])) {
                    $progId = $map[(int)$Value]['id'];
                    $progName = $map[(int)$Value]['name'];
                    $this->SetActiveProgram($progId);
                    $this->SetValue('ActiveProgram', (int)$Value);
                    $this->LogMessage("Programm gewechselt auf: " . $progName);
                } else {
                    $this->LogMessage("Fehler: Programm-Index " . $Value . " nicht gefunden.");
                }
                break;

            default:
                throw new Exception("Invalid Action");
        }
    }

    public function FetchPrograms(): void
    {
        $this->SendJsonCommand(json_encode(['listPrograms' => true]));
    }

    public function Reconnect(): void
    {
        if (!$this->HasActiveParent()) {
            $parentID = $this->GetParentID();
            if ($parentID > 0) {
                // Nur reconnecten, wenn die Instanz grundstzlich "Open" geschaltet ist
                if (IPS_GetProperty($parentID, 'Open')) {
                    $this->LogMessage("Verbindung getrennt. Versuche Reconnect...");
                    @IPS_SetProperty($parentID, 'Open', false);
                    @IPS_ApplyChanges($parentID);
                    @IPS_SetProperty($parentID, 'Open', true);
                    @IPS_ApplyChanges($parentID);
                }
            }
        }
    }

    private function GetParentID(): int
    {
        $instance = @IPS_GetInstance($this->InstanceID);
        return ($instance && isset($instance['ConnectionID'])) ? $instance['ConnectionID'] : 0;
    }

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        
        // WebSocket Client Data ID
        if ($data['DataID'] == '{018EF6B5-AB94-40C6-AA53-46943E824ACF}') {
            $buffer = $data['Buffer'];

            // Prfe auf JSON Text-Frame (Status Updates etc.)
            if (strpos($buffer, '{') === 0) {
                $payload = json_decode($buffer, true);
                if (is_array($payload)) {
                    // Helligkeit
                    if (isset($payload['brightness'])) {
                        $brightness = (int)round((float)$payload['brightness'] * 100.0);
                        if ($brightness != $this->GetValue('Brightness')) {
                            $this->SetValue('Brightness', $brightness);
                            $this->SetValue('Power', $brightness > 0);
                            $this->UpdateVisibility($brightness > 0);
                        }
                    }
                    if (isset($payload['activeProgram']['activeProgramId'])) {
                        $progId = $payload['activeProgram']['activeProgramId'];
                        
                        $mapRaw = $this->ReadAttributeString('ProgramMap');
                        $map = json_decode($mapRaw, true);
                        if (is_array($map)) {
                            foreach ($map as $index => $progData) {
                                if ($progData['id'] === $progId) {
                                    if ($index != $this->GetValue('ActiveProgram')) {
                                        $this->SetValue('ActiveProgram', $index);
                                    }
                                    break;
                                }
                            }
                        }
                    }
                }
                return "";
            }

            // Prfe auf binren listPrograms Frame (0x07)
            if (strlen($buffer) >= 2 && ord($buffer[0]) === 0x07) {
                $flags = ord($buffer[1]);
                $payload = substr($buffer, 2);

                if ($flags & 0x01) { // Start
                    $this->SetBuffer('ProgramListBuffer', '');
                }

                $currentBuffer = $this->GetBuffer('ProgramListBuffer');
                $currentBuffer .= $payload;
                $this->SetBuffer('ProgramListBuffer', $currentBuffer);

                if ($flags & 0x04) { // End
                    $this->ProcessProgramList($currentBuffer);
                    $this->SetBuffer('ProgramListBuffer', '');
                }
            }
        }
        
        return "";
    }

    private function ProcessProgramList($rawList): void
    {
        $lines = explode("\n", trim($rawList));
        $programs = [];
        $index = 0;
        foreach ($lines as $line) {
            $parts = explode("\t", trim($line));
            if (count($parts) >= 2) {
                $id = $parts[0];
                $name = $parts[1];
                if (!empty($id)) {
                    $programs[$index] = ['id' => $id, 'name' => $name];
                    $index++;
                }
            }
        }

                if (count($programs) > 0) {
            $this->WriteAttributeString('ProgramMap', json_encode($programs));

            $profileName = 'Pixelblaze.Program.' . $this->InstanceID;
            if (!IPS_VariableProfileExists($profileName)) {
                IPS_CreateVariableProfile($profileName, 1);
                IPS_SetVariableProfileIcon($profileName, 'Script');
            }
            
            foreach ($programs as $i => $prog) {
                IPS_SetVariableProfileAssociation($profileName, $i, $prog['name'], '', -1);
            }
            
            IPS_SetVariableCustomProfile($this->GetIDForIdent('ActiveProgram'), $profileName);

            $this->LogMessage(count($programs) . " Programme geladen und als Dropdown hinterlegt.", KL_MESSAGE);
        }
    }

    private function SetBrightness(float $percent): void
    {
        // Pixelblaze erwartet Float von 0.0 bis 1.0
        $floatValue = $percent / 100.0;
        if ($floatValue < 0.0) $floatValue = 0.0;
        if ($floatValue > 1.0) $floatValue = 1.0;

        $command = ['brightness' => $floatValue];
        $this->SendWebSocketCommand($command);
    }

    private function SetActiveProgram(string $programId): void
    {
        $command = ['activeProgramId' => $programId];
        $this->SendWebSocketCommand($command);
    }

    public function SendJsonCommand(string $jsonString): void
    {
        $data = json_decode($jsonString, true);
        if ($data) {
            $this->SendWebSocketCommand($data);
        } else {
            $this->LogMessage("SendJsonCommand: Ungültiges JSON Format.");
        }
    }

    private function SendWebSocketCommand(array $payload): void
    {
        if (!$this->HasActiveParent()) {
            $this->LogMessage("Fehler: Kein aktiver WebSocket Client verbunden.");
            return;
        }

        $jsonPayload = json_encode($payload);
        
        $msg = [
            'DataID' => '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}', // WS Text Frame
            'Buffer' => $jsonPayload
        ];

        $this->SendDataToParent(json_encode($msg));
        $this->SendDebug("Transmit", $jsonPayload, 0);
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "NumberSpinner",
                    "name": "AutoReconnectInterval",
                    "caption": "Auto-Reconnect Intervall (Sekunden)"
                }
            ]
        }
    ],
    "actions": [
        {
            "type": "Button",
            "label": "Programme vom Gerät laden",
            "onClick": "PB_FetchPrograms($id);"
        }
    ]
}
EOT;
    }
}

