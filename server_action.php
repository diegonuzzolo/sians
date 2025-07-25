<?php
session_start();
require 'config/config.php';
require 'includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    error_log("[server_action] Utente non autenticato");
    exit('Non autorizzato');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['server_id'], $_POST['action'])) {
    http_response_code(400);
    error_log("[server_action] Richiesta non valida");
    exit('Richiesta non valida');
}

$userId = $_SESSION['user_id'];
$serverId = intval($_POST['server_id']);
$action = $_POST['action']; // 'start' o 'stop'

// Recupera info server e VM
$stmt = $pdo->prepare("
    SELECT vm.ip AS ip_address, s.ssh_user
    FROM servers s
    JOIN minecraft_vms vm ON s.vm_id = vm.id
    WHERE s.id = ? AND s.user_id = ?
");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    http_response_code(404);
    error_log("[server_action] Server non trovato per user_id=$userId e server_id=$serverId");
    exit('Server non trovato');
}

$ip = $server['ip_address'] ?? null;
$sshUser = $server['ssh_user'] ?? null;

if (!$ip || !$sshUser) {
    error_log("[server_action] Dati mancanti: ip=$ip, sshUser=$sshUser");
    exit('Dati server incompleti');
}

$cmds = [
    'start' => "cd ~/server && ./start.sh",
    'stop'  => "cd ~/server && ./stop.sh"
];

if (!isset($cmds[$action])) {
    http_response_code(400);
    error_log("[server_action] Azione non valida: $action");
    exit('Azione non valida');
}

$privateKeyPath = '/home/diego/.ssh/id_rsa';

// Costruisco comando SSH
$sshCommand = sprintf(
    'ssh -i %s -o StrictHostKeyChecking=no %s@%s "%s"',
    escapeshellarg($privateKeyPath),
    escapeshellarg($sshUser),
    escapeshellarg($ip),
    $cmds[$action]
);

error_log("[server_action] Esecuzione comando SSH: $sshCommand");

// Eseguo e catturo output e exit code
exec($sshCommand . " 2>&1", $output, $exitCode);

error_log("[server_action] Exit code: $exitCode");
error_log("[server_action] Output:\n" . implode("\n", $output));

if ($exitCode === 0) {
    $newStatus = $action === 'start' ? 'running' : 'stopped';
    $update = $pdo->prepare("UPDATE servers SET status = ? WHERE id = ?");
    $res = $update->execute([$newStatus, $serverId]);

    if ($res) {
        error_log("[server_action] Status aggiornato a '$newStatus' per server_id=$serverId");
    } else {
        error_log("[server_action] Fallito aggiornamento status per server_id=$serverId");
    }

    header('Location: dashboard.php?msg=success');
    exit;
} else {
    error_log("[server_action] Errore esecuzione comando SSH, exitCode=$exitCode");
    header('Location: dashboard.php?msg=ssh_error');
    exit;
}
