<?php
require 'auth_check.php';
require 'config/config.php';
require 'includes/csrf.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Metodo non consentito.']);
    exit;
}
csrf_check_or_die();

$serverId = intval($_POST['server_id'] ?? 0);
$userId = $_SESSION['user_id'] ?? 0;
$command = trim($_POST['command'] ?? '');

$stmt = $pdo->prepare("SELECT proxmox_containerId FROM servers WHERE id=? AND user_id=?");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server || empty($command)) {
    echo json_encode(['success' => false, 'message' => 'Dati non validi o accesso negato.']);
    exit;
}

$containerId = $server['proxmox_containerId'];
$proxmox_host = '192.168.1.251'; // Es. 192.168.1.251
$ssh_key_path = '/var/www/.ssh/id_proxmox';

// Sanifica il comando per evitare injection nel comando shell
$escaped_command = escapeshellarg($command);

// Costruisce il comando screen da eseguire dentro il container
$screen_command = "screen -S minecraft_server -p 0 -X stuff {$escaped_command}$'\\n'";

// Costruisce il comando SSH completo
$full_ssh_command = "ssh -i {$ssh_key_path} -o StrictHostKeyChecking=no root@{$proxmox_host} "
                  . escapeshellarg("pct exec {$containerId} -- bash -c " . escapeshellarg($screen_command));

exec($full_ssh_command . " 2>&1", $output, $return_var);

if ($return_var === 0) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Impossibile eseguire il comando via SSH.', 'details' => $output]);
}