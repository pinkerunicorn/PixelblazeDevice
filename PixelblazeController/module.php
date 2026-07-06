<?php

declare(strict_types=1);

class PixelblazeController extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Fordere WebSocket Client als Parent an
        $this->RequireParent("{D68FD31F-0E90-7019-F16C-1949BD3079EF}");

        // Internes Attribut für die letzte Helligkeit vor dem Ausschalten
        $this->RegisterAttributeFloat('LastBrightness', 50.0);

        // Variablen
        $this->RegisterVariableBoolean('Power', 'Status', '~Switch', 10);
        $this->EnableAction('Power');

        $this->RegisterVariableFloat('Brightness', 'Helligkeit', '~Intensity.100', 20);
        $this->EnableAction('Brightness');

        $this->RegisterVariableString('ActiveProgramID', 'Programm ID', '', 30);
        $this->EnableAction('ActiveProgramID');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

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

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        
        // WebSocket Client Data ID für Text-Frames
        if ($data['DataID'] == '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}') {
            $payload = json_decode($data['Buffer'], true);

            if (is_array($payload)) {
                // Helligkeit vom Pixelblaze empfangen
                if (isset($payload['brightness'])) {
                    $brightness = (float)$payload['brightness'] * 100.0;
                    if ($brightness != $this->GetValue('Brightness')) {
                        $this->SetValue('Brightness', $brightness);
                        $this->SetValue('Power', $brightness > 0);
                    }
                }

                // Aktives Programm empfangen
                if (isset($payload['activeProgram']['activeProgramId'])) {
                    $progId = $payload['activeProgram']['activeProgramId'];
                    if ($progId != $this->GetValue('ActiveProgramID')) {
                        $this->SetValue('ActiveProgramID', $progId);
                    }
                }
            }
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
