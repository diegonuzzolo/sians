<?php
session_start();
require 'config/config.php';
require 'includes/auth.php';
include 'auth_check.php'; // o come si chiama

$userId = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT s.type, s.version FROM servers s WHERE s.id = ? AND s.user_id = ?");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch();

if (!$server) {
    http_response_code(403);
    exit('❌ Accesso negato');
}


if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['server_id'], $_POST['action'], $_POST['proxmox_vmid'])) {
    http_response_code(400);
    exit('❌ Richiesta non valida');
}

$serverId = (int) $_POST['server_id'];
$action = $_POST['action'];
$proxmoxVmid = (int) $_POST['proxmox_vmid'];

if (!in_array($action, ['start', 'stop'])) {
    http_response_code(400);
    exit('❌ Azione non valida');
}

// Recupera IP VM associata
$stmt = $pdo->prepare("SELECT ip FROM minecraft_vms WHERE proxmox_vmid = ?");
$stmt->execute([$proxmoxVmid]);
$vm = $stmt->fetch();

$stmt = $pdo->prepare("SELECT type, version FROM servers WHERE id = ?");
$stmt->execute([$serverId]);
$server = $stmt->fetch();

if (!$server) {
    http_response_code(404);
    exit('❌ Server non trovato');
}

$type = $server['type'];
$version = $server['version'];

if (!$vm) {
    http_response_code(404);
    exit('❌ VM non trovata');
}

$ip = $vm['ip'];
$sshUser = 'diego';
$privateKeyPath = '/var/www/.ssh/id_ed25519_mc';

$remoteCommand = "cd /home/diego/minecraft_servers/{$serverId} && bash " . ($action === 'start' ? "start.sh" : 'stop.sh');
$sshCommand = sprintf(
    'ssh -i %s -o StrictHostKeyChecking=no %s@%s %s',
    escapeshellarg($privateKeyPath),
    $sshUser,
    escapeshellarg($ip),
    escapeshellarg($remoteCommand)
);

// Logging
error_log("[server_action] Comando SSH: $sshCommand");

exec($sshCommand . ' 2>&1', $output, $exitCode);

error_log("[server_action] Output:\n" . implode("\n", $output));
error_log("[server_action] Exit code: $exitCode");

// Aggiorna stato server nel DB
$newStatus = $exitCode === 0
    ? ($action === 'start' ? 'running' : 'stopped')
    : 'error';

try {
    $stmt = $pdo->prepare("UPDATE servers SET status = ? WHERE id = ?");
    $stmt->execute([$newStatus, $serverId]);
} catch (PDOException $e) {
    error_log("[server_action] Errore aggiornamento stato: " . $e->getMessage());
}

header('Location: dashboard.php');
exit;
