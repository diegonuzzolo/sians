<?php
require __DIR__.'/../config/config.php'; // Carica connessione DB

// Token (se usi API con autenticazione, altrimenti ometti)
$MODRINTH_TOKEN = ''; // Inserisci qui se serve

// Funzione per fare richiesta GET all'API Modrinth
function modrinthApiRequest(string $url): ?array {
    $opts = [
        "http" => [
            "method" => "GET",
            "header" => "Accept: application/json\r\n"
            // Se serve token: "Authorization: Bearer $token\r\n"
        ]
    ];
    $context = stream_context_create($opts);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) return null;
    return json_decode($response, true);
}

// Recupera plugin (project_type=plugin) con loader forge (o quello che serve)
$page = 0;
$pageSize = 50;
$totalProcessed = 0;

do {
    $offset = $page * $pageSize;

    // Facets AND: project_type:plugin AND categories:forge (modifica se vuoi)
    $facets = urlencode('[["project_type:plugin"],["categories:forge"]]');

    $url = "https://api.modrinth.com/v2/search?facets=$facets&index=downloads&limit=$pageSize&offset=$offset";

    $data = modrinthApiRequest($url);

    if (!$data || !isset($data['hits'])) break;

    foreach ($data['hits'] as $plugin) {
        $id = $plugin['project_id'] ?? null;
        $name = $plugin['title'] ?? $plugin['slug'];
        $description = $plugin['description'] ?? '';
        $slug = $plugin['slug'] ?? '';
        $categories = isset($plugin['categories']) ? implode(',', $plugin['categories']) : '';
        $updated_at = isset($plugin['updated']) ? date('Y-m-d H:i:s', strtotime($plugin['updated'])) : null;
        $created_at = isset($plugin['created']) ? date('Y-m-d H:i:s', strtotime($plugin['created'])) : null;
        $loader_type = '';  // Modrinth non ha loader_type a progetto, si ricava da versioni
        $version = '';      // Versione da ricavare da versioni
        $game_version = ''; // Idem
        $author = $plugin['author'] ?? '';

        // Prendo info versione più recente
        $versions_url = "https://api.modrinth.com/v2/project/$id/version";
        $versions_data = modrinthApiRequest($versions_url);
        if ($versions_data && is_array($versions_data) && count($versions_data) > 0) {
            $latest_version = $versions_data[0];
            $version = $latest_version['version_number'] ?? '';
            $game_version = isset($latest_version['game_versions']) ? implode(',', $latest_version['game_versions']) : '';
            $loader_type = isset($latest_version['loaders']) ? implode(',', $latest_version['loaders']) : '';
            $download_url = $latest_version['files'][0]['url'] ?? '';
        } else {
            $download_url = '';
        }

            if (empty($id)) {
        echo "❌ Plugin senza ID, salto.\n";
        continue;
    }

        // Controllo esistenza record
        $stmt = $pdo->prepare("SELECT id FROM plugins WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() > 0) {
            // Update
            $stmt = $pdo->prepare("UPDATE plugins SET
                name = ?, description = ?, version = ?, game_version = ?, slug = ?, categories = ?, loader_type = ?, download_url = ?, author = ?, updated_at = ?
                WHERE id = ?");
            $stmt->execute([
                $name, $description, $version, $game_version, $slug, $categories, $loader_type, $download_url, $author, $updated_at, $id
            ]);
            echo "Aggiornato plugin: $name ($version)\n";
        } else {
            // Insert
            $stmt = $pdo->prepare("INSERT INTO plugins (id, name, description, version, game_version, slug, categories, loader_type, download_url, author, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $id, $name, $description, $version, $game_version, $slug, $categories, $loader_type, $download_url, $author, $created_at, $updated_at
            ]);
            echo "Inserito plugin: $name ($version)\n";
        }

        $totalProcessed++;
    }

    $page++;

} while ($page * $pageSize < ($data['total_hits'] ?? 0));

echo "Totale plugin sincronizzati: $totalProcessed\n";
