<?php
require __DIR__.'/../config/config.php';

$baseApiUrl = "https://api.modrinth.com/v2/projects";

// Funzione per recuperare paginazione API Modrinth
function fetchModrinthForgeProjects($offset = 0, $limit = 50) {
    $url = "https://api.modrinth.com/v2/search";

    $postData = [
        "facets" => [
            ["categories:modpacks"],
            ["loaders:forge"]
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
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($httpCode != 200) {
        echo "Errore API Modrinth, HTTP status code: $httpCode\n";
        return null;
    }

    return json_decode($response, true);
}




// Funzione per recuperare info versione di un progetto Modrinth
function fetchVersions($projectId) {
    $url = "https://api.modrinth.com/v2/project/$projectId/version";
    $response = file_get_contents($url);
    return json_decode($response, true);
}

$page = 0;
$pageSize = 50;
$totalProcessed = 0;

do {
    $data = fetchModrinthForgeProjects($page, $pageSize);
    if (!isset($data['hits'])) break;

    foreach ($data['hits'] as $project) {
        $projectId = $project['id'];
        $slug = $project['slug'];
        $name = $project['title'] ?? $project['slug'];

        $versions = fetchVersions($projectId);
        if (!$versions) continue;

        foreach ($versions as $version) {
            $versionId = $version['id'];
            $versionNumber = $version['version_number'] ?? '';
            $gameVersions = $version['game_versions'] ?? [];
            $loaders = $version['loaders'] ?? [];

            // Filtro versione forge
            if (!in_array('forge', $loaders)) continue;

            // Provo a estrarre la versione Forge da dependencies (se esiste)
            $forgeVersion = null;
            if (isset($version['dependencies']) && is_array($version['dependencies'])) {
                foreach ($version['dependencies'] as $dep) {
                    if (stripos($dep, 'forge') !== false) {
                        $forgeVersion = $dep;
                        break;
                    }
                }
            }

            // Prendo il primo file URL (download)
            $downloadUrl = '';
            if (isset($version['files'][0]['url'])) {
                $downloadUrl = $version['files'][0]['url'];
            }

            $gameVersionStr = implode(',', $gameVersions);

            // Controllo se già presente
            $stmt = $pdo->prepare("SELECT id FROM modpacks WHERE project_id = ? AND version_id = ?");
            $stmt->execute([$projectId, $versionId]);

            if ($stmt->rowCount() > 0) {
                echo "Esiste già: $name - $versionNumber\n";
                continue;
            }

            // Inserisco modpack
            $stmt = $pdo->prepare("INSERT INTO modpacks 
                (name, slug, project_id, version_id, version, download_url, game_version, loader_type, forge_version) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'forge', ?)");

            $stmt->execute([
                $name,
                $slug,
                $projectId,
                $versionId,
                $versionNumber,
                $downloadUrl,
                $gameVersionStr,
                $forgeVersion
            ]);

            echo "Importato: $name - $versionNumber\n";
            $totalProcessed++;
        }
    }

    $page++;
} while ($page * $pageSize < $data['total_hits']);

echo "Totale modpack Forge sincronizzati: $totalProcessed\n";
