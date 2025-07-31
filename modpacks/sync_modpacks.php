<?php
require __DIR__ .'/../config/config.php';

// Imposta l'header per l'output leggibile
header('Content-Type: text/plain');

$page = 0;
$limit = 50;

do {
    $url = "https://api.modrinth.com/v2/search?limit=$limit&offset=" . ($page * $limit) . "&facets=" . urlencode('[["project_type:modpack"],["categories:forge"],["client_side:required"]]');

    $json = file_get_contents($url);
    if ($json === false) {
        die("Errore durante il download dei dati da Modrinth\n");
    }

    $data = json_decode($json, true);
    if (!isset($data['hits'])) {
        die("Risposta non valida da Modrinth\n");
    }

    foreach ($data['hits'] as $modpack) {
        $projectId = $modpack['project_id'] ?? null;
        $title = $modpack['title'] ?? '';
        $slug = $modpack['slug'] ?? '';
        $description = $modpack['description'] ?? '';
        $categories = $modpack['categories'] ?? [];
        $updated = $modpack['date_modified'] ?? null;
        $downloads = $modpack['downloads'] ?? 0;
        $projectType = $modpack['project_type'] ?? 'modpack';

        // Prende la prima versione stabile
        $versionUrl = "https://api.modrinth.com/v2/project/$slug/version";
        $versionJson = file_get_contents($versionUrl);
        $gameVersion = null;

        if ($versionJson !== false) {
            $versions = json_decode($versionJson, true);
            foreach ($versions as $ver) {
                if ($ver['version_type'] === 'release' && in_array('forge', $ver['loaders'] ?? [])) {
                    $gameVersion = $ver['game_versions'][0] ?? null;
                    break;
                }
            }
        }

        if (!$projectId || !$gameVersion) {
            continue;
        }

        // Inserisce o aggiorna
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM modpacks WHERE project_id = ?");
        $stmt->execute([$projectId]);
        $exists = $stmt->fetchColumn() > 0;

        if ($exists) {
            $stmt = $pdo->prepare("UPDATE modpacks SET title=?, game_version=?, slug=?, description=?, categories=?, updated=?, downloads=?, project_type=? WHERE project_id=?");
            $stmt->execute([
                $title,
                $gameVersion,
                $slug,
                $description,
                json_encode($categories),
                $updated,
                $downloads,
                $projectType,
                $projectId
            ]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO modpacks (project_id, title, game_version, slug, description, categories, updated, downloads, project_type)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $projectId,
                $title,
                $gameVersion,
                $slug,
                $description,
                json_encode($categories),
                $updated,
                $downloads,
                $projectType
            ]);
        }

        echo "Importato: $title ($gameVersion)\n";
    }

    $page++;
    $hasMore = count($data['hits']) === $limit;

    sleep(1); // Evita rate limiting

} while ($hasMore);

echo "Sync completato.\n";
