<?php

$apiKey = '$2a$10$yykz2aOhcuZ8rQNQTvOCGO0/sgIdJ7sKUjRqOv0LmllIPEimHh9XC'; // <-- Inserisci qui la tua API Key CurseForge
$outputFile = 'mod_list.json';

$index = 0;
$pageSize = 50;
$allMods = [];

do {
    $url = "https://api.curseforge.com/v1/mods/search?gameId=432&pageSize=$pageSize&index=$index";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "x-api-key: $apiKey"
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) {
        die("❌ Errore nella richiesta API\n");
    }

    $data = json_decode($response, true);
    $mods = $data['data'] ?? [];

    foreach ($mods as $mod) {
        $allMods[] = [
            'id' => $mod['id'],
            'name' => $mod['name']
        ];
    }

    echo "Scaricati " . count($mods) . " mod (index: $index)\n";

    $index += $pageSize;
    $hasMore = count($mods) === $pageSize;

    // Optional: sleep per non sovraccaricare API (rate limit)
    usleep(200000); // 0.2 secondi

} while ($hasMore);

// Salva tutto in un file JSON
file_put_contents($outputFile, json_encode($allMods, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "✅ Mod salvate in $outputFile\n";
