<?php
require 'auth_check.php';
require 'config/config.php';
require_once 'includes/csrf.php';

header('Content-Type: application/json');
csrf_check_or_die();

$serverId = intval($_POST['server_id'] ?? 0);
$command = trim($_POST['command'] ?? '');
$userId = $_SESSION['user_id'] ?? 0;

if (!$command) {
    echo json_encode(['success' => false, 'message' => 'Comando vuoto.']);
    exit;
}

// Recupera il container
$stmt = $pdo->prepare("SELECT c.proxmox_containerId, c.node 
                       FROM servers s 
                       JOIN containers c ON c.id = s.container_id 
                       WHERE s.id = ? AND s.user_id = ?");
$stmt->execute([$serverId, $userId]);
$container = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$container) {
    echo json_encode(['success' => false, 'message' => 'Server non trovato.']);
    exit;
}

$vmid = $container['proxmox_containerId'];
$node = $container['node'];

$sshKey = '/var/www/.ssh/id_rsa_container';
$nodeIp = '192.168.1.251';

try {
    // Invia il comando al container tramite pct exec
    $cmd = "ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- screen -S minecraft_server -p 0 -X stuff '{$command}\n'\"";
    shell_exec($cmd);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
