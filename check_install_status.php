<?php
require 'auth_check.php';
require 'config/config.php';

$serverId = intval($_GET['server_id'] ?? 0);
if ($serverId <= 0) die(json_encode(['status' => 'error']));

$stmt = $pdo->prepare("SELECT c.proxmox_containerId FROM servers s JOIN containers c ON c.id = s.container_id WHERE s.id = ?");
$stmt->execute([$serverId]);
$vmid = $stmt->fetchColumn();

$pidFile = "/tmp/bedrock_install_$vmid.pid";
$logFile = "/tmp/bedrock_install_$vmid.log";

$status = 'installing';
if (file_exists($pidFile)) {
    $pid = trim(file_get_contents($pidFile));
    if (!posix_kill((int)$pid, 0)) {
        $status = 'ready';
        unlink($pidFile);
    }
} else {
    $status = 'ready';
}

echo json_encode(['status' => $status]);
