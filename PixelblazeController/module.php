<?php

declare(strict_types=1);

class PixelblazeController extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Fordere WebSocket Client als Parent an
        $this->RequireParent("{D68FD31F-0E90-7019-F16C-1949BD3079EF}");

        // Properties
        $this->RegisterPropertyInteger('AutoReconnectInterval', 30);

        // Internes Attribut für die letzte Helligkeit vor dem Ausschalten
        $this->RegisterAttributeFloat('LastBrightness', 50.0);

        // Variablen
        $this->RegisterVariableBoolean('Power', 'Status', '~Switch', 10);
        $this->EnableAction('Power');

        $this->RegisterVariableFloat('Brightness', 'Helligkeit', '~Intensity.100', 20);
        $this->EnableAction('Brightness');

        $this->RegisterVariableString('ActiveProgramID', 'Programm ID', '', 30);
        $this->EnableAction('ActiveProgramID');

        // Timer für Auto-Reconnect
        $this->RegisterTimer('ReconnectTimer', 0, 'PB_Reconnect($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $interval = $this->ReadPropertyInteger('AutoReconnectInterval');
        $this->SetTimerInterval('ReconnectTimer', $interval * 1000);

        if (function_exists('IPS_SetVariableCustomPresentation')) {
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

            IPS_SetVariableCustomPresentation($this->GetIDForIdent('ActiveProgramID'), [
                'ICON' => 'Script'
            ]);
        }
    }

    protected function LogMessage($Message, $KL_MESSAGE = KL_MESSAGE)
    {
        IPS_LogMessage('SmartVillaKunterbunt', 'Pixelblaze: ' . $Message);
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Power':
                if ($Value) {
                    // Einschalten -> Letzte Helligkeit wiederherstellen
                    $brightness = $this->ReadAttributeFloat('LastBrightness');
                    if ($brightness <= 0) {
                        $brightness = 100.0;
                    }
                    $this->SetBrightness($brightness);
                    $this->SetValue('Power', true);
                    $this->SetValue('Brightness', $brightness);
                    $this->LogMessage("Angeschaltet mit Helligkeit: " . $brightness . "%");
                } else {
                    // Ausschalten -> Aktuelle Helligkeit speichern, dann auf 0 setzen
                    $current = $this->GetValue('Brightness');
                    if ($current > 0) {
                        $this->WriteAttributeFloat('LastBrightness', $current);
                    }
                    $this->SetBrightness(0.0);
                    $this->SetValue('Power', false);
                    $this->SetValue('Brightness', 0.0);
                    $this->LogMessage("Ausgeschaltet. Letzte Helligkeit " . $current . "% gespeichert.");
                }
                break;

            case 'Brightness':
                $this->SetBrightness((float)$Value);
                $this->SetValue('Brightness', (float)$Value);
                
                if ($Value > 0) {
                    $this->SetValue('Power', true);
                    $this->LogMessage("Helligkeit auf " . $Value . "% gesetzt (Gerät AN).");
                } else {
                    $this->SetValue('Power', false);
                    $this->LogMessage("Helligkeit auf 0% gesetzt (Gerät AUS).");
                }
                break;

            case 'ActiveProgramID':
                $this->SetActiveProgram((string)$Value);
                $this->SetValue('ActiveProgramID', (string)$Value);
                $this->LogMessage("Programm gewechselt auf ID: " . $Value);
                break;

            default:
                throw new Exception("Invalid Action");
        }
    }

    public function FetchPrograms()
    {
        $this->SendJsonCommand(json_encode(['listPrograms' => true]));
    }

    public function Reconnect()
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

    private function GetParentID()
    {
        $instance = @IPS_GetInstance($this->InstanceID);
        return ($instance && isset($instance['ConnectionID'])) ? $instance['ConnectionID'] : 0;
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        
        // WebSocket Client Data ID
        if ($data['DataID'] == '{018EF6B5-AB94-40C6-AA53-46943E824ACF}') {
            $buffer = $data['Buffer'];

            // Prfe auf JSON Text-Frame (Status Updates etc.)
            if (strpos($buffer, '{') === 0) {
                $payload = json_decode($buffer, true);
                if (is_array($payload)) {
                    if (isset($payload['brightness'])) {
                        $brightness = (float)$payload['brightness'] * 100.0;
                        if ($brightness != $this->GetValue('Brightness')) {
                            $this->SetValue('Brightness', $brightness);
                            $this->SetValue('Power', $brightness > 0);
                        }
                    }
                    if (isset($payload['activeProgram']['activeProgramId'])) {
                        $progId = $payload['activeProgram']['activeProgramId'];
                        if ($progId != $this->GetValue('ActiveProgramID')) {
                            $this->SetValue('ActiveProgramID', $progId);
                        }
                    }
                }
                return;
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
    }

    private function ProcessProgramList($rawList)
    {
        $lines = explode("\n", trim($rawList));
        $programs = [];
        foreach ($lines as $line) {
            $parts = explode("\t", trim($line));
            if (count($parts) >= 2) {
                $id = $parts[0];
                $name = $parts[1];
                if (!empty($id)) {
                    $programs[$id] = $name;
                }
            }
        }

        if (count($programs) > 0) {
            $profileName = "Pixelblaze.Program." . $this->InstanceID;
            
            if (!IPS_VariableProfileExists($profileName)) {
                IPS_CreateVariableProfile($profileName, 3); // 3 = String
                IPS_SetVariableProfileIcon($profileName, "Script");
            }

            // Alle alten Assoziationen lschen
            $oldProfile = IPS_GetVariableProfile($profileName);
            foreach ($oldProfile['Associations'] as $asc) {
                IPS_SetVariableProfileAssociation($profileName, $asc['Value'], "", "", -1);
            }

            // Neue Assoziationen hinzufgen
            foreach ($programs as $id => $name) {
                IPS_SetVariableProfileAssociation($profileName, $id, $name, "", -1);
            }

            $varID = $this->GetIDForIdent('ActiveProgramID');
            if ($varID) {
                IPS_SetVariableCustomProfile($varID, $profileName);
            }

            $this->LogMessage(count($programs) . " Programme geladen und als Dropdown hinterlegt.");
        }
    }

    private function SetBrightness(float $percent)
    {
        // Pixelblaze erwartet Float von 0.0 bis 1.0
        $floatValue = $percent / 100.0;
        if ($floatValue < 0.0) $floatValue = 0.0;
        if ($floatValue > 1.0) $floatValue = 1.0;

        $command = ['brightness' => $floatValue];
        $this->SendWebSocketCommand($command);
    }

    private function SetActiveProgram(string $programId)
    {
        $command = ['activeProgramId' => $programId];
        $this->SendWebSocketCommand($command);
    }

    public function SendJsonCommand(string $jsonString)
    {
        $data = json_decode($jsonString, true);
        if ($data) {
            $this->SendWebSocketCommand($data);
        } else {
            $this->LogMessage("SendJsonCommand: Ungültiges JSON Format.");
        }
    }

    private function SendWebSocketCommand(array $payload)
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
}
