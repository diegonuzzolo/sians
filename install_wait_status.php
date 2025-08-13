<?php
require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php';

$serverId = intval($_GET['server_id'] ?? 0);
if ($serverId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Server ID non valido']);
    exit;
}

// Recupera dati server dal DB
$stmt = $pdo->prepare("SELECT s.*, c.proxmox_containerId AS vmid 
                       FROM servers s 
                       JOIN containers c ON c.id = s.container_id 
                       WHERE s.id = ?");
$stmt->execute([$serverId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    http_response_code(404);
    echo json_encode(['error' => 'Server non trovato']);
    exit;
}

$vmid = (int)$server['vmid'];
$installation_started = $server['status'] !== 'not_installed';

try {
    $auth = proxmoxLogin();
    $container_status = getContainerStatus($vmid, $auth);

    echo json_encode([
        'container_status' => $container_status,
        'installation_started' => $installation_started
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
