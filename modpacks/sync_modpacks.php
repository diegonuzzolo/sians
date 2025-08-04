<?php
require __DIR__ ."/../config/config.php";
function modrinthApiRequest($url)
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'YourAppName/1.0 (by you)',
    ]);
    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response, true);
}

$page = 0;
$pageSize = 100;

do {
    $offset = $page * $pageSize;

    $facets = urlencode(json_encode([
        ["project_type:modpack"],
        ["client_side:unsupported"],
        ["categories:forge", "categories:fabric"]
    ]));

    $url = "https://api.modrinth.com/v2/search?facets=$facets&limit=$pageSize&offset=$offset";
    $data = modrinthApiRequest($url);

    if (!$data || !isset($data['hits']) || count($data['hits']) === 0) {
        echo "✅ Fine dei risultati.\n";
        break;
    }

    foreach ($data['hits'] as $modpack) {
        $project_id = $modpack['project_id'] ?? '';
        $game_version = implode(',', $modpack['game_versions'] ?? []);
        $slug = $modpack['slug'] ?? '';
        $title = $modpack['title'] ?? '';
        $downloads = $modpack['downloads'] ?? 0;
        $description = $modpack['description'] ?? '';
        $categories = implode(',', $modpack['categories'] ?? []);
        $updated = $modpack['updated'] ?? '';
        $project_type = $modpack['project_type'] ?? '';

        // 🔄 Recupera l'ultima versione
        $versions_url = "https://api.modrinth.com/v2/project/$project_id/version";
        $versions = modrinthApiRequest($versions_url);

        $version_id = null;
        $download_url = null;

        if (is_array($versions) && count($versions) > 0) {
            $latest_version = $versions[0];
            $version_id = $latest_version['id'] ?? null;

            // Trova il file .mrpack
            foreach ($latest_version['files'] ?? [] as $file) {
                if (($file['url'] ?? '') && preg_match('/\.mrpack$/', $file['filename'])) {
                    $download_url = $file['url'];
                    break;
                }
            }
        }

        echo "• [$title] ($slug)\n";
        echo "  - Version ID: $version_id\n";
        echo "  - Download URL: $download_url\n\n";

        // Salvataggio nel DB (solo se vuoi):
        
        $stmt = $pdo->prepare("REPLACE INTO modpacks 
            (project_id, game_version, slug, title, downloads, description, categories, updated, project_type, version_id, download_url)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$project_id, $game_version, $slug, $title, $downloads, $description, $categories, $updated, $project_type, $version_id, $download_url]);
        
    }

    $page++;
    sleep(1);
} while (true);
