<?php

$apiKey = '$2a$10$yykz2aOhcuZ8rQNQTvOCGO0/sgIdJ7sKUjRqOv0LmllIPEimHh9XC';
$pageSize = 50;
$page = 0;
$maxPages = 1000; // imposta un limite alto, ma lo interrompiamo se non ci sono più dati
$allIds = [];

for ($page = 0; $page < $maxPages; $page++) {
    echo "Scarico pagina $page...\n";

    $url = "https://api.curseforge.com/v1/mods/search?gameId=432&classId=4471&pageSize=$pageSize&page=$page";

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Accept: application/json",
            "x-api-key: $apiKey"
        ]
    ]);

    $response = curl_exec($curl);
    curl_close($curl);

    if (!$response) {
        echo "Errore nella risposta dalla pagina $page\n";
        break;
    }

    $data = json_decode($response, true);

    if (empty($data['data'])) {
        echo "Nessun dato alla pagina $page. Fine.\n";
        break;
    }

    foreach ($data['data'] as $mod) {
        $allIds[] = [
            'id' => $mod['id'],
            'name' => $mod['name']
        ];
        echo "- ID: {$mod['id']} - Nome: {$mod['name']}\n";
    }

    // Optional: dormi 0.5 secondi per evitare rate limit
    usleep(500000);
}

// Salva in un file JSON
file_put_contents('/var/www/html/ids.json', json_encode($allIds, JSON_PRETTY_PRINT));
echo "Salvati " . count($allIds) . " ID in ids.json\n";
