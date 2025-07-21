<?php
require 'config/config.php';
require 'includes/auth.php';

$serverId = $_POST['server_id'] ?? null;
$action = $_POST['action'] ?? null;

if (!$serverId || !in_array($action, ['start', 'stop'])) {
    http_response_code(400);
    exit('Richiesta non valida');
}

// Verifica il server dell'utente
$stmt = $pdo->prepare("SELECT s.*, v.proxmox_vmid FROM servers s
                       JOIN minecraft_vms v ON v.assigned_server_id = s.id
                       WHERE s.id = ? AND s.user_id = ?");
$stmt->execute([$serverId, $_SESSION['user_id']]);
$server = $stmt->fetch();

if (!$server) {
    http_response_code(403);
    exit('Non autorizzato o server non trovato');
}

// Comando Proxmox
$vmid = $server['proxmox_vmid'];
if ($action === 'start') {
    shell_exec("qm start $vmid");
    $status = 'attivo';
} else {
    shell_exec("qm shutdown $vmid");
    $status = 'spento';
}

// Aggiorna stato nel DB
$stmt = $pdo->prepare("UPDATE servers SET status = ? WHERE id = ?");
$stmt->execute([$status, $serverId]);

header("Location: dashboard.php");
exit;
?>