<?php
require __DIR__.'/../config/config.php'; // Connessione DB

$page = 0;
$pageSize = 100;
$totalProcessed = 0;


function modrinthApiRequest(string $url): ?array {
    $opts = [
        "http" => [
            "method" => "GET",
            "header" => "Accept: application/json\r\n"
        ]
    ];
    $context = stream_context_create($opts);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) return null;
    return json_decode($response, true);
}

do {
    $offset = $page * $pageSize;

    // Solo modpack
    $facets = urlencode('[["project_type:modpack"]]');
    $url = "https://api.modrinth.com/v2/search?facets=$facets&index=downloads&limit=$pageSize&offset=$offset";

    $data = modrinthApiRequest($url);
    if (!$data || !isset($data['hits']) || count($data['hits']) === 0) break;

    foreach ($data['hits'] as $pack) {
        $projectId = $pack['project_id'] ?? null;
        if (empty($projectId)) {
            echo "❌ Modpack senza project_id, salto.\n";
            continue;
        }

        // Recupera versione compatibile con forge
        $versions_url = "https://api.modrinth.com/v2/project/$projectId/version";
        $game_version = '';

  

        if (!$foundForge) {
            continue;
        }

        $title = $pack['title'] ?? $pack['slug'] ?? '';
        $description = $pack['description'] ?? '';
        $slug = $pack['slug'] ?? '';
        $categories = isset($pack['categories']) && is_array($pack['categories']) ? implode(',', $pack['categories']) : '';
        $updated = isset($pack['updated']) ? date('Y-m-d H:i:s', strtotime($pack['updated'])) : null;
        $downloads = $pack['downloads'] ?? 0;
        $projectType = $pack['project_type'] ?? 'modpack';

        // Controlla se già presente (usa project_id)
        $stmt = $pdo->prepare("SELECT project_id FROM modpacks WHERE project_id = ?");
        $stmt->execute([$projectId]);

        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("UPDATE modpacks SET
                title = ?, description = ?, game_version = ?, slug = ?, categories = ?, updated = ?, downloads = ?, project_type = ?
                WHERE project_id = ?");
            $stmt->execute([
                $title, $description, $game_version, $slug, $categories, $updated, $downloads, $projectType, $projectId
            ]);
            echo "🔁 Aggiornato modpack: $title ($game_version)\n";
        } else {
            $stmt = $pdo->prepare("INSERT INTO modpacks (project_id, title, game_version, slug, description, categories, updated, downloads, project_type)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $projectId,
                $title,
                $game_version,
                $slug,
                $description,
                $categories,
                $updated,
                $downloads,
                $projectType
            ]);
            echo "✅ Inserito modpack: $title ($game_version) \n";
        }

        $totalProcessed++;
    }

    $page++;
} while (true);

echo "Totale modpack Forge sincronizzati: $totalProcessed\n";
