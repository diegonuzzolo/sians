<?php
include("config/config.php"); // Include il file di configurazione per le costanti del database
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('DB_PORT', 3306);
define('MODRINTH_API_BASE_URL', 'https://api.modrinth.com/v2');
define('USER_AGENT', 'MinecraftPlatformSyncScriptPHP/1.0 (contact@example.com)');
define('PAGE_LIMIT', 100);

$projectTypesToSync = ["mod", "modpack"];

// --- Funzioni Database ---
function connect_db() {
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, $options);
        echo "Connessione al database riuscita.\n";
        return $pdo;
    } catch (PDOException $e) {
        echo "ERRORE CRITICO: Errore durante la connessione al database: " . $e->getMessage() . "\n";
        return null;
    }
}

function insert_or_update_item($pdo, $item_data) {
    $sql = "
    INSERT INTO modrinth_items (
        id, title, slug, description, categories, downloads,
        latest_version_id, date_created, date_modified, project_type, url, thumbnail_url
    ) VALUES (
        :id, :title, :slug, :description, :categories, :downloads,
        :latest_version_id, :date_created, :date_modified, :project_type, :url, :thumbnail_url
    ) ON DUPLICATE KEY UPDATE
        title = VALUES(title),
        slug = VALUES(slug),
        description = VALUES(description),
        categories = VALUES(categories),
        downloads = VALUES(downloads),
        latest_version_id = VALUES(latest_version_id),
        date_created = VALUES(date_created),
        date_modified = VALUES(date_modified),
        project_type = VALUES(project_type),
        url = VALUES(url),
        thumbnail_url = VALUES(thumbnail_url);
    ";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':id' => $item_data['id'],
            ':title' => $item_data['title'],
            ':slug' => $item_data['slug'],
            ':description' => $item_data['description'],
            ':categories' => $item_data['categories'],
            ':downloads' => $item_data['downloads'],
            ':latest_version_id' => $item_data['latest_version_id'],
            ':date_created' => $item_data['date_created'],
            ':date_modified' => $item_data['date_modified'],
            ':project_type' => $item_data['project_type'],
            ':url' => $item_data['url'],
            ':thumbnail_url' => $item_data['thumbnail_url']
        ]);
        echo "Elemento sincronizzato: " . $item_data['title'] . " (ID: " . $item_data['id'] . ")\n";
    } catch (PDOException $e) {
        echo "ERRORE DB: Errore durante la sincronizzazione dell'elemento " . ($item_data['id'] ?? 'N/A') . ": " . $e->getMessage() . "\n";
    }
}

// --- Funzioni API Modrinth ---
function fetch_modrinth_projects($project_type, $offset = 0, $limit = PAGE_LIMIT) {
    $headers = [
        "User-Agent: " . USER_AGENT,
        "Accept: application/json"
    ];

    $params = [
        "facets" => json_encode([["project_type:" . $project_type]]),
        "offset" => $offset,
        "limit" => $limit,
        "index" => "downloads" // MODIFICATO: ordina per popolarità
    ];

    $url = MODRINTH_API_BASE_URL . "/search?" . http_build_query($params);
    echo "DEBUG: Richiesta API Modrinth URL: " . $url . "\n";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    curl_close($ch);

    if ($response === false) {
        echo "ERRORE cURL: Codice errore: $curl_errno, Messaggio: $curl_error\n";
        return null;
    }

    if ($http_code >= 400) {
        echo "ERRORE HTTP: ($http_code) Risposta: " . $response . "\n";
        return null;
    }

    $decoded_response = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "ERRORE JSON: " . json_last_error_msg() . ". Risposta RAW: " . $response . "\n";
        return null;
    }

    echo "DEBUG: Recuperati " . (isset($decoded_response['hits']) ? count($decoded_response['hits']) : 0) . " progetti per '$project_type' (offset $offset).\n";
    return $decoded_response;
}

// --- Sincronizzazione principale ---
function sync_modrinth_data() {
    $pdo = connect_db();
    if (!$pdo) {
        echo "Sincronizzazione interrotta per errore di connessione.\n";
        return;
    }

    try {
        global $projectTypesToSync;

        foreach ($projectTypesToSync as $p_type) {
            echo "\n--- Avvio sincronizzazione per: '$p_type' ---\n";
            $offset = 0;
            $totalSyncedForType = 0;

            while (true) {
                $data = fetch_modrinth_projects($p_type, $offset, PAGE_LIMIT);

                if (!$data || !isset($data['hits']) || empty($data['hits'])) {
                    echo "Nessun altro progetto di tipo '$p_type' trovato o errore API.\n";
                    break;
                }

                foreach ($data['hits'] as $project) {
                    $dateCreated = null;
                    if (isset($project['date_created'])) {
                        try {
                            $dt = new DateTime($project['date_created']);
                            $dateCreated = $dt->format('Y-m-d H:i:s');
                        } catch (Exception $e) {
                            echo "AVVISO: Errore parsing date_created per ID {$project['project_id']}: {$e->getMessage()}\n";
                        }
                    }

                    $dateModified = null;
                    if (isset($project['date_modified'])) {
                        try {
                            $dt = new DateTime($project['date_modified']);
                            $dateModified = $dt->format('Y-m-d H:i:s');
                        } catch (Exception $e) {
                            echo "AVVISO: Errore parsing date_modified per ID {$project['project_id']}: {$e->getMessage()}\n";
                        }
                    }

                    $item_data = [
                        'id' => $project['project_id'] ?? null,
                        'title' => $project['title'] ?? null,
                        'slug' => $project['slug'] ?? null,
                        'description' => $project['description'] ?? null,
                        'categories' => json_encode($project['categories'] ?? []),
                        'downloads' => $project['downloads'] ?? null,
                        'latest_version_id' => $project['latest_version'] ?? null,
                        'date_created' => $dateCreated,
                        'date_modified' => $dateModified,
                        'project_type' => $project['project_type'] ?? null,
                        'url' => isset($project['slug']) ? "https://modrinth.com/project/" . $project['slug'] : null,
                        'thumbnail_url' => $project['icon_url'] ?? null
                    ];

                    if ($item_data['id'] !== null) {
                        insert_or_update_item($pdo, $item_data);
                        $totalSyncedForType++;
                    } else {
                        echo "AVVISO: Elemento senza ID valido, saltato. Dati: " . json_encode($project) . "\n";
                    }
                }

                if (count($data['hits']) < PAGE_LIMIT) {
                    echo "Raggiunta l'ultima pagina per '$p_type'.\n";
                    break;
                }

                $offset += PAGE_LIMIT;
                echo "Totale sincronizzato per '$p_type': $totalSyncedForType. Prossimo offset: $offset\n";
            }
        }
    } catch (Exception $e) {
        echo "ERRORE INATTESO: " . $e->getMessage() . "\n";
    } finally {
        echo "Sincronizzazione completata.\n";
    }
}

// --- Esecuzione principale ---
if (php_sapi_name() == 'cli') {
    sync_modrinth_data();
} else {
    echo "Questo script va eseguito da riga di comando.\n";
}
?>
