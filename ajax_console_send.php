<?php
require 'config/config.php';
require 'proxmox_functions.php'; // contiene callProxmox()
session_start();

$userId = $_SESSION['user_id'] ?? 0;
$serverId = intval($_POST['server_id'] ?? 0);
$csrf = $_POST['csrf_token'] ?? '';

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

$cmd = trim($_POST['command'] ?? '');
if ($cmd === '') {
    echo json_encode(['success' => false, 'message' => 'Comando vuoto']);
    exit;
}

$vmid = $server['proxmox_containerId'];
$node = $server['node'];

// Invio comando dentro screen
$payload = [
    "command" => "/bin/bash",
    "extra-args" => ["-c", "screen -S bedrock_$vmid -p 0 -X stuff " . escapeshellarg($cmd . "^M")]
];

$response = callProxmox("POST", "/nodes/$node/lxc/$vmid/exec", $payload);

if (!empty($response['upid'])) {
    echo json_encode(['success' => true, 'message' => "Comando inviato: $cmd"]);
} else {
    echo json_encode(['success' => false, 'message' => "Errore invio comando"]);
}
