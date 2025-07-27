<?php
session_start();
require 'config/config.php';
require 'includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    error_log("[server_action] Utente non autenticato");
    exit('Non autorizzato');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['id']) || empty($_POST['action'])) {
    http_response_code(400);
    error_log("[server_action] Richiesta non valida");
    exit('Richiesta non valida');
}

$userId = $_SESSION['user_id'];
$serverId = intval($_POST['id']);
$action = $_POST['action'];

$allowedActions = ['start', 'stop'];
if (!in_array($action, $allowedActions, true)) {
    http_response_code(400);
    error_log("[server_action] Azione non valida: $action");
    exit('Azione non valida');
}

// Recupera l'IP della VM e la vm_id associata al server
$stmt = $pdo->prepare("
    SELECT vm.ip AS ip_address, vm.vmid AS vmid
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
$vmId = $server['vmid'] ?? null;

if (!$ip || !$vmId) {
    error_log("[server_action] IP o vmid mancante per server_id=$serverId");
    exit('IP o VMID mancante');
}

$sshUser = 'diego';
$privateKeyPath = '/var/www/.ssh/id_rsa';

$remoteDir = "/home/diego/$vmId";
$scriptName = $action === 'start' ? 'start.sh' : 'stop.sh';
$remoteCommand = "bash $remoteDir/$scriptName";

// Comando SSH
$sshCommand = 'ssh -i ' . escapeshellarg($privateKeyPath) .
              ' -o StrictHostKeyChecking=no ' .
              escapeshellarg("$sshUser@$ip") . ' ' .
              escapeshellarg($remoteCommand);

// Log per debug
error_log("[server_action] Comando SSH: ssh -i <key> $sshUser@$ip '$remoteCommand'");

// Esecuzione comando
exec($sshCommand . ' 2>&1', $output, $exitCode);

error_log("[server_action] Exit code: $exitCode");
error_log("[server_action] Output:\n" . implode("\n", $output));

if ($exitCode === 0) {
    $newStatus = $action === 'start' ? 'running' : 'stopped';
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
    echo "[server_action] Errore durante l'esecuzione SSH:\n" . implode("\n", $output);
    exit;
}
