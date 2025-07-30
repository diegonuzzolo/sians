<?php
function fetchAllModrinthModpacks($loader = 'forge') {
    $allModpacks = [];
    $offset = 0;
    $limit = 50;

    do {
        $result = fetchModrinthModpacks($offset, $limit, $loader);
        if (!$result || !isset($result['hits'])) {
            echo "Errore o nessun risultato alla pagina offset $offset.\n";
            break;
        }

        foreach ($result['hits'] as $modpack) {
            $allModpacks[] = [
                'id' => $modpack['id'],
                'slug' => $modpack['slug'],
                'title' => $modpack['title'] ?? '',
                'downloads' => $modpack['downloads'] ?? 0
            ];
        }

        $offset += $limit;
    } while (count($result['hits']) === $limit); // finché restituisce pagina piena

    return $allModpacks;
}

function fetchModrinthModpacks($offset, $limit, $loader) {
    $url = "https://api.modrinth.com/v2/search";

    $postData = [
        "query" => "",
        "facets" => [
            ["categories:modpacks"],
            ["project_type:modpack"],
            ["loaders:$loader"]
        ],
        "index" => "downloads",
        "offset" => $offset,
        "limit" => $limit
    ];

    $payload = json_encode($postData);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

// Esecuzione
$loader = 'fabric'; // puoi usare anche 'forge', 'neoforge', 'quilt' ecc.
$modpacks = fetchAllModrinthModpacks($loader);

// Stampa risultati
foreach ($modpacks as $m) {
    echo "ID: {$m['id']} | Slug: {$m['slug']} | Title: {$m['title']} | Downloads: {$m['downloads']}\n";
}

// Facoltativo: salva su file JSON
file_put_contents("modrinth_modpacks_{$loader}.json", json_encode($modpacks, JSON_PRETTY_PRINT));
echo "\nTotale modpack trovati: " . count($modpacks) . "\n";
