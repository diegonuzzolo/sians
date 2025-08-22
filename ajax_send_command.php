<?php
include("auth_check.php");
require 'config/config.php';

header('Content-Type: application/json');

$userId = $_SESSION['user_id'] ?? 0;
$serverId = intval($_POST['server_id'] ?? 0);
$command = trim($_POST['command'] ?? '');
$csrf = $_POST['csrf_token'] ?? '';

if ($csrf !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'message' => 'CSRF token non valido']);
    exit;
}

if (!$command) {
    echo json_encode(['success' => false, 'message' => 'Comando vuoto']);
    exit;
}

// Recupera il container/server e l'ID Proxmox
$stmt = $pdo->prepare("SELECT s.*, c.proxmox_containerId FROM servers s LEFT JOIN containers c ON c.id=s.container_id WHERE s.id=? AND s.user_id=?");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    echo json_encode(['success' => false, 'message' => 'Server non trovato']);
    exit;
}

$nodeIp = '192.168.1.251';
$containerId = $server['proxmox_containerId'];
$sessionName = "mc-$containerId";

// Esegui il comando via SSH sul nodo Proxmox
$sshCommand = sprintf(
    'ssh -o StrictHostKeyChecking=no root@%s "screen -S %s -X stuff %s"',
    $nodeIp,
    escapeshellarg($sessionName),
    escapeshellarg($command . "\n")
);

exec($sshCommand, $output, $returnVar);

if ($returnVar === 0) {
    echo json_encode(['success' => true, 'message' => 'Comando inviato con successo']);
} else {
    echo json_encode(['success' => false, 'message' => 'Errore invio comando.']);
}
