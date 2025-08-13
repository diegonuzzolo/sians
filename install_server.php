<?php
require 'auth_check.php';
require 'config/config.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$serverId = intval($input['server_id'] ?? 0);

if ($serverId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Server ID non valido']);
    exit;
}

// Recupera dati container
$stmt = $pdo->prepare("SELECT s.*, c.proxmox_containerId AS vmid, c.private_ip
                       FROM servers s
                       JOIN containers c ON c.id = s.container_id
                       WHERE s.id = ?");
$stmt->execute([$serverId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    echo json_encode(['success' => false, 'error' => 'Server non trovato']);
    exit;
}

$containerIp = $server['private_ip'];

// Funzione per connessione SSH
function sshExecute($host, $user, $password, $command, &$output=null) {
    $conn = ssh2_connect($host, 22);
    if (!$conn) return ['success'=>false, 'error'=>'Connessione SSH fallita'];

    if (!ssh2_auth_password($conn, $user, $password)) {
        return ['success'=>false, 'error'=>'Autenticazione SSH fallita'];
    }

    $stream = ssh2_exec($conn, $command);
    if (!$stream) return ['success'=>false, 'error'=>'Esecuzione comando fallita'];

    stream_set_blocking($stream, true);
    $output = stream_get_contents($stream);
    fclose($stream);

    return ['success'=>true, 'output'=>$output];
}

// Attendi che SSH sia disponibile (max 30 tentativi)
$attempts = 0;
$sshReady = false;
while($attempts < 30) {
    $res = sshExecute($containerIp, 'root', 'Lgu8330Serve6', 'echo ready');
    if ($res['success']) {
        $sshReady = true;
        break;
    }
    $attempts++;
    sleep(2);
}

if (!$sshReady) {
    echo json_encode(['success'=>false, 'error'=>'SSH non raggiungibile nel container']);
    exit;
}

// Comandi di installazione Bedrock
$commands = [
    'apt update && apt install -y wget unzip screen',
    'mkdir -p /opt/minecraft-bedrock && cd /opt/minecraft-bedrock',
    'wget -O bedrock-server.zip https://www.minecraft.net/content/dam/minecraft/edition-bedrock/bedrock-server-1.20.10.23.zip',
    'unzip -o bedrock-server.zip -d /opt/minecraft-bedrock',
    'chmod +x /opt/minecraft-bedrock/bedrock_server',
    'echo -e "#!/bin/bash\ncd /opt/minecraft-bedrock\n./bedrock_server" > /opt/minecraft-bedrock/start.sh',
    'chmod +x /opt/minecraft-bedrock/start.sh',
    'echo -e "#!/bin/bash\npkill -f bedrock_server" > /opt/minecraft-bedrock/stop.sh',
    'chmod +x /opt/minecraft-bedrock/stop.sh'
];

// Esegui comandi in sequenza
foreach($commands as $cmd) {
    $out = '';
    $res = sshExecute($containerIp, 'root', 'Lgu8330Serve6', $cmd, $out);
    if (!$res['success']) {
        echo json_encode(['success'=>false, 'error'=>"Errore eseguendo: $cmd", 'output'=>$out]);
        exit;
    }
}

// Aggiorna DB
$stmt = $pdo->prepare("UPDATE servers SET status='ready', updated_at=NOW() WHERE id=?");
$stmt->execute([$serverId]);

echo json_encode(['success'=>true, 'message'=>'Server Bedrock installato con successo']);
