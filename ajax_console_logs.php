<?php
require 'config/config.php';
require 'proxmox_api.php';
session_start();

$userId = $_SESSION['user_id'] ?? 0;
$serverId = intval($_GET['id'] ?? 0);
$csrf = $_GET['csrf_token'] ?? '';

if ($csrf !== ($_SESSION['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'CSRF non valido']);
    exit;
}

// Recupera container associato
$stmt = $pdo->prepare("SELECT c.proxmox_containerId, c.node 
    FROM servers s 
    JOIN containers c ON s.container_id=c.id 
    WHERE s.id=? AND s.user_id=?");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch();
if (!$server) {
    echo json_encode(['success' => false, 'message' => 'Server non trovato']);
    exit;
}

$vmid = $server['proxmox_containerId'];
$node = $server['node'];

// Avvia il comando "tail"
$payload = [
    "command" => "/bin/bash",
    "extra-args" => ["-c", "tail -n 100 /root/minecraft_bedrock/server.log"]
];

$exec = callProxmox("POST", "/nodes/$node/lxc/$vmid/exec", $payload);

if (empty($exec['data']['pid'])) {
    echo json_encode(['success' => false, 'message' => 'Errore avvio exec']);
    exit;
}

$pid = $exec['data']['pid'];

// Attendi un attimo che il comando finisca
usleep(500000);

// Recupera stdout
$output = callProxmox("GET", "/nodes/$node/lxc/$vmid/exec/$pid/stdout");
$logs = $output['data'] ?? [];

echo json_encode(['success' => true, 'logs' => $logs]);
