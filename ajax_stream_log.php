<?php
// ajax_stream_log.php (Versione Stabile con Lettura Locale)

// Impostazioni per il debug
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Includi i file di configurazione e autenticazione
include("auth_check.php");
require 'config/config.php';

// Disabilita il time limit per lo script, dato che è uno stream di lunga durata
set_time_limit(0);

// Imposta gli header essenziali per lo streaming SSE (Server-Sent Events)
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
// Necessario per evitare che Nginx o altri proxy bufferizzino l'output
header('X-Accel-Buffering: no');

try {
    // 1. Validazione e Autorizzazione
    $userId   = $_SESSION['user_id'] ?? 0;
    $serverId = intval($_GET['id'] ?? 0);

    if (!$userId || !$serverId) {
        throw new Exception("Parametri non validi o sessione scaduta.");
    }

    // Verifica che l'utente sia il proprietario del server richiesto (passaggio di sicurezza)
    $stmt = $pdo->prepare("SELECT id FROM servers WHERE id = ? AND user_id = ?");
    $stmt->execute([$serverId, $userId]);
    if ($stmt->fetch() === false) {
        throw new Exception("Accesso non autorizzato a questo server.");
    }

    // 2. Definizione del Percorso del File di Log Locale
    // Questo file viene creato e aggiornato costantemente dal tuo demone/worker in background.
    $localLogFile = "/tmp/log_server_" . $serverId . ".txt";

    // 3. Verifica dell'Esistenza del File
    // Se il file non esiste, significa che il demone non lo sta ancora monitorando.
    if (!file_exists($localLogFile)) {
        // Invia un evento specifico al client per informarlo
        echo "event: waiting\n";
        echo 'data: {"message": "In attesa che il server avvii lo streaming del log..."}' . "\n\n";
        ob_flush();
        flush();
        
        // Attendi qualche secondo per dare tempo al demone di creare il file
        sleep(5); 

        // Se ancora non esiste, esci
        if (!file_exists($localLogFile)) {
             throw new Exception("Il file di log non è disponibile. Contattare l'assistenza.");
        }
    }
    
    // 4. Avvio dello Streaming dal File Locale
    // Usiamo `tail -f` sul file LOCALE. Questa operazione è estremamente leggera per il web server.
    $command = "tail -n 100 -f " . escapeshellarg($localLogFile);
    $handle = popen($command, 'r');

    if ($handle === false) {
        throw new Exception("Impossibile avviare lo streaming dal file di log locale.");
    }
    
    // Invia un evento di connessione riuscita
    echo "event: connected\n";
    echo 'data: {"message": "Connessione al log stabilita."}' . "\n\n";
    ob_flush();
    flush();

    // 5. Loop di Lettura e Invio Dati
    // Legge l'output di `tail -f` riga per riga e lo invia al client.
    while (!feof($handle) && connection_status() == CONNECTION_NORMAL) {
        $line = fgets($handle);
        if ($line) {
            // Formatta il messaggio per SSE e lo invia al client
            echo "data: " . json_encode(['log' => $line]) . "\n\n";

            // Forza l'invio immediato dei dati nel buffer di output
            ob_flush();
            flush();
        }
    }

    pclose($handle);

} catch (Exception $e) {
    // In caso di errore, invia un evento di errore specifico al client
    echo "event: error\n";
    echo 'data: {"message": "' . addslashes($e->getMessage()) . '"}' . "\n\n";
    ob_flush();
    flush();
}