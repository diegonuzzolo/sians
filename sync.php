<?php
include("config/config.php"); // Include il file di configurazione per le costanti del database
// Abilita la visualizzazione di tutti gli errori PHP per il debug
error_reporting(E_ALL);
ini_set('display_errors', 1);


define('DB_PORT', 3306); // Aggiunto il port, se non specificato in DSN

// --- Configurazione API Modrinth ---
define('MODRINTH_API_BASE_URL', 'https://api.modrinth.com/v2');
// IMPORTANTE: L'API di Modrinth richiede un'intestazione User-Agent descrittiva.
// Sostituiscila con qualcosa che identifichi la tua applicazione,
// es. 'IlTuoNomeUtenteGitHub/IlTuoNomeProgetto/1.0 (tua_email@example.com)'
define('USER_AGENT', 'MinecraftPlatformSyncScriptPHP/1.0 (contact@example.com)');

// Tipi di progetto da sincronizzare. Modrinth supporta "mod", "modpack", "resourcepack", "shader".
// Ci concentriamo su "mod" e "modpack" come da tua richiesta.
$projectTypesToSync = ["mod", "modpack"];

// Numero massimo di risultati per pagina per l'API di ricerca di Modrinth (max è 100)
define('PAGE_LIMIT', 100);

// --- Funzioni Database ---
function connect_db() {
    /**
     * Stabilisce una connessione al database MySQL utilizzando PDO.
     * Restituisce l'oggetto PDO se la connessione ha successo, altrimenti null.
     */
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
    /**
     * Inserisce un nuovo elemento o aggiorna uno esistente nella tabella modrinth_items.
     * Utilizza la sintassi SQL 'INSERT ... ON DUPLICATE KEY UPDATE' per operazioni di upsert efficienti.
     */
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
        // Puoi aggiungere qui una logica per gestire l'errore, ad esempio saltare l'elemento corrente
    }
}

// --- Funzioni API Modrinth ---
function fetch_modrinth_projects($project_type, $offset = 0, $limit = PAGE_LIMIT) {
    /**
     * Recupera i progetti dall'API di Modrinth utilizzando l'endpoint /search.
     * Supporta la paginazione con i parametri 'offset' e 'limit'.
     * I risultati sono ordinati per data 'updated' per dare priorità alle modifiche recenti.
     */
    $headers = [
        "User-Agent: " . USER_AGENT,
        "Accept: application/json"
    ];

    $params = [
        "facets" => json_encode([["project_type:" . $project_type]]),
        "offset" => $offset,
        "limit" => $limit,
        "index" => "updated" // Ordina per data di aggiornamento
    ];

    $url = MODRINTH_API_BASE_URL . "/search?" . http_build_query($params);
    echo "DEBUG: Richiesta API Modrinth URL: " . $url . "\n";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Timeout di 30 secondi

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    curl_close($ch);

    if ($response === false) {
        echo "ERRORE cURL: Durante il recupero dei dati da Modrinth per il tipo '$project_type', offset $offset. Codice errore: $curl_errno, Messaggio: $curl_error\n";
        return null;
    }

    if ($http_code >= 400) {
        echo "ERRORE HTTP: ($http_code) durante il recupero dei dati da Modrinth per il tipo '$project_type', offset $offset. Risposta: " . $response . "\n";
        return null;
    }

    $decoded_response = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "ERRORE JSON: Impossibile decodificare la risposta API per il tipo '$project_type', offset $offset. Errore: " . json_last_error_msg() . ". Risposta RAW: " . $response . "\n";
        return null;
    }

    echo "DEBUG: Recuperati " . (isset($decoded_response['hits']) ? count($decoded_response['hits']) : 0) . " progetti per il tipo '$project_type' (offset $offset).\n";
    return $decoded_response;
}

function sync_modrinth_data() {
    /**
     * Funzione principale per orchestrare il processo di sincronizzazione.
     * Si connette al database, itera attraverso i tipi di progetto specificati,
     * recupera i dati da Modrinth pagina per pagina e sincronizza ogni elemento.
     */
    $pdo = connect_db();
    if (!$pdo) {
        echo "Sincronizzazione interrotta a causa di un errore di connessione al database.\n";
        return; // Esci se la connessione al database fallisce
    }

    try {
        global $projectTypesToSync; // Accedi alla variabile globale

        foreach ($projectTypesToSync as $p_type) {
            echo "\n--- Avvio sincronizzazione per il tipo di progetto: '$p_type' ---\n";
            $offset = 0;
            $totalSyncedForType = 0;

            while (true) {
                $data = fetch_modrinth_projects($p_type, $offset, PAGE_LIMIT);

                if (!$data || !isset($data['hits']) || empty($data['hits'])) {
                    echo "Nessun altro progetto di tipo '$p_type' trovato o si è verificato un errore API/decodifica JSON.\n";
                    break;
                }

                foreach ($data['hits'] as $project) {
                    // Mappa i campi della risposta API di Modrinth alle colonne dello schema del tuo database
                    // Nota: 'latest_version_id' nel tuo schema è mappato a 'latest_version' di Modrinth
                    // (che è una stringa della versione del gioco Minecraft, es. "1.20.1").
                    // Se hai bisogno dell'ID interno della versione di Modrinth, sarebbero necessarie chiamate API aggiuntive.

                    $dateCreated = null;
                    if (isset($project['date_created'])) {
                        try {
                            $dt = new DateTime($project['date_created']);
                            $dateCreated = $dt->format('Y-m-d H:i:s');
                        } catch (Exception $e) {
                            echo "AVVISO: Impossibile parsare date_created '{$project['date_created']}' per l'ID {$project['project_id']}: {$e->getMessage()}\n";
                        }
                    }

                    $dateModified = null;
                    if (isset($project['date_modified'])) {
                        try {
                            $dt = new DateTime($project['date_modified']);
                            $dateModified = $dt->format('Y-m-d H:i:s');
                        } catch (Exception $e) {
                            echo "AVVISO: Impossibile parsare date_modified '{$project['date_modified']}' per l'ID {$project['project_id']}: {$e->getMessage()}\n";
                        }
                    }

                    $item_data = [
                        'id' => $project['project_id'] ?? null,
                        'title' => $project['title'] ?? null,
                        'slug' => $project['slug'] ?? null,
                        'description' => $project['description'] ?? null,
                        // Le categorie sono un array; memorizzale come stringa JSON
                        'categories' => json_encode($project['categories'] ?? []),
                        'downloads' => $project['downloads'] ?? null,
                        'latest_version_id' => $project['latest_version'] ?? null,
                        'date_created' => $dateCreated,
                        'date_modified' => $dateModified,
                        'project_type' => $project['project_type'] ?? null,
                        'url' => isset($project['slug']) ? "https://modrinth.com/project/" . $project['slug'] : null,
                        'thumbnail_url' => $project['icon_url'] ?? null
                    ];
                    
                    // Verifica che l'ID non sia nullo prima di tentare l'inserimento
                    if ($item_data['id'] !== null) {
                        insert_or_update_item($pdo, $item_data);
                        $totalSyncedForType++;
                    } else {
                        echo "AVVISO: Saltato elemento senza ID valido. Dati: " . json_encode($project) . "\n";
                    }
                }

                // Se il numero di hit è inferiore a PAGE_LIMIT, significa che abbiamo raggiunto l'ultima pagina
                if (count($data['hits']) < PAGE_LIMIT) {
                    echo "Raggiunta l'ultima pagina per il tipo '$p_type'.\n";
                    break;
                }
                $offset += PAGE_LIMIT; // Passa alla pagina successiva
                echo "Elaborati " . count($data['hits']) . " progetti di tipo '$p_type'. Totale sincronizzato per tipo: $totalSyncedForType. Passaggio all'offset: $offset\n";
            }
        }
    } catch (Exception $e) {
        echo "ERRORE INATTESO: Si è verificato un errore inaspettato durante la sincronizzazione: " . $e->getMessage() . "\n";
    } finally {
        // La connessione PDO si chiude automaticamente quando lo script termina o l'oggetto PDO viene distrutto.
        // Non è necessario chiamare $pdo->close() esplicitamente.
        echo "Sincronizzazione completata. Connessione al database chiusa.\n";
    }
}

// --- Esecuzione principale ---
if (php_sapi_name() == 'cli') {
    // Esegui la sincronizzazione solo se lo script è chiamato da riga di comando
    sync_modrinth_data();
} else {
    echo "Questo script è progettato per essere eseguito da riga di comando.\n";
}

?>
