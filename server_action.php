<?php
include("auth_check.php");
require 'config/config.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['id'], $input['action'])) {
    echo json_encode(['success' => false, 'error' => 'Parametri mancanti']);
    exit;
}

$serverId = intval($input['id']);
$action = $input['action'];

$userId = $_SESSION['user_id'] ?? 0;

// Recupera info server e container
$stmt = $pdo->prepare("SELECT s.*, c.ip, c.hostname FROM servers s JOIN containers c ON s.container_id = c.id WHERE s.id = ? AND s.user_id = ?");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    echo json_encode(['success' => false, 'error' => 'Server non trovato o accesso negato']);
    exit;
}

$statusAllowed = ['start', 'stop'];
if (!in_array($action, $statusAllowed)) {
    echo json_encode(['success' => false, 'error' => 'Azione non valida']);
    exit;
}

try {
    // Costruisci comando SSH da eseguire sulla VM (esempio)
    // Qui devi personalizzare il comando per avviare/fermare il server Minecraft, 
    // basandoti su come è gestito il server (es. script start.sh, stop.sh)

    $sshUser = 'minecraft'; // o altro utente che usi
    $containerIp = $server['ip']; // IP della VM/container

    if ($action === 'start') {
        $cmd = "ssh -o ConnectTimeout=5 {$sshUser}@{$containerIp} 'screen -dmS mcserver ./start.sh'";
        $newStatus = 'running';
    } else { // stop
        $cmd = "ssh -o ConnectTimeout=5 {$sshUser}@{$containerIp} './stop.sh'";
        $newStatus = 'stopped';
    }

    // Esegui comando remoto
    exec($cmd, $output, $returnVar);

    if ($returnVar !== 0) {
        throw new Exception("Errore esecuzione comando remoto");
    }

    // Aggiorna stato nel DB
    $stmt = $pdo->prepare("UPDATE servers SET status = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$newStatus, $serverId]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
