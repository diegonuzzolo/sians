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
        $id = $pack['project_id'] ?? $pack['slug'];
        if (empty($id)) {
            echo "❌ Modpack senza ID, salto.\n";
            continue;
        }

        $title = $pack['title'] ?? $pack['slug'];
        $description = $pack['description'] ?? '';
        $slug = $pack['slug'] ?? '';
        $categories = isset($pack['categories']) ? implode(',', $pack['categories']) : '';
        $updated = isset($pack['updated']) ? date('Y-m-d H:i:s', strtotime($pack['updated'])) : null;
        $downloads = $pack['downloads'] ?? 0;
        $project_type = $pack['project_type'] ?? 'modpack';

        // Recupera versione compatibile con forge
        $versions_url = "https://api.modrinth.com/v2/project/$id/version";
        $versions = modrinthApiRequest($versions_url);
        $game_version = '';
        $forge_version = '';

        if ($versions) {
            foreach ($versions as $v) {
                if (in_array('forge', $v['loaders'])) {
                    $game_version = $v['game_versions'][0] ?? '';
                    $forge_version = $v['version_number'] ?? '';
                    break;
                }
            }
        }

        // Inserimento o aggiornamento
        $stmt = $pdo->prepare("SELECT id FROM modpacks WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("UPDATE modpacks SET
                title = ?, description = ?, game_version = ?, slug = ?, categories = ?, updated = ?, downloads = ?, project_type = ?, forge_version = ?
                WHERE id = ?");
            $stmt->execute([
                $title, $description, $game_version, $slug, $categories, $updated, $downloads, $project_type, $forge_version, $id
            ]);
            echo "🔁 Aggiornato modpack: $title ($game_version - forge: $forge_version)\n";
        } else {
            $stmt = $pdo->prepare("INSERT INTO modpacks (id, title, description, game_version, slug, categories, updated, downloads, project_type, forge_version)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $id, $title, $description, $game_version, $slug, $categories, $updated, $downloads, $project_type, $forge_version
            ]);
            echo "✅ Inserito modpack: $title ($game_version - forge: $forge_version)\n";
        }

        $totalProcessed++;
    }

    $page++;
} while (true);

echo "Totale modpack sincronizzati: $totalProcessed\n";
