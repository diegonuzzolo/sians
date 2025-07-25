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

// Recupera info della VM
$stmt = $pdo->prepare("SELECT ip_address, ssh_user FROM servers WHERE id = ? AND user_id = ?");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    http_response_code(404);
    exit('Server non trovato');
}

$ip = $server['ip_address'];
$sshUser = $server['ssh_user']; // es: 'mcuser'

// Percorsi degli script
$cmds = [
    'start' => "cd ~/server/ && bash start.sh",
    'stop'  => "cd ~/server/ && bash stop.sh"
];

if (!isset($cmds[$action])) {
    http_response_code(400);
    exit('Azione non valida');
}

// Esegui comando SSH
$privateKeyPath = '/home/diego/.ssh/id_rsa'; // Modifica se serve
$sshCommand = "ssh -i $privateKeyPath -o StrictHostKeyChecking=no $sshUser@$ip '{$cmds[$action]}'";
exec($sshCommand . " 2>&1", $output, $exitCode);

if ($exitCode === 0) {
    $status = $action === 'start' ? 'running' : 'stopped';
    $update = $pdo->prepare("UPDATE servers SET status = ? WHERE id = ?");
    $update->execute([$status, $serverId]);
    header('Location: dashboard.php?msg=success');
    exit;
} else {
    error_log("Errore SSH ($exitCode): " . implode("\n", $output));
    header('Location: dashboard.php?msg=ssh_error');
    exit;
}
