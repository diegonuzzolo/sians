<?php
require 'config/config.php';
require 'proxmox_functions.php';
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

// Recupera ultime 100 righe del log
$payload = [
    "command" => "/bin/bash",
    "extra-args" => ["-c", "tail -n 100 /root/minecraft_bedrock/server.log"]
];

$response = callProxmox("POST", "/nodes/$node/lxc/$vmid/exec", $payload);

if (!empty($response['upid'])) {
    // Recupera output del comando
    $execId = $response['upid'];
    sleep(1); // piccola attesa
    $output = callProxmox("GET", "/nodes/$node/tasks/$execId/log");
    $lines = array_column($output['data'] ?? [], 't'); // testo log
    echo json_encode(['success' => true, 'logs' => $lines]);
} else {
    echo json_encode(['success' => false, 'message' => 'Errore recupero log']);
}
