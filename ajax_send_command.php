<?php
include("auth_check.php");
require 'config/config.php';

header('Content-Type: application/json');

// Controllo CSRF
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

// Recupera VMID del container e nome del server
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
$screenSession = 'mc-' . $server['name']; // SESSION_NAME="mc-<nome_server>"

// SSH verso il nodo Proxmox
$proxmoxUser = 'root'; // o altro utente con accesso pct
$proxmoxHost = '192.168.1.251';
$escapedCommand = str_replace("'", "'\\''", $command) . "\\n";

$sshCommand = "ssh -o StrictHostKeyChecking=no $proxmoxUser@$proxmoxHost 'pct exec $vmid -- screen -S \"$screenSession\" -X stuff \'$escapedCommand\''";

// Esegui comando
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
