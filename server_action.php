<?php
session_start();
require 'config/config.php';
require 'includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Non autorizzato');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['server_id'], $_POST['action'])) {
    http_response_code(400);
    exit('Richiesta non valida');
}

$userId = $_SESSION['user_id'];
$serverId = intval($_POST['server_id']);
$action = $_POST['action']; // 'start' o 'stop'

// Recupera info della VM e SSH user
$stmt = $pdo->prepare("SELECT vm.ip AS ip_address, s.ssh_user FROM servers s JOIN minecraft_vms vm ON s.vm_id = vm.id WHERE s.id = ? AND s.user_id = ?");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    http_response_code(404);
    exit('Server non trovato');
}

$ip = $server['ip_address'];
$sshUser = $server['ssh_user']; // esempio: 'diego' o 'mcuser'

// Comandi da eseguire sulla VM
$cmds = [
    'start' => "cd ~/server && ./start.sh",
    'stop'  => "cd ~/server && ./stop.sh"
];

if (!isset($cmds[$action])) {
    http_response_code(400);
    exit('Azione non valida');
}

$privateKeyPath = '/home/diego/.ssh/id_rsa'; // modifica se serve

// Costruisci comando SSH senza nohup, perché start.sh usa screen -dmS
$sshCommand = sprintf(
    'ssh -i %s -o StrictHostKeyChecking=no %s@%s "%s"',
    escapeshellarg($privateKeyPath),
    escapeshellarg($sshUser),
    escapeshellarg($ip),
    $cmds[$action]
);

// Esegui e cattura output e codice
exec($sshCommand . " 2>&1", $output, $exitCode);

// Log per debug
error_log("Comando SSH: $sshCommand");
error_log("Output SSH: " . print_r($output, true));
error_log("Exit code SSH: $exitCode");

if ($exitCode === 0) {
    $newStatus = $action === 'start' ? 'running' : 'stopped';

    $update = $pdo->prepare("UPDATE servers SET status = ? WHERE id = ?");
    $update->execute([$newStatus, $serverId]);

    header('Location: dashboard.php?msg=success');
    exit;
} else {
    header('Location: dashboard.php?msg=ssh_error');
    exit;
}
