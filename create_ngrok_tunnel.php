<?php
require 'config/config.php';
require 'includes/auth.php';

$serverId = $_POST['server_id'] ?? null;

if (!$serverId) {
    http_response_code(400);
    echo "ID server mancante";
    exit;
}

// Verifica che il server appartenga all'utente
$stmt = $pdo->prepare("SELECT * FROM servers WHERE id = ? AND user_id = ?");
$stmt->execute([$serverId, $_SESSION['user_id']]);
$server = $stmt->fetch();

if (!$server) {
    http_response_code(404);
    echo "Server non trovato o non autorizzato.";
    exit;
}

// Avvia tunnel ngrok per la porta 25565 della VM (Minecraft)
$vmIp = $server['vm_ip'];
$command = "sudo -u www-data ngrok  tcp {$vmIp}:25565 --region=eu --log=stdout --log-format=logfmt";
$descriptors = [
    1 => ['pipe', 'w'], // stdout
    2 => ['pipe', 'w'], // stderr
];

$process = proc_open($command, $descriptors, $pipes);

if (!is_resource($process)) {
    echo "Errore nell'avvio del processo ngrok.";
    exit;
}

$timeout = 10; // secondi
$startTime = time();
$ngrokHost = null;
$ngrokPort = null;

while (time() - $startTime < $timeout) {
    $line = fgets($pipes[1]);

    if (preg_match('/url=tcp:\/\/([^:]+):(\d+)/', $line, $matches)) {
        $ngrokHost = $matches[1];
        $ngrokPort = $matches[2];
        break;
    }
    usleep(100000); // 100 ms
}

if (!$ngrokHost || !$ngrokPort) {
    echo "Errore: impossibile ottenere l'endpoint TCP da ngrok.";
    proc_terminate($process);
    exit;
}

// Salva l'endpoint ngrok nel DB
$update = $pdo->prepare("UPDATE servers SET ngrok_tcp_host = ?, ngrok_tcp_port = ? WHERE id = ? AND user_id = ?");
$update->execute([$ngrokHost, $ngrokPort, $serverId, $_SESSION['user_id']]);

// (Opzionale) Salva il PID per future chiusure
file_put_contents(__DIR__ . "/pids/server_{$serverId}.pid", proc_get_status($process)['pid']);

echo "Ngrok TCP attivo su: {$ngrokHost}:{$ngrokPort}";
