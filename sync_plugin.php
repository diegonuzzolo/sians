<?php
$apiKey = '$2a$10$yykz2aOhcuZ8rQNQTvOCGO0/sgIdJ7sKUjRqOv0LmllIPEimHh9XC';
$db = new PDO('mysql:host=localhost;dbname=minecraft_platform', 'diego', 'Lgu8330Serve6');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Recupera tutte le versioni Minecraft valide
function fetchGameVersions() {
    global $apiKey;
    $url = "https://api.curseforge.com/v1/games/432/versions";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["x-api-key: $apiKey"]);

    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        echo "❌ Errore nel recupero versioni: HTTP $code\n";
        return [];
    }

    $data = json_decode($response, true)['data'] ?? [];
    $minecraftVersions = [];

    foreach ($data as $version) {
        if (isset($version['gameVersionTypeId']) && $version['gameVersionTypeId'] == 73250 && $version['status'] == 2) {
            $minecraftVersions[] = $version['name'];
        }
    }

    return array_reverse($minecraftVersions); // più recenti prima
}

function fetchPlugins($version, $page = 0) {
    global $apiKey;

    $encodedVersion = urlencode($version);
    $url = "https://api.curseforge.com/v1/mods/search?gameId=432&classId=5&sortField=2&sortOrder=desc&pageSize=50&page=$page&gameVersion=$encodedVersion";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "x-api-key: $apiKey"
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        echo "❌ Errore HTTP $httpCode per versione $version, pagina $page\n";
        return [];
    }

    return json_decode($response, true)['data'] ?? [];
}

function fixDate($isoDate) {
    try {
        return (new DateTime($isoDate))->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

// Avvio sincronizzazione
$total = 0;
$versions = fetchGameVersions();
print_r($versions);
foreach ($versions as $version) {
    echo "🔄 Versione: $version\n";
    $page = 0;

    while (true) {
        $plugins = fetchPlugins($version, $page);
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

        echo "   ➕ Pagina $page sincronizzata (versione $version), totale plugin: $total\n";
        $page++;
        sleep(1); // anti rate-limit
    }
}

echo "\n🎉 Sincronizzazione completata: $total plugin popolari importati da tutte le versioni supportate.\n";
