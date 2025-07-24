<?php
require 'config/config.php';  // $pdo connessione PDO
define('CURSEFORGE_API_KEY', '$2a$10$yykz2aOhcuZ8rQNQTvOCGO0/sgIdJ7sKUjRqOv0LmllIPEimHh9XC');

function curseforgeApiGet($endpoint, $params=[]) {
    $url = "https://api.curseforge.com/v1/$endpoint";
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'x-api-key: ' . CURSEFORGE_API_KEY,
        'Accept: application/json'
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception('Errore CURL: ' . curl_error($ch));
    }
    curl_close($ch);

    $data = json_decode($response, true);
    if (!isset($data['data'])) {
        throw new Exception('Risposta API non valida');
    }
    return $data['data'];
}

// Sincronizza modpack o plugin da CurseForge e salva nel DB
function syncCurseforgeItems($category, $gameId=432, $classId) {
    global $pdo;

    // CurseForge gameId 432 = Minecraft
    // classId: 6=modpacks, 12=plugins (esempio, verifica API)
    $page = 0;
    $pageSize = 50;

    do {
        $page++;
        $items = curseforgeApiGet('mods/search', [
            'gameId' => $gameId,
            'classId' => $classId,
            'pageSize' => $pageSize,
            'index' => $pageSize * ($page - 1)
        ]);

        if (empty($items)) break;

        foreach ($items as $item) {
            $latestFile = $item['latestFiles'][0] ?? null;
            $stmt = $pdo->prepare("INSERT INTO curseforge_items (id, name, slug, summary, category, latest_file_id, latest_file_name, latest_file_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                name=VALUES(name),
                slug=VALUES(slug),
                summary=VALUES(summary),
                category=VALUES(category),
                latest_file_id=VALUES(latest_file_id),
                latest_file_name=VALUES(latest_file_name),
                latest_file_date=VALUES(latest_file_date),
                updated_at=NOW()
            ");
            $stmt->execute([
                $item['id'],
                $item['name'],
                $item['slug'] ?? null,
                $item['summary'] ?? null,
                $category,
                $latestFile['id'] ?? null,
                $latestFile['fileName'] ?? null,
                isset($latestFile['fileDate']) ? date('Y-m-d H:i:s', strtotime($latestFile['fileDate'])) : null
            ]);
        }
    } while (count($items) === $pageSize);
}

// Esempio: aggiorna modpack e plugin
try {
    syncCurseforgeItems('modpack', 432, 6);  // 6 = modpack classId (verifica ufficiale)
    syncCurseforgeItems('plugin', 432, 12); // 12 = plugin classId (verifica ufficiale)
    echo "Sincronizzazione completata.";
} catch (Exception $e) {
    echo "Errore sincronizzazione: " . $e->getMessage();
}
