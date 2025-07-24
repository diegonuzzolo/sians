<?php
// Configurazione DB e API
$dsn = 'mysql:host=localhost;dbname=minecraft_platform;charset=utf8mb4';
$user = 'diego';
$pass = 'Lgu8330Serve6';

const CURSEFORGE_API_KEY = '$2a$10$yykz2aOhcuZ8rQNQTvOCGO0/sgIdJ7sKUjRqOv0LmllIPEimHh9XC';

try {
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "🟢 Connessione al database riuscita.\n";
} catch (PDOException $e) {
    die("🔴 Errore connessione DB: " . $e->getMessage() . "\n");
}

function insert_or_update_project(PDO $pdo, array $project) {
    $stmt = $pdo->prepare("REPLACE INTO curseforge_projects
        (id, name, slug, summary, downloads, project_type, game_versions, date_created, date_modified, url, thumbnail_url)
        VALUES (:id, :name, :slug, :summary, :downloads, :project_type, :game_versions, :date_created, :date_modified, :url, :thumbnail_url)");

    $stmt->execute([
        ':id' => $project['id'],
        ':name' => $project['name'],
        ':slug' => $project['slug'],
        ':summary' => $project['summary'],
        ':downloads' => $project['downloads'],
        ':project_type' => $project['project_type'],
        ':game_versions' => json_encode($project['game_versions']),
        ':date_created' => $project['date_created'],
        ':date_modified' => $project['date_modified'],
        ':url' => $project['url'],
        ':thumbnail_url' => $project['thumbnail_url'],
    ]);

    echo "✅ Salvato: {$project['name']} [{$project['id']}] ({$project['project_type']})\n";
}

function fetch_curseforge_projects(PDO $pdo, string $projectType, int $classId) {
    $page = 0;
    $pageSize = 50;
    echo "\n🔄 Sincronizzazione $projectType...\n";

    do {
        $page++;
        $url = "https://api.curseforge.com/v1/mods/search?gameId=432&classId=$classId&pageSize=$pageSize&page=$page";
        $headers = ["x-api-key: " . CURSEFORGE_API_KEY];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $data = json_decode($response, true);
        curl_close($ch);

        if (!isset($data['data']) || count($data['data']) === 0) {
            echo "ℹ️  Nessun dato ricevuto alla pagina $page. Fine.\n";
            break;
        }

        foreach ($data['data'] as $mod) {
            $project = [
                'id' => $mod['id'],
                'name' => $mod['name'],
                'slug' => $mod['slug'] ?? null,
                'summary' => $mod['summary'] ?? null,
                'downloads' => $mod['downloadCount'] ?? 0,
                'project_type' => $projectType,
                'game_versions' => $mod['gameVersionLatestFiles'] ?? [],
                'date_created' => isset($mod['dateCreated']) ? date('Y-m-d H:i:s', strtotime($mod['dateCreated'])) : null,
                'date_modified' => isset($mod['dateModified']) ? date('Y-m-d H:i:s', strtotime($mod['dateModified'])) : null,
                'url' => "https://www.curseforge.com/minecraft/{$projectType}s/" . $mod['slug'],
                'thumbnail_url' => $mod['logo']['thumbnailUrl'] ?? null,
            ];

            insert_or_update_project($pdo, $project);
        }

    } while (true);

    echo "✅ Completato $projectType.\n";
}

function fetch_project_by_id(PDO $pdo, $id, $projectType = 'modpack') {
    echo "\n🔍 Recupero manuale progetto ID $id...\n";
    $url = "https://api.curseforge.com/v1/mods/$id";
    $headers = [
        "x-api-key: " . CURSEFORGE_API_KEY,
        "Accept: application/json",
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($response === false || $http_code >= 400) {
        echo "❌ Errore nel recupero progetto ID $id: HTTP $http_code\n";
        return;
    }

    $mod = json_decode($response, true)['data'];
    if (!$mod) {
        echo "❌ Progetto ID $id non trovato.\n";
        return;
    }

    $project = [
        'id' => $mod['id'],
        'name' => $mod['name'],
        'slug' => $mod['slug'] ?? null,
        'summary' => $mod['summary'] ?? null,
        'downloads' => $mod['downloadCount'] ?? 0,
        'project_type' => $projectType,
        'game_versions' => $mod['gameVersionLatestFiles'] ?? [],
        'date_created' => isset($mod['dateCreated']) ? date('Y-m-d H:i:s', strtotime($mod['dateCreated'])) : null,
        'date_modified' => isset($mod['dateModified']) ? date('Y-m-d H:i:s', strtotime($mod['dateModified'])) : null,
        'url' => "https://www.curseforge.com/minecraft/{$projectType}s/" . $mod['slug'],
        'thumbnail_url' => $mod['logo']['thumbnailUrl'] ?? null,
    ];

    insert_or_update_project($pdo, $project);
    echo "✅ Aggiunto progetto manuale: {$mod['name']} (ID: $id)\n";
}

// Avvia sincronizzazione CLI
fetch_curseforge_projects($pdo, 'modpack', 4471);
fetch_curseforge_projects($pdo, 'plugin', 4559);
fetch_project_by_id($pdo, 301027, 'modpack'); // Aggiunta RLCraft

echo "\n🎉 Sincronizzazione completata!\n";
