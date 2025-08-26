<?php
include("auth_check.php");
require 'config/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['csrf_token'], $_POST['server_id'], $_POST['command'])) {
    echo json_encode(['success' => false, 'message' => 'Dati mancanti.']);
    exit;
}

if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'message' => 'Token CSRF non valido.']);
    exit;
}

$userId = $_SESSION['user_id'] ?? 0;
$serverId = intval($_POST['server_id']);
$command = trim($_POST['command']);

if ($command === '') {
    echo json_encode(['success' => false, 'message' => 'Comando vuoto.']);
    exit;
}

// Recupera VMID e nome del server
$stmt = $pdo->prepare("
    SELECT c.proxmox_containerId, s.name
    FROM servers s
    LEFT JOIN containers c ON c.id = s.container_id
    WHERE s.id = ? AND s.user_id = ?
");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server || empty($server['proxmox_containerId'])) {
    echo json_encode(['success' => false, 'message' => 'Container non trovato.']);
    exit;
}

$vmid = intval($server['proxmox_containerId']);
$screenSession = 'mc-' . $server['name'];

$proxmoxUser = 'root';
$proxmoxHost = '192.168.1.251';
$privateKeyPath = '/var/www/.ssh/id_proxmox';

// Escape comando: apici singoli e newline finale
$escapedCommand = str_replace("'", "'\\''", $command) . "\\n";

// Comando completo
$sshCommand = sprintf(
    'ssh -i %s -o StrictHostKeyChecking=no %s@%s "pct exec %d -- screen -S \'%s\' -X stuff \'%s\'"',
    escapeshellarg($privateKeyPath),
    escapeshellarg($proxmoxUser),
    escapeshellarg($proxmoxHost),
    $vmid,
    $screenSession,
    $escapedCommand
);

exec($sshCommand, $output, $returnVar);

if ($returnVar !== 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Errore invio comando tramite SSH.',
        'debug' => implode("\n", $output)
    ]);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Comando inviato correttamente.']);
