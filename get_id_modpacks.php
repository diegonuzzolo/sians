<?php

$apiKey = '$2a$10$yykz2aOhcuZ8rQNQTvOCGO0/sgIdJ7sKUjRqOv0LmllIPEimHh9XC';
$pageSize = 50;
$maxPages = 1000;
$ids = [];

for ($page = 0; $page < $maxPages; $page++) {
    #echo "Pagina $page...\n";

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
        echo "Errore nella richiesta alla pagina $page\n";
        break;
    }

    $data = json_decode($response, true);

    if (empty($data['data'])) {
        echo "Nessun dato alla pagina $page. Fine.\n";
        break;
    }

    foreach ($data['data'] as $mod) {
        $ids[] = $mod['id'];
        echo "{$mod['id']},\n";
    }

    usleep(500000); // 0.5 secondi per sicurezza (evita rate limit)
}

// Salvataggio array semplice di ID
file_put_contents('ids.json', json_encode($ids, JSON_PRETTY_PRINT));
echo "Totale ID salvati: " . count($ids) . "\n";
