<?php
require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php';

header('Content-Type: application/json');

$serverId = intval($_GET['server_id'] ?? 0);
if ($serverId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Server ID non valido']);
    exit;
}

// Recupera container
$stmt = $pdo->prepare("SELECT c.proxmox_containerId FROM servers s JOIN containers c ON c.id = s.container_id WHERE s.id = ?");
$stmt->execute([$serverId]);
$vmid = $stmt->fetchColumn();

if (!$vmid) {
    echo json_encode(['success' => false, 'message' => 'Container non trovato']);
    exit;
}

// Trova PID del processo in esecuzione (log file temporaneo)
$logFile = "/tmp/bedrock_install_$vmid.log";
$pidFile = "/tmp/bedrock_install_$vmid.pid";
if (file_exists($pidFile)) {
    $pid = intval(file_get_contents($pidFile));
    if ($pid) {
        // Termina il processo
        exec("kill -9 $pid");
        unlink($pidFile);
    }
}

// Pulizia log
if (file_exists($logFile)) unlink($logFile);

// Resetta stato server
$stmt = $pdo->prepare("UPDATE servers SET status = ? WHERE id = ?");
$stmt->execute(['ready_to_install', $serverId]);

echo json_encode(['success' => true]);
