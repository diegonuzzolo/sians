<?php
session_start();
require 'config/config.php';

header('Content-Type: application/json');

$userId   = $_SESSION['user_id'] ?? 0;
$serverId = intval($_POST['server_id'] ?? 0);
$command  = trim($_POST['command'] ?? '');

if (!$userId || !$serverId || !$command) {
    echo json_encode(["success" => false, "message" => "Parametri mancanti"]);
    exit;
}

// Recupera server + container + nodo
$stmt = $pdo->prepare("
    SELECT s.id, s.name, c.proxmox_containerId, c.node_ip
    FROM servers s
    LEFT JOIN containers c ON c.id = s.container_id
    WHERE s.id=? AND s.user_id=?
");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    echo json_encode(["success" => false, "message" => "Server non trovato"]);
    exit;
}

$containerId = (int)$server['proxmox_containerId'];
$screenName  = "mc-" . $server['name'];
$nodeIp      = $server['node_ip'];   // IP del nodo remoto
$sshKey      = '/var/www/.ssh/id_proxmox';

// Escaping sicuro del comando
$escapedCmd = escapeshellarg($command);

// Costruisci comando remoto via SSH
$remoteCmd = "pct exec $containerId -- screen -S $screenName -p 0 -X stuff {$escapedCmd}$(printf '\r')";
$sshCmd = "ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp " . escapeshellarg($remoteCmd);

exec($sshCmd, $output, $exitCode);

if ($exitCode === 0) {
    echo json_encode(["success" => true, "message" => "Comando inviato"]);
} else {
    echo json_encode([
        "success" => false,
        "message" => "Errore durante l'invio",
        "debug"   => $output
    ]);
}
