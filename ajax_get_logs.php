<?php
require 'auth_check.php';
require 'config/config.php';
require_once 'includes/csrf.php';

header('Content-Type: application/json');
csrf_check_or_die();

$serverId = intval($_GET['server_id'] ?? 0);
$userId = $_SESSION['user_id'] ?? 0;
$offset = intval($_GET['offset'] ?? 0);

// Recupera i dati del container dal DB
$stmt = $pdo->prepare("SELECT s.name, c.proxmox_containerId, c.node 
                       FROM servers s 
                       JOIN containers c ON c.id = s.container_id 
                       WHERE s.id = ? AND s.user_id = ?");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    echo json_encode(['success' => false, 'log' => '', 'new_size' => 0]);
    exit;
}

$vmid = $server['proxmox_containerId'];
$node = $server['node'];

// Chiave SSH generata manualmente
$sshKey = '/var/www/.ssh/id_rsa_container';
$nodeIp = '192.168.1.251'; // IP del nodo Proxmox

// File di log temporaneo nel container
$logFile = '/root/minecraft.log';

try {
    // Legge il log dal container usando ssh + pct exec
    $cmd = "ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- tail -n +$offset $logFile\"";
    $output = shell_exec($cmd);

    if ($output === null) $output = '';

    $lines = explode("\n", trim($output));
    $htmlLog = '';
    foreach ($lines as $line) {
        $htmlLog .= '<span>' . htmlspecialchars($line) . '</span>';
    }

    $newSize = $offset + count($lines);

    echo json_encode(['success' => true, 'log' => $htmlLog, 'new_size' => $newSize]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'log' => '', 'new_size' => 0]);
}
