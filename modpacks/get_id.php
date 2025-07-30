<?php

$modrinthApiToken = getenv('MODRINTH_API_TOKEN');

$headers = [
    'Content-Type: application/json',
    'User-Agent: YourAppName/1.0 (your_email@example.com)' // Sostituisci con informazioni reali
];

if (!empty($modrinthApiToken)) {
    $headers[] = 'Authorization: Bearer ' . $modrinthApiToken;
}

$baseUrl = "https://api.modrinth.com/v2/search";
$forgeModpackIds = [];
$limit = 100;
$offset = 0;

echo "Ricerca di modpack Forge su Modrinth...\n";

do {
    $queryParams = [
        'query' => '',
        'game' => 'minecraft',
        'project_type' => 'modpack',
        'limit' => $limit,
        'offset' => $offset,
        'index' => 'relevance'
    ];

    $url = $baseUrl . '?' . http_build_query($queryParams);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $data = json_decode($response, true);

        if (empty($data['hits'])) {
            break;
        }

        foreach ($data['hits'] as $modpack) {
            if (isset($modpack['loaders']) && in_array('forge', $modpack['loaders'])) {
                $forgeModpackIds[] = $modpack['project_id'];
            }
        }

        $offset += $limit;
        
    } else {
        echo "Errore nella richiesta HTTP: " . $httpCode . "\n";
        echo "Risposta: " . $response . "\n";
        break;
    }

} while (true);

echo "\n--- ID dei Modpack Forge Trovati ---\n";
if (empty($forgeModpackIds)) {
    echo "Nessun modpack Forge trovato.\n";
} else {
    foreach ($forgeModpackIds as $id) {
        echo $id . "\n";
    }
    echo "\nTotale modpack Forge trovati: " . count($forgeModpackIds) . "\n";
}

?>