<?php
// sync_modpacks.php

require_once '../config/config.php';

$token = 'Bearer YOUR_API_TOKEN'; // se Modrinth richiede autenticazione, altrimenti rimuovi

$page = 0;
$limit = 100;

do {
    $url = "https://api.modrinth.com/v2/search?limit=$limit&offset=" . ($page * $limit) . "&facets=" . urlencode('[["project_type:modpack"],["categories:forge"]]');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'User-Agent: ModpackSyncScript/1.0',
            // $token, // decommenta se usi token
        ]
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) {
        die("Errore nella richiesta API\n");
    }

    $data = json_decode($response, true);
    if (!isset($data['hits']) || empty($data['hits'])) break;

    foreach ($data['hits'] as $modpack) {
        $projectId = $modpack['project_id'] ?? null;
        $title = $modpack['title'] ?? '';
        $slug = $modpack['slug'] ?? '';
        $description = $modpack['description'] ?? '';
        $categories = $modpack['categories'] ?? [];
        $updated = $modpack['updated'] ?? null;
        $downloads = $modpack['downloads'] ?? 0;
        $projectType = $modpack['project_type'] ?? 'modpack';
        $gameVersions = $modpack['game_versions'] ?? [];

        // Filtra modpack che non hanno game_version valida
        $gameVersion = count($gameVersions) > 0 ? $gameVersions[0] : null;
        if (!$projectId || !$gameVersion) continue;

        // Filtra solo Forge
        $loaders = $modpack['loaders'] ?? [];
        if (!in_array('forge', $loaders)) continue;

        // Usa la versione Forge se disponibile
        $forgeVersion = null;
        foreach ($gameVersions as $ver) {
            if (strpos($ver, 'forge') !== false) {
                $forgeVersion = $ver;
                break;
            }
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM modpacks WHERE project_id = ?");
        $stmt->execute([$projectId]);
        $exists = $stmt->fetchColumn() > 0;

        if ($exists) {
            $stmt = $pdo->prepare("UPDATE modpacks SET title = ?, game_version = ?, slug = ?, description = ?, categories = ?, updated = ?, downloads = ?, project_type = ?, forge_version = ? WHERE project_id = ?");
            $stmt->execute([
                $title,
                $gameVersion,
                $slug,
                $description,
                json_encode($categories),
                $updated,
                $downloads,
                $projectType,
                $forgeVersion,
                $projectId
            ]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO modpacks (project_id, title, game_version, slug, description, categories, updated, downloads, project_type, forge_version)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $projectId,
                $title,
                $gameVersion,
                $slug,
                $description,
                json_encode($categories),
                $updated,
                $downloads,
                $projectType,
                $forgeVersion
            ]);
        }
    }

    $page++;
} while (count($data['hits']) === $limit);

echo "Sincronizzazione completata.\n";
