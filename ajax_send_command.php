<?php
session_start();
header('Content-Type: application/json');

require 'config/config.php';
require_once 'includes/auth_check.php';

$userId = $_SESSION['user_id'] ?? 0;
$serverId = intval($_POST['server_id'] ?? 0);
$command = trim($_POST['command'] ?? '');
$csrfToken = $_POST['csrf_token'] ?? '';

if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    echo json_encode(['success' => false, 'message' => 'Token CSRF non valido']);
    exit;
}

// Recupera il server e il container
$stmt = $pdo->prepare("
    SELECT s.*, c.proxmox_containerId
    FROM servers s
    LEFT JOIN containers c ON c.id = s.container_id
    WHERE s.id=? AND s.user_id=?
");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    echo json_encode(['success' => false, 'message' => 'Server non trovato']);
    exit;
}

$containerId = $server['proxmox_containerId'];
$nodeIp = '192.168.1.251';
$sshUser = 'root';
$sshKey = '/root/.ssh/id_rsa';

// Il nome della sessione screen deve corrispondere allo script di avvio
$screenSession = "mc-$containerId";

// Comando da inviare dentro screen (aggiungi il newline \n)
$escapedCommand = str_replace('"', '\"', $command);
$screenCommand = "screen -S $screenSession -X stuff \"$escapedCommand\n\"";

// Esecuzione via SSH sul nodo Proxmox
$sshCommand = sprintf(
    'ssh -i %s -o StrictHostKeyChecking=no %s@%s "%s"',
    escapeshellarg($sshKey),
    escapeshellarg($sshUser),
    escapeshellarg($nodeIp),
    $screenCommand
);

exec($sshCommand, $output, $returnVar);

if ($returnVar !== 0) {
    echo json_encode(['success' => false, 'message' => 'Errore nell\'invio del comando']);
} else {
    echo json_encode(['success' => true, 'message' => 'Comando inviato']);
}
