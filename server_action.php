<?php
session_start();
require 'config/config.php';
require 'includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    error_log("[server_action] Utente non autenticato");
    exit('Non autorizzato');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($server['id']) || empty($_POST['action'])) {
    http_response_code(400);
    error_log("[server_action] Richiesta non valida");
    exit('Richiesta non valida');
}

$userId = $_SESSION['user_id'];
$serverId = intval($_POST['id']);  // ID della tabella 'servers'
$action = $_POST['action'];

$allowedActions = ['start', 'stop'];
if (!in_array($action, $allowedActions, true)) {
    http_response_code(400);
    error_log("[server_action] Azione non valida: $action");
    exit('Azione non valida');
}

// Recupera IP e VM_ID associato al server
$stmt = $pdo->prepare("
    SELECT vm.ip AS ip_address, vm.id AS vm_id
    FROM servers s
    JOIN minecraft_vms vm ON s.vm_id = vm.id
    WHERE s.id = ? AND s.user_id = ?
    LIMIT 1
");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    http_response_code(404);
    error_log("[server_action] Server non trovato per user_id=$userId e server_id=$serverId");
    exit('Server non trovato');
}

$ip = $server['ip_address'] ?? null;
$vmId = $server['vm_id'] ?? null;

if (!$ip || !$vmId) {
    error_log("[server_action] IP o VM_ID mancante per server_id=$serverId");
    exit('IP o VM_ID mancante');
}

$sshUser = 'diego';
$privateKeyPath = '/var/www/.ssh/id_rsa';

// ✅ Usa vm_id per il path remoto
$remoteDir = "/home/diego/$vmId";
$scriptName = ($action === 'start') ? 'start.sh' : 'stop.sh';
$remoteCommand = "bash $remoteDir/$scriptName";

// Comando SSH completo
$sshCommand =
    'ssh -i ' . escapeshellarg($privateKeyPath) .
    ' -o StrictHostKeyChecking=no ' .
    escapeshellarg("$sshUser@$ip") . ' ' .
    escapeshellarg($remoteCommand);

// Log utile
error_log("[server_action] Comando SSH: $sshCommand");

// Esecuzione
exec($sshCommand . ' 2>&1', $output, $exitCode);

error_log("[server_action] Exit code: $exitCode");
error_log("[server_action] Output:\n" . implode("\n", $output));

if ($exitCode === 0) {
    $newStatus = ($action === 'start') ? 'running' : 'stopped';
    $update = $pdo->prepare("UPDATE servers SET status = ? WHERE id = ?");
    $res = $update->execute([$newStatus, $serverId]);

    if ($res) {
        error_log("[server_action] Stato aggiornato a '$newStatus' per server_id=$serverId");
    } else {
        error_log("[server_action] Fallito aggiornamento stato per server_id=$serverId");
    }

    header('Location: dashboard.php?msg=success');
    exit;
} else {
    error_log("[server_action] Errore comando SSH (exitCode=$exitCode)");
    echo "[server_action] Esecuzione comando SSH: $sshCommand\n";
    echo "[server_action] Output:\n" . implode("\n", $output);
    exit;
}
