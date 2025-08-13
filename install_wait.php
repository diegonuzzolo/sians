<?php
require 'config/config.php';
require 'auth_check.php';

$serverId = intval($_GET['server_id'] ?? 0);
if ($serverId <= 0) {
    die("Server ID non valido");
}

// Recupera info server
$stmt = $pdo->prepare("SELECT s.id, s.name, s.status, c.proxmox_containerId, c.private_ip
                       FROM servers s
                       JOIN containers c ON c.id = s.container_id
                       WHERE s.id = ?");
$stmt->execute([$serverId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    die("Server non trovato");
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Installazione in corso...</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
</head>
<body class="bg-dark text-white d-flex flex-column justify-content-center align-items-center vh-100">
    <div class="text-center">
        <h2>Installazione del server Minecraft Bedrock in corso...</h2>
        <p>Attendi qualche istante, la pagina si aggiornerà automaticamente.</p>
        <div class="spinner-border text-info" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
        <div id="statusMessage" class="mt-3">Stato attuale: <?= htmlspecialchars($server['status']) ?></div>
    </div>

<script>
const serverId = <?= json_encode($serverId) ?>;
const containerId = <?= json_encode($server['proxmox_containerId']) ?>;
const serverName = <?= json_encode($server['name']) ?>;
const ip = <?= json_encode($server['private_ip']) ?>;
const statusMessage = document.getElementById('statusMessage');

async function startInstallation() {
    try {
        const resp = await fetch('install_server.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                server_id: serverId,
                container_id: containerId,
                server_name: serverName,
                cpu: <?= (int)$server['cpu_cores'] ?>,
                ram: <?= (int)$server['ram_mb'] ?>,
                ip: ip,
                type: 'bedrock'
            })
        });
        const data = await resp.json();
        if (!data.success) {
            statusMessage.textContent = 'Errore installazione: ' + (data.error || 'Sconosciuto');
            return;
        }
        checkStatus();
    } catch(e) {
        statusMessage.textContent = 'Errore di comunicazione, riprovo...';
        setTimeout(startInstallation, 5000);
    }
}

async function checkStatus() {
    try {
        const resp = await fetch(`check_install_status.php?server_id=${serverId}`);
        const data = await resp.json();
        if (data.status === 'ready') {
            statusMessage.textContent = 'Server pronto! Reindirizzamento...';
            setTimeout(() => window.location.href = 'dashboard.php', 1500);
        } else {
            statusMessage.textContent = 'Stato attuale: ' + data.status;
            setTimeout(checkStatus, 3000);
        }
    } catch(e) {
        statusMessage.textContent = 'Errore nel controllo dello stato, riprovo...';
        setTimeout(checkStatus, 5000);
    }
}

// Avvia installazione solo se il server non è già installing/ready
if (<?= json_encode($server['status']) ?> === 'not_installed') {
    startInstallation();
} else {
    checkStatus();
}
</script>
</body>
</html>
