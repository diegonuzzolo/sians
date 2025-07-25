<?php
require_once 'config/config.php';
require_once 'sync_functions.php';

$apiKey = '$2a$10$yykz2aOhcuZ8rQNQTvOCGO0/sgIdJ7sKUjRqOv0LmllIPEimHh9XC';
$projectId = 285109; // es. RLCraft

$endpoint = "https://api.curseforge.com/v1/mods/$projectId/files";

$ch = curl_init($endpoint);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "x-api-key: $apiKey"
]);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    die("❌ Curl error: " . curl_error($ch) . "\n");
}

curl_close($ch);

$data = json_decode($response, true);
if (!$data) {
    die("❌ Risposta API non valida\n");
}

if (!isset($data['data'])) {
    die("❌ Nessun campo 'data' nella risposta\n");
}

// Prendo la prima release (releaseType=1)
foreach ($data['data'] as $entry) {
    if ($entry['releaseType'] == 1) {
     $modpack = [
    'name' => $entry['displayName'],
    'forgeVersion' => 'unknown',
    'filename' => $entry['fileName'],
    'downloadUrl' => $entry['downloadUrl'],
    'minecraftVersion' => $entry['gameVersions'][0] ?? '',
    'type' => 1,
    'installMethod' => 1,
    'latest' => isset($entry['isLatest']) ? (int)$entry['isLatest'] : 0,
    'recommended' => isset($entry['isServerPack']) ? (int)$entry['isServerPack'] : 0,
    'approved' => 1,
    'dateModified' => $entry['fileDate']
];



        syncModpack($modpack);
        break;
    }
}
