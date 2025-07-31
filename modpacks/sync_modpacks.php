<?php
require __DIR__.'/../config/config.php'; // Connessione PDO in $pdo

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

function insertOrUpdateModpack($pdo, $modpack) {
    // Prima controllo se esiste già
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM modpacks WHERE project_id = ?");
    $stmt->execute([$modpack['project_id']]);
    $exists = $stmt->fetchColumn() > 0;

    // Prepara i dati da salvare
    $description = $modpack['description'] ?? '';
    $downloads = $modpack['downloads'] ?? 0;
    $project_type = $modpack['project_type'] ?? 'modpack';
    $categories = isset($modpack['categories']) ? implode(',', $modpack['categories']) : '';
    $game_version = '';
    if (!empty($modpack['versions']) && is_array($modpack['versions'])) {
        $game_version = $modpack['versions'][0] ?? '';
    }
    $updated_raw = $modpack['date_modified'] ?? null;
    $updated = $updated_raw ? date('Y-m-d H:i:s', strtotime($updated_raw)) : date('Y-m-d H:i:s');

    if ($exists) {
        $stmt = $pdo->prepare("
            UPDATE modpacks SET
                slug = :slug,
                title = :title,
                description = :description,
                downloads = :downloads,
                project_type = :project_type,
                categories = :categories,
                game_version = :game_version,
                updated = :updated
            WHERE project_id = :project_id
        ");
        $stmt->execute([
            ':slug' => $modpack['slug'],
            ':title' => $modpack['title'],
            ':description' => $description,
            ':downloads' => $downloads,
            ':project_type' => $project_type,
            ':categories' => $categories,
            ':game_version' => $game_version,
            ':updated' => $updated,
            ':project_id' => $modpack['project_id'],
        ]);
        echo "🔄 Aggiornato: {$modpack['title']}\n";
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO modpacks (project_id, slug, title, description, downloads, project_type, categories, game_version, updated)
            VALUES (:project_id, :slug, :title, :description, :downloads, :project_type, :categories, :game_version, :updated)
        ");
        $stmt->execute([
            ':project_id' => $modpack['project_id'],
            ':slug' => $modpack['slug'],
            ':title' => $modpack['title'],
            ':description' => $description,
            ':downloads' => $downloads,
            ':project_type' => $project_type,
            ':categories' => $categories,
            ':game_version' => $game_version,
            ':updated' => $updated,
        ]);
        echo "✅ Inserito: {$modpack['title']}\n";
    }
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
        insertOrUpdateModpack($pdo, $modpack);
        $totalFetched++;
    }

    $offset += 100;
    sleep(1); // evita rate limit
}

echo "Importazione completata. Modpack importati/aggiornati: $totalFetched\n";
