<?php
// Configurazione DB
define('DB_HOST', 'localhost');
define('DB_NAME', 'minecraft_platform');
define('DB_USER', 'diego');
define('DB_PASSWORD', 'Lgu8330Serve6');
define('DB_CHARSET', 'utf8mb4');

// API CurseForge key
define('CURSEFORGE_API_KEY', '$2a$10$yykz2aOhcuZ8rQNQTvOCGO0/sgIdJ7sKUjRqOv0LmllIPEimHh9XC');

// Connessione DB
function connect_db() {
    $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    try {
        return new PDO($dsn, DB_USER, DB_PASSWORD, $options);
    } catch (PDOException $e) {
        die("Errore connessione DB: " . $e->getMessage());
    }
}

// Inserimento o aggiornamento progetto
function insert_or_update_project(PDO $pdo, $project) {
    $sql = "INSERT INTO curseforge_projects 
        (id, name, slug, summary, downloads, project_type, game_versions, date_created, date_modified, url, thumbnail_url)
        VALUES (:id, :name, :slug, :summary, :downloads, :project_type, :game_versions, :date_created, :date_modified, :url, :thumbnail_url)
        ON DUPLICATE KEY UPDATE 
          name = VALUES(name),
          slug = VALUES(slug),
          summary = VALUES(summary),
          downloads = VALUES(downloads),
          project_type = VALUES(project_type),
          game_versions = VALUES(game_versions),
          date_created = VALUES(date_created),
          date_modified = VALUES(date_modified),
          url = VALUES(url),
          thumbnail_url = VALUES(thumbnail_url)";
    
    $stmt = $pdo->prepare($sql);
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
    echo "Sincronizzato progetto ID {$project['id']}: {$project['name']}\n";
}

// Funzione per richiamare l’API CurseForge per un tipo di progetto
function fetch_curseforge_projects(PDO $pdo, $projectType, $pageSize = 50) {
    $page = 0;
    $hasMore = true;

    // Map project type a classId API CurseForge
    $classIds = [
        'modpack' => 4471,
        'plugin' => 6, // I plugin generalmente sono mod, usiamo 6 per mod/plugin
    ];

    if (!isset($classIds[$projectType])) {
        echo "Tipo progetto sconosciuto: $projectType\n";
        return;
    }
    $classId = $classIds[$projectType];

    while ($hasMore) {
        $offset = $page * $pageSize;

        $url = "https://api.curseforge.com/v1/mods/search?"
            . "gameId=432"  // Minecraft
            . "&classId=$classId"
            . "&pageSize=$pageSize"
            . "&index=$offset";

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
            echo "Errore API CurseForge: HTTP $http_code\n";
            break;
        }

        $data = json_decode($response, true);
        if (!$data || !isset($data['data']) || count($data['data']) === 0) {
            echo "Nessun altro progetto di tipo $projectType trovato.\n";
            $hasMore = false;
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
                'game_versions' => $mod['gameVersionLatestFiles'] ?? [], // array versioni Minecraft
                'date_created' => isset($mod['dateCreated']) ? date('Y-m-d H:i:s', strtotime($mod['dateCreated'])) : null,
                'date_modified' => isset($mod['dateModified']) ? date('Y-m-d H:i:s', strtotime($mod['dateModified'])) : null,
                'url' => "https://www.curseforge.com/minecraft/" . ($projectType === 'modpack' ? "modpacks" : "mods") . "/" . $mod['slug'],
                'thumbnail_url' => $mod['logo']['thumbnailUrl'] ?? null,
            ];
            insert_or_update_project($pdo, $project);
        }

        $page++;
    }
}

// Esecuzione sincronizzazione
$pdo = connect_db();

echo "Sincronizzazione Modpacks...\n";
fetch_curseforge_projects($pdo, 'modpack');

echo "Sincronizzazione Plugin...\n";
fetch_curseforge_projects($pdo, 'plugin');

echo "Sincronizzazione completata.\n";
