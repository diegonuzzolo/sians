<?php
include("auth_check.php");
require 'config/config.php';

$userId = $_SESSION['user_id'] ?? 0;
$serverId = intval($_GET['id'] ?? 0);

if (!$userId || !$serverId) {
    http_response_code(400);
    exit("Parametri mancanti");
}

// Recupera container + nodo
$stmt = $pdo->prepare("SELECT c.proxmox_containerId, c.node_ip
                       FROM servers s
                       LEFT JOIN containers c ON c.id = s.container_id
                       WHERE s.id=? AND s.user_id=?");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    http_response_code(404);
    exit("Server non trovato");
}

$vmid = (int)$server['proxmox_containerId'];
$nodeIp = $server['node_ip'];
$sshKey = '/var/www/.ssh/id_proxmox';
$screenName = "mc-$serverId";

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

$lastLine = 0;

while (true) {
    // Legge log dal container tramite screen
    $cmd = "ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp " .
           escapeshellarg("pct exec $vmid -- tail -n +$lastLine /root/minecraft_bedrock/$serverId/bedrock_server_$serverId.log");

    exec($cmd, $lines, $exitCode);

    if ($exitCode === 0 && !empty($lines)) {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            echo "data: " . json_encode(['log' => $line]) . "\n\n";
            ob_flush(); flush();
        }
        $lastLine += count($lines);
    }

    sleep(1);
}
