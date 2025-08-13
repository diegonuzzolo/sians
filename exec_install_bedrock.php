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

$scriptPath = "/root/install_bedrock.sh";
$logFile = "/tmp/bedrock_install_$vmid.log";

// Pulisci eventuale log precedente
file_put_contents($logFile, "");

// Esegui script in background
$cmd = "pct exec $vmid -- bash $scriptPath > $logFile 2>&1 & echo $!";
$pid = shell_exec($cmd);

if ($pid) {
    file_put_contents("/tmp/bedrock_install_$vmid.pid", trim($pid)); // salva PID per stop/reset
    // Aggiorna stato a installing
    $stmt = $pdo->prepare("UPDATE servers SET status = ? WHERE id = ?");
    $stmt->execute(['installing', $serverId]);
    echo json_encode([
        'success' => true,
        'log_file' => $logFile,
        'pid' => trim($pid)
    ]);
}
