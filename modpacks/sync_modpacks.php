<?php
// sync_modpacks.php
// Sincronizza modpack Fabric da Modrinth e aggiorna DB

require __DIR__.'/../config/config.php';  // connessione DB

// Config
$modrinthApiUrl = 'https://api.modrinth.com/v2/search';
$perPage = 50;  // quanti modpack prendere per pagina
$page = 1;

// Connessione DB

// Funzione per chiamata API Modrinth

function fetchModpacksFromModrinth($page, $perPage) {
    $url = "https://api.modrinth.com/v2/search";

    // Parametri di ricerca:
    // facciamo filtro per modpack Fabric (esempio tag: 'fabric-modpack' oppure 'modpack')
    $postData = [
        "facets" => [["project_type:modpack"], ["categories:fabric"]],
        "limit" => $perPage,
        "offset" => ($page - 1) * $perPage,
        "sort" => "downloads"
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        echo "Errore curl: " . curl_error($ch);
        return null;
    }
    curl_close($ch);

    return json_decode($response, true);
}

// Funzione per inserire o aggiornare modpack in DB
function upsertModpack($pdo, $modpack) {
    // Campi di esempio da Modrinth:
    // id, slug, title, description, categories (array), versions (array di version id)
    // Qui salviamo solo i dati base e il JSON versione completa come stringa
    
    $sql = "INSERT INTO modpacks (id, slug, title, description, categories, versions_json, last_updated)
            VALUES (:id, :slug, :title, :description, :categories, :versions_json, NOW())
            ON DUPLICATE KEY UPDATE
            slug = VALUES(slug),
            title = VALUES(title),
            description = VALUES(description),
            categories = VALUES(categories),
            versions_json = VALUES(versions_json),
            last_updated = NOW()";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id' => $modpack['id'],
        ':slug' => $modpack['slug'],
        ':title' => $modpack['title'],
        ':description' => $modpack['description'] ?? '',
        ':categories' => json_encode($modpack['categories'] ?? []),
        ':versions_json' => json_encode($modpack['versions'] ?? [])
    ]);
}

// Start sync
echo "Inizio sincronizzazione modpack Fabric da Modrinth...\n";

$page = 1;
while (true) {
    $data = fetchModpacksFromModrinth($page, $perPage);
    if (!$data || empty($data['hits'])) {
        echo "Fine risultati o errore.\n";
        break;
    }

    foreach ($data['hits'] as $modpack) {
        upsertModpack($pdo, $modpack);
        echo "Aggiornato modpack: {$modpack['title']} ({$modpack['id']})\n";
    }

    // Se meno risultati del perPage, significa ultima pagina
    if (count($data['hits']) < $perPage) {
        break;
    }

    $page++;
}

echo "Sincronizzazione completata.\n";
