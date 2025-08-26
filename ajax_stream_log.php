<?php
// ajax_stream_log.php

ini_set('display_errors', 1);
error_reporting(E_ALL);

include("auth_check.php");
require 'config/config.php';

// 1. Imposta gli header essenziali per lo streaming SSE
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
// Necessario per evitare che Nginx o altri proxy bufferizzino l'output
header('X-Accel-Buffering: no');

try {
    $userId   = $_SESSION['user_id'] ?? 0;
    $serverId = intval($_GET['id'] ?? 0);

    if (!$userId || !$serverId) {
        throw new Exception("Parametri non validi");
    }

    // 2. Recupera le info del server una sola volta all'inizio
    $stmt = $pdo->prepare("
        SELECT s.proxmox_containerId
        FROM servers s
        LEFT JOIN containers c ON s.container_id = c.id
        WHERE s.id = ? AND s.user_id = ?
    ");
    $stmt->execute([$serverId, $userId]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$server || empty($server['proxmox_containerId'])) {
        throw new Exception("Server non trovato o proxmox_containerId mancante.");
    }

    $vmid          = intval($server['proxmox_containerId']);
    $nodeIp        = '192.168.1.251';
    $sshKey        = '/var/www/.ssh/id_proxmox';
    $remoteLogPath = '/root/minecraft_bedrock/server.log';

    // 3. MODIFICA CHIAVE: Usa `tail -f` per "seguire" il file
    // `tail -n 50 -f` invia le ultime 50 righe e poi attende le nuove.
    $cmd = sprintf(
        'sudo ssh -i %s -o StrictHostKeyChecking=no -o ConnectTimeout=10 root@%s "pct exec %d -- tail -n 50 -f %s"',
        escapeshellarg($sshKey),
        escapeshellarg($nodeIp),
        $vmid,
        escapeshellarg($remoteLogPath)
    );

    // 4. MODIFICA CHIAVE: Usa popen() per aprire un pipe al processo
    // Questo ci permette di leggere l'output del comando riga per riga man mano che arriva.
    $handle = popen($cmd, 'r');

    if ($handle === false) {
        throw new Exception("Impossibile eseguire il comando di streaming del log.");
    }
    
    // Invia un evento di connessione riuscita (opzionale)
    echo "event: connected\n";
    echo 'data: {"message": "Connessione al log stabilita..."}' . "\n\n";
    ob_flush();
    flush();

    // 5. Loop che legge l'output e lo invia al client
    while (!feof($handle)) {
        // Legge una riga dall'output del comando `tail -f`
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
    // In caso di errore, invia un evento di errore al client
    echo "event: error\n";
    echo 'data: {"message": "' . addslashes($e->getMessage()) . '"}' . "\n\n";
    ob_flush();
    flush();
}