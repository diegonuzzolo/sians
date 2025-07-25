<?php
require_once 'config/config.php'; // connessione PDO
require_once 'sync_functions.php'; // contiene la funzione syncModpack()

$apiKey = '$2a$10$yykz2aOhcuZ8rQNQTvOCGO0/sgIdJ7sKUjRqOv0LmllIPEimHh9XC';
$projectId = 323046; // es. RLCraft
$endpoint = "https://api.curseforge.com/v1/mods/$projectId/files";

$ch = curl_init($endpoint);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "x-api-key: $apiKey"
]);

$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);

if (!isset($data['data'])) {
    die("❌ Errore nella risposta CurseForge\n");
}

// Trova l'ultima versione "release"
foreach ($data['data'] as $entry) {
    if ($entry['releaseType'] == 1) { // 1 = Release, 2 = Beta, 3 = Alpha
        $modpack = [
            'gameVersionId' => 0,
            'minecraftGameVersionId' => 0,
            'forgeVersion' => 'unknown',
            'name' => $entry['displayName'],
            'type' => 1,
            'downloadUrl' => $entry['downloadUrl'],
            'filename' => $entry['fileName'],
            'installMethod' => 1,
            'latest' => $entry['isLatest'],
            'recommended' => $entry['isServerPack'],
            'approved' => true,
            'dateModified' => $entry['fileDate'],
            'mavenVersionString' => '',
            'versionJson' => '{}',
            'librariesInstallLocation' => '',
            'minecraftVersion' => $entry['gameVersions'][0] ?? '',
            'additionalFilesJson' => '[]',
            'modLoaderGameVersionId' => 0,
            'modLoaderGameVersionTypeId' => 0,
            'modLoaderGameVersionStatus' => 1,
            'modLoaderGameVersionTypeStatus' => 1,
            'mcGameVersionId' => 0,
            'mcGameVersionTypeId' => 0,
            'mcGameVersionStatus' => 1,
            'mcGameVersionTypeStatus' => 1,
            'installProfileJson' => '{}'
        ];

        syncModpack($modpack);
        break;
    }
}
