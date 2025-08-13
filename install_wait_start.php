<?php
require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php';

$serverId = intval($_GET['server_id'] ?? 0);
if ($serverId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Server ID non valido']);
    exit;
}

// Recupera VMID dal DB
$stmt = $pdo->prepare("SELECT c.proxmox_containerId AS vmid 
                       FROM containers c 
                       JOIN servers s ON s.container_id = c.id 
                       WHERE s.id = ?");
$stmt->execute([$serverId]);
$vmid = (int)$stmt->fetchColumn();

if (!$vmid) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Container non trovato']);
    exit;
}

try {
    $auth = proxmoxLogin();
    $status = getContainerStatus($vmid, $auth);

    if ($status !== 'running') {
        startContainer($vmid, $auth);
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
