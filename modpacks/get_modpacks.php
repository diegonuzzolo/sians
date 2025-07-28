<?php
// sync_modpacks_modrinth.php
// Script per scaricare modpack Fabric da Modrinth e salvare modpacks.json

$apiUrl = 'https://api.modrinth.com/v2/search?facets=%5B%5B%22project_type:modpack%22%5D,%5B%22categories:fabric%22%5D%5D&limit=500';

echo "📦 Recupero modpack Fabric da Modrinth...\n";

// Effettua la richiesta HTTP GET
$response = file_get_contents($apiUrl);

if ($response === false) {
    echo "❌ Errore nella richiesta API.\n";
    exit(1);
}

// Decodifica JSON
$data = json_decode($response, true);
if ($data === null) {
    echo "❌ Errore nella decodifica JSON.\n";
    exit(1);
}

$modpacks = [];

if (!isset($data['hits']) || !is_array($data['hits'])) {
    echo "❌ Risposta API non valida o nessun modpack trovato.\n";
    exit(1);
}

// Estrai slug e nome
foreach ($data['hits'] as $project) {
    $modpacks[] = [
        'slug' => $project['slug'],
        'name' => $project['title'],
        'minecraftVersion' => isset($project['game_version']) ? implode(", ", $project['game_version']) : ''
    ];
}

// Salva in file JSON
$filePath = __DIR__ . '/modpacks.json';
file_put_contents($filePath, json_encode($modpacks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "✅ Salvati " . count($modpacks) . " modpack Fabric in $filePath\n";
