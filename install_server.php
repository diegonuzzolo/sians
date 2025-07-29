<?php
// install_server.php

require 'config/config.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

// ✅ Accetta parametri da GET o CLI
$type         = $_GET['type']         ?? $argv[1] ?? null;
$version      = $_GET['version']      ?? $argv[2] ?? null;
$slug         = $_GET['modpack_slug'] ?? $argv[3] ?? null;
$serverId     = $_GET['server_id']    ?? $argv[4] ?? null;

// 🔒 Verifica parametri minimi
if (!$type || !$serverId) {
    echo "❌ Parametri mancanti.\n";
    exit;
}

// ✅ Recupera IP VM dal DB
$stmt = $pdo->prepare("SELECT ip FROM minecraft_vms WHERE assigned_server_id = ?");
$stmt->execute([$serverId]);
$vm = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$vm) {
    echo "❌ Nessuna VM trovata per server_id $serverId\n";
    exit;
}

$vmIp = $vm['ip'];
$sshUser = 'diego'; // fisso

// ✅ Comando remoto da eseguire sulla VM
$escapedType    = escapeshellarg($type);
$escapedVersion = escapeshellarg($version ?? '');
$escapedSlug    = escapeshellarg($slug ?? '');
$escapedId      = escapeshellarg($serverId);

// 🧠 Costruisce il comando SSH
$remoteCommand = "bash /home/diego/setup_server.sh $escapedType $escapedVersion $escapedSlug auto $escapedId";

// ✅ Avvia installazione via SSH
echo "🚀 Avvio installazione server su VM $vmIp...\n";
exec("ssh -o StrictHostKeyChecking=no $sshUser@$vmIp '$remoteCommand' > /tmp/install_log_$serverId.log 2>&1 &");

echo "✅ Comando inviato. Log in: /tmp/install_log_$serverId.log\n";


