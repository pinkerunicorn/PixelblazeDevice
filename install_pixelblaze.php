<?php

$moduleID = "{9E05AE57-F71D-457A-AB30-A8FE3A086782}";

echo "1. Lade Modul neu...\n";
if (IPS_ModuleExists($moduleID)) {
    IPS_ReloadModule($moduleID);
    echo "Modul erfolgreich neu geladen!\n";
} else {
    echo "Modul ist nicht im System registriert. Bitte Symcon Dienst neu starten.\n";
    return;
}

echo "2. Erstelle Instanz...\n";
$instID = IPS_CreateInstance($moduleID);
IPS_SetName($instID, "Pixelblaze");
echo "Instanz angelegt: " . $instID . "\n";

echo "3. Prüfe Verbindung...\n";
$parentID = IPS_GetInstance($instID)['ConnectionID'];
if ($parentID > 0) {
    echo "Gateway erfolgreich verbunden! (ID: $parentID)\n";
    IPS_SetProperty($parentID, "URL", "ws://10.1.30.100:81");
    IPS_ApplyChanges($parentID);
    echo "IP 10.1.30.100:81 am Gateway eingetragen.\n";
} else {
    echo "FEHLER: Gateway konnte nicht erstellt werden!\n";
}

echo "\nFertig!\n";
