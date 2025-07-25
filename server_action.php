<?php
session_start();
require 'config/config.php';
require 'includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    error_log("[server_action] Utente non autenticato");
    exit('Non autorizzato');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['server_id']) || empty($_POST['action'])) {
    http_response_code(400);
    error_log("[server_action] Richiesta non valida");
    exit('Richiesta non valida');
}

$userId = $_SESSION['user_id'];
$serverId = intval($_POST['server_id']);
$action = $_POST['action']; // aspettato 'start' o 'stop'

// Verifica azione valida
$allowedActions = ['start', 'stop'];
if (!in_array($action, $allowedActions, true)) {
    http_response_code(400);
    error_log("[server_action] Azione non valida: $action");
    exit('Azione non valida');
}

// Recupera IP VM associata al server dell'utente
$stmt = $pdo->prepare("
    SELECT vm.ip AS ip_address
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
if (!$ip) {
    error_log("[server_action] IP VM mancante per server_id=$serverId");
    exit('IP VM mancante');
}

$sshUser = 'diego'; // utente SSH fisso
$privateKeyPath = '/home/diego/.ssh/id_rsa';

// Comandi da eseguire sulla VM
$commands = [
    'start' => 'cd ~/server && bash start.sh',
    'stop' => 'cd ~/server && bash stop.sh',
];

// Costruzione comando SSH con escapeshellarg per sicurezza
$sshCommand = sprintf(
    'ssh -i %s -o StrictHostKeyChecking=no %s@%s %s',
    escapeshellarg($privateKeyPath),
    escapeshellarg($sshUser),
    escapeshellarg($ip),
    escapeshellarg($commands[$action])
);

error_log("[server_action] Esecuzione comando SSH: $sshCommand");

// Esecuzione comando SSH
exec($sshCommand . ' 2>&1', $output, $exitCode);

error_log("[server_action] Exit code: $exitCode");
error_log("[server_action] Output:\n" . implode("\n", $output));

if ($exitCode === 0) {
    // Aggiorna stato server nel DB
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
