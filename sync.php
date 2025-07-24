<?php
require 'config/config.php';  // $pdo PDO connection

function modrinthApiGet($endpoint, $params=[]) {
    $url = "https://api.modrinth.com/v2/$endpoint";
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json'
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception('Errore CURL: ' . curl_error($ch));
    }
    curl_close($ch);

    $data = json_decode($response, true);
    if (!$data) {
        throw new Exception('Risposta API non valida');
    }
    return $data;
}

function syncModrinthItems($projectType = 'modpack', $limit = 50) {
    global $pdo;

    $page = 0;
    do {
        $page++;
        $data = modrinthApiGet('search', [
            'facets' => json_encode(["project_type:$projectType"]),
            'limit' => $limit,
            'index' => $limit * ($page - 1)
        ]);

        if (empty($data['hits'])) break;

        foreach ($data['hits'] as $item) {
            $stmt = $pdo->prepare("INSERT INTO modrinth_items (id, title, slug, description, categories, downloads, latest_version_id, date_created, date_modified, project_type, url, thumbnail_url)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                title=VALUES(title),
                slug=VALUES(slug),
                description=VALUES(description),
                categories=VALUES(categories),
                downloads=VALUES(downloads),
                latest_version_id=VALUES(latest_version_id),
                date_created=VALUES(date_created),
                date_modified=VALUES(date_modified),
                project_type=VALUES(project_type),
                url=VALUES(url),
                thumbnail_url=VALUES(thumbnail_url),
                updated_at=NOW()
            ");

            $categoriesJson = json_encode($item['categories'] ?? []);

            $stmt->execute([
                $item['project_id'],
                $item['title'],
                $item['slug'],
                $item['description'] ?? null,
                $categoriesJson,
                $item['downloads'] ?? 0,
                $item['latest_version'] ?? null,
                date('Y-m-d H:i:s', strtotime($item['date_created'])),
                date('Y-m-d H:i:s', strtotime($item['date_modified'])),
                $item['project_type'],
                $item['project_url'] ?? null,
                $item['icon_url'] ?? null
            ]);
        }
    } while (count($data['hits']) === $limit);
}

// Esempio: sincronizza modpack e plugin
try {
    syncModrinthItems('modpack', 50);
    syncModrinthItems('plugin', 50);
    echo "Sincronizzazione Modrinth completata.";
} catch (Exception $e) {
    echo "Errore sincronizzazione: " . $e->getMessage();
}
