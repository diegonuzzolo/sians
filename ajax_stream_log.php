<?php
include("auth_check.php");
require 'config/config.php';

set_time_limit(0);
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

try {
    $userId   = $_SESSION['user_id'] ?? 0;
    $serverId = intval($_GET['id'] ?? 0);

    if (!$userId || !$serverId) {
        throw new Exception("Parametri non validi o sessione scaduta.");
    }

    $stmt = $pdo->prepare("SELECT id FROM servers WHERE id = ? AND user_id = ?");
    $stmt->execute([$serverId, $userId]);
    if ($stmt->fetch() === false) {
        throw new Exception("Accesso non autorizzato a questo server.");
    }

    // --- Usa lo stesso DEST_FILE di start.sh ---
    $localLogFile = "/tmp/log_server_" . $serverId . ".txt";

    if (!file_exists($localLogFile)) {
        echo "event: waiting\n";
        echo 'data: {"message": "In attesa che il server avvii lo streaming del log..."}' . "\n\n";
        ob_flush(); flush();
        sleep(5);
        if (!file_exists($localLogFile)) {
            throw new Exception("Il file di log non è disponibile. Contattare l'assistenza.");
        }
    }

    $command = "tail -n 100 -f " . escapeshellarg($localLogFile);
    $handle = popen($command, 'r');

    if ($handle === false) {
        throw new Exception("Impossibile avviare lo streaming dal file di log locale.");
    }

    echo "event: connected\n";
    echo 'data: {"message": "Connessione al log stabilita."}' . "\n\n";
    ob_flush(); flush();

    while (!feof($handle) && connection_status() == CONNECTION_NORMAL) {
        $line = fgets($handle);
        if ($line) {
            echo "data: " . json_encode(['log' => $line]) . "\n\n";
            ob_flush(); flush();
        }
    }

    pclose($handle);

} catch (Exception $e) {
    echo "event: error\n";
    echo 'data: {"message": "' . addslashes($e->getMessage()) . '"}' . "\n\n";
    ob_flush(); flush();
}
