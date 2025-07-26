<?php
$apiKey = '$2a$10$yykz2aOhcuZ8rQNQTvOCGO0/sgIdJ7sKUjRqOv0LmllIPEimHh9XC';
$db = new PDO('mysql:host=localhost;dbname=minecraft_platform', 'diego', 'Lgu8330Serve6');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function fetchGameVersions() {
    global $apiKey;

    $url = "https://api.curseforge.com/v1/minecraft/version";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "x-api-key: $apiKey"
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        echo "❌ Errore HTTP $httpCode durante la richiesta delle versioni Minecraft\n";
        return [];
    }

    $data = json_decode($response, true);
    if (!isset($data['data'])) {
        echo "❌ Risposta API malformata\n";
        return [];
    }

    // Filtra versioni stabili (no snapshot/prerelease)
    $stable = array_filter($data['data'], fn($v) => !$v['isSnapshot'] && !$v['isPreRelease']);

    // Ritorna solo i nomi delle versioni, es. ["1.20.1", "1.21.0"]
    return array_map(fn($v) => $v['versionString'], $stable);
}

function fetchPlugins($page = 0, $gameVersion = null) {
    global $apiKey;

    $versionParam = $gameVersion ? "&gameVersion=" . urlencode($gameVersion) : '';
    $url = "https://api.curseforge.com/v1/mods/search?gameId=432&classId=5&sortField=2&sortOrder=desc&pageSize=50&page=$page$versionParam";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "x-api-key: $apiKey"
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        echo "❌ Errore HTTP $httpCode per versione $gameVersion (pagina $page)\n";
        return [];
    }

    $data = json_decode($response, true);
    return $data['data'] ?? [];
}

function fixDate($isoDate) {
    try {
        return (new DateTime($isoDate))->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

$total = 0;
$versions = fetchGameVersions();

if (empty($versions)) {
    echo "⚠️ Nessuna versione valida trovata. Interrotto.\n";
    exit;
}

foreach ($versions as $version) {
    $page = 0;
    echo "🔄 Scarico plugin per versione Minecraft $version...\n";

    while (true) {
        $plugins = fetchPlugins($page, $version);
        if (empty($plugins)) break;

        foreach ($plugins as $plugin) {
            $stmt = $db->prepare("INSERT INTO plugins (
                cf_project_id, name, slug, summary, download_url,
                logo_url, website_url, date_created, date_modified,
                download_count, game_versions, latest_files
            ) VALUES (
                :cf_project_id, :name, :slug, :summary, :download_url,
                :logo_url, :website_url, :date_created, :date_modified,
                :download_count, :game_versions, :latest_files
            ) ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                summary = VALUES(summary),
                download_url = VALUES(download_url),
                logo_url = VALUES(logo_url),
                website_url = VALUES(website_url),
                date_modified = VALUES(date_modified),
                download_count = VALUES(download_count),
                game_versions = VALUES(game_versions),
                latest_files = VALUES(latest_files)");

            $stmt->execute([
                ':cf_project_id' => $plugin['id'],
                ':name' => $plugin['name'],
                ':slug' => $plugin['slug'],
                ':summary' => $plugin['summary'],
                ':download_url' => $plugin['latestFiles'][0]['downloadUrl'] ?? null,
                ':logo_url' => $plugin['logo']['thumbnailUrl'] ?? null,
                ':website_url' => $plugin['links']['websiteUrl'] ?? null,
                ':date_created' => fixDate($plugin['dateCreated']),
                ':date_modified' => fixDate($plugin['dateModified']),
                ':download_count' => $plugin['downloadCount'],
                ':game_versions' => json_encode($plugin['gameVersions'] ?? []),
                ':latest_files' => json_encode($plugin['latestFiles'] ?? []),
            ]);

            $total++;
        }

        echo "✅ Pagina $page di $version sincronizzata, totale plugin finora: $total\n";
        $page++;
        sleep(1); // evitare rate-limit
    }
}

echo "🎉 Sincronizzazione completata: $total plugin popolari importati da tutte le versioni supportate.\n";
