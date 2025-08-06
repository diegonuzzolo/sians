<?php
require __DIR__.'/../config/config.php';

$page = 0;
$pageSize = 100;
$totalProcessed = 0;
$tmpDir = sys_get_temp_dir();

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

function isModpackServerSideCompatible(string $downloadUrl): bool {
    global $tmpDir;

    $tmpFile = tempnam($tmpDir, 'modpack_') . '.mrpack';
    $tmpExtract = $tmpFile . '_extracted';

    // Scarica il file
    if (!@copy($downloadUrl, $tmpFile)) {
        echo "⚠️ Impossibile scaricare il file $downloadUrl\n";
        return false;
    }

    mkdir($tmpExtract);
    $zip = new ZipArchive();
    if ($zip->open($tmpFile) === true) {
        $zip->extractTo($tmpExtract);
        $zip->close();
    } else {
        echo "⚠️ Errore apertura ZIP $tmpFile\n";
        return false;
    }

    $indexPath = "$tmpExtract/modrinth.index.json";
    if (!file_exists($indexPath)) {
        echo "⚠️ modrinth.index.json non trovato nel pacchetto\n";
        return false;
    }

    $index = json_decode(file_get_contents($indexPath), true);
    if (!isset($index['files'])) return false;

    foreach ($index['files'] as $file) {
        if (isset($file['project_id'])) {
            $modInfo = modrinthApiRequest("https://api.modrinth.com/v2/project/{$file['project_id']}");
            if (!$modInfo) continue;
            if (($modInfo['server_side'] ?? 'unsupported') === 'unsupported') {
                echo "⛔ Mod {$modInfo['title']} non compatibile lato server.\n";
                return false;
            }
        }
    }

    // Pulizia
    @unlink($tmpFile);
    exec("rm -rf " . escapeshellarg($tmpExtract));

    return true;
}

// 🔍 Cerca solo modpack Forge (senza filtro client_side)
$facets = urlencode(json_encode([
    ["project_type:modpack"],
    ["categories:forge"]
]));

do {
    $offset = $page * $pageSize;
    $url = "https://api.modrinth.com/v2/search?facets=$facets&index=downloads&limit=$pageSize&offset=$offset";
    $data = modrinthApiRequest($url);
    if (!$data || !isset($data['hits']) || count($data['hits']) === 0) break;

    foreach ($data['hits'] as $modpack) {
        $id = $modpack['project_id'] ?? null;
        if (empty($id)) continue;

        $name = $modpack['title'] ?? $modpack['slug'];
        $slug = $modpack['slug'] ?? '';
        $description = $modpack['description'] ?? '';
        $categories = isset($modpack['categories']) ? implode(',', $modpack['categories']) : '';
        $author = $modpack['author'] ?? '';
        $updated_at = isset($modpack['updated']) ? date('Y-m-d H:i:s', strtotime($modpack['updated'])) : null;
        $created_at = isset($modpack['created']) ? date('Y-m-d H:i:s', strtotime($modpack['created'])) : null;

        // Versioni
        $version = '';
        $game_version = '';
        $loader_type = '';
        $download_url = '';

        $versions_url = "https://api.modrinth.com/v2/project/$id/version";
        $versions_data = modrinthApiRequest($versions_url);
        if ($versions_data && is_array($versions_data) && count($versions_data) > 0) {
            $latest_version = $versions_data[0];
            $version = $latest_version['version_number'] ?? '';
            $game_version = isset($latest_version['game_versions']) ? implode(',', $latest_version['game_versions']) : '';
            $loader_type = isset($latest_version['loaders']) ? implode(',', $latest_version['loaders']) : '';
            $download_url = $latest_version['files'][0]['url'] ?? '';
        }

        if (!$download_url || !isModpackServerSideCompatible($download_url)) {
            echo "❌ Skippato modpack non compatibile: $name\n";
            continue;
        }

        // Inserimento o aggiornamento nel DB
        $stmt = $pdo->prepare("SELECT project_id FROM modpacks WHERE project_id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("UPDATE modpacks SET
                title = ?, description = ?, version = ?, game_version = ?, slug = ?, categories = ?, loader_type = ?, download_url = ?, author = ?, updated_at = ?
                WHERE project_id = ?");
            $stmt->execute([
                $name, $description, $version, $game_version, $slug, $categories, $loader_type, $download_url, $author, $updated_at, $id
            ]);
            echo "🔁 Aggiornato modpack: $name ($version)\n";
        } else {
            $stmt = $pdo->prepare("INSERT INTO modpacks (project_id, title, description, version, game_version, slug, categories, loader_type, download_url, author, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $id, $name, $description, $version, $game_version, $slug, $categories, $loader_type, $download_url, $author, $created_at, $updated_at
            ]);
            echo "✅ Inserito modpack: $name ($version)\n";
        }

        $totalProcessed++;
    }

    $page++;
    sleep(1);

} while (true);

echo "🎉 Totale modpack sincronizzati: $totalProcessed\n";
