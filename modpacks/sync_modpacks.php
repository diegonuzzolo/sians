<?php
// sync_modpacks.php

require __DIR__ .'/../config/config.php';

// Filtro solo i modpack Forge
$page = 0;
$limit = 50;

do {
    $url = "https://api.modrinth.com/v2/search?limit=$limit&offset=" . ($page * $limit) . "&facets=" . urlencode('[["project_type:modpack"],["categories:forge"],["client_side:required"],["server_side:required"]]');

    $response = file_get_contents($url);
    if ($response === false) {
        echo "Errore durante la richiesta Modrinth\n";
        exit;
    }

    $data = json_decode($response, true);
    $hits = $data['hits'] ?? [];

    if (empty($hits)) {
        break;
    }

    foreach ($hits as $modpack) {
        $project_id = $modpack['project_id'];
        $slug = $modpack['slug'];
        $title = $modpack['title'];
        $description = $modpack['description'];
        $projectType = $modpack['project_type'];
        $icon_url = $modpack['icon_url'] ?? '';
        $downloads = $modpack['downloads'] ?? 0;
        $updated_raw = $modpack['updated'] ?? null;
        
        $updated = $updated_raw ? date('Y-m-d H:i:s', strtotime($updated_raw)) : date('Y-m-d H:i:s');

        // Ottieni le versioni disponibili
        $versionResponse = file_get_contents("https://api.modrinth.com/v2/project/$slug/version");
        $versionData = json_decode($versionResponse, true);
        $latestVersion = $versionData[0] ?? null;

        if (!$latestVersion) {
            echo "Nessuna versione trovata per $slug\n";
            continue;
        }

        // Cerchiamo la prima versione compatibile con Minecraft
        $gameVersions = $latestVersion['game_versions'] ?? [];
        $gameVersion = $gameVersions[0] ?? null;
        if (!$gameVersion) {
            echo "Nessuna game version per $slug\n";
            continue;
        }

        // Download URL (Modrinth pack format v1)
        $downloadUrl = '';
        foreach ($latestVersion['files'] as $file) {
            if ($file['primary'] ?? false) {
                $downloadUrl = $file['url'];
                break;
            }
        }

        if (!$downloadUrl) {
            echo "Nessun file principale per $slug\n";
            continue;
        }

        // Inserisci o aggiorna il modpack nel database
        $stmt = $pdo->prepare("
            INSERT INTO modpacks (project_id, slug, title, description, icon_url, downloads, updated, download_url, game_version, project_type)
            VALUES (:project_id, :slug, :title, :description, :icon_url, :downloads, :updated, :download_url, :game_version, :project_type)
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                description = VALUES(description),
                icon_url = VALUES(icon_url),
                downloads = VALUES(downloads),
                updated = VALUES(updated),
                download_url = VALUES(download_url),
                game_version = VALUES(game_version)
        ");

        $stmt->execute([
            ':project_id' => $project_id,
            ':slug' => $slug,
            ':title' => $title,
            ':description' => $description,
            ':icon_url' => $icon_url,
            ':downloads' => $downloads,
            ':updated' => $updated,
            ':download_url' => $downloadUrl,
            ':game_version' => $gameVersion,
            'project_type' => $projectType
        ]);

        echo "Modpack aggiornato: $title ($slug)\n";
    }

    $page++;
    usleep(500_000); // 0.5 secondi di pausa per non stressare l’API

} while (count($hits) === $limit);

echo "Sincronizzazione completata.\n";
