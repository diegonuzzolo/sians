<?php
require 'config/config.php'; // Connessione PDO in $pdo

function fetchModpacks($limit = 100, $offset = 0) {
    $facets = urlencode(json_encode([
        ["categories:forge"],
        ["project_type:modpack"]
    ]));

    $url = "https://api.modrinth.com/v2/search?game=minecraft&limit=$limit&offset=$offset&facets=$facets";

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20
    ]);
    $response = curl_exec($curl);
    curl_close($curl);

    if (!$response) {
        echo "Errore nel recupero dati.\n";
        return [];
    }

    $json = json_decode($response, true);
    return $json['hits'] ?? [];
}

function insertModpack($pdo, $modpack) {
    $stmt = $pdo->prepare("
        INSERT INTO modpacks (id, slug, title, description, downloads, project_type, categories, game_version, updated)
        VALUES (:id, :slug, :title, :description, :downloads, :project_type, :categories, :game_version, :updated)
        ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            description = VALUES(description),
            downloads = VALUES(downloads),
            categories = VALUES(categories),
            game_version = VALUES(game_version),
            updated = VALUES(updated)
    ");

    $stmt->execute([
        ':id' => $modpack['project_id'],
        ':slug' => $modpack['slug'],
        ':title' => $modpack['title'],
        ':description' => $modpack['description'] ?? '',
        ':downloads' => $modpack['downloads'] ?? 0,
        ':project_type' => $modpack['project_type'],
        ':categories' => implode(',', $modpack['categories'] ?? []),
        ':game_version' => $modpack['versions'][0] ?? '',
        ':updated' => date('Y-m-d H:i:s', strtotime($modpack['date_modified'] ?? 'now')),
    ]);
}

// Ciclo per prendere tutti i modpack paginati
$offset = 0;
$totalFetched = 0;

while (true) {
    echo "Recupero modpack da offset $offset...\n";
    $modpacks = fetchModpacks(100, $offset);

    if (empty($modpacks)) {
        echo "Nessun altro modpack trovato.\n";
        break;
    }

    foreach ($modpacks as $modpack) {
        insertModpack($pdo, $modpack);
        $totalFetched++;
    }

    $offset += 100;
    sleep(1); // evita rate limit
}

echo "Importazione completata. Modpack importati: $totalFetched\n";
