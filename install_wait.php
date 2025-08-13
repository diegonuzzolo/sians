<?php
require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php';

$serverId = intval($_GET['server_id'] ?? 0);
if ($serverId <= 0) die("Server ID non valido.");

// Recupera dati server e container
$stmt = $pdo->prepare("SELECT s.id AS server_id, s.name AS server_name, s.status, c.proxmox_containerId, c.private_ip, c.ram_mb
                       FROM servers s
                       JOIN containers c ON c.id = s.container_id
                       WHERE s.id = ?");
$stmt->execute([$serverId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$server) die("Server non trovato.");

$vmid = $server['proxmox_containerId'];
$privateIp = $server['private_ip'];
$ramMb = $server['ram_mb'];

$statusMessage = "Preparazione del container...";

// Prepara percorso script Bedrock (già copiato con prepareBedrockScript)
$installScriptPath = "/root/install_bedrock.sh";

// Aggiorna stato se non è già in installazione
if ($server['status'] === 'new') {
    $stmt = $pdo->prepare("UPDATE servers SET status = ? WHERE id = ?");
    $stmt->execute(['ready_to_install', $serverId]);
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Installazione server</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark text-white d-flex flex-column justify-content-center align-items-center vh-100">
<div class="text-center">
    <h2>Server <?= htmlspecialchars($server['server_name']) ?></h2>
    <p>Container ID: <?= $vmid ?> | IP privato: <?= $privateIp ?></p>

    <div id="statusMessage" class="mt-3"><?= htmlspecialchars($statusMessage) ?></div>

    <?php if ($server['status'] === 'ready_to_install'): ?>
        <div class="mt-3">
            <p>Per installare Bedrock, entra nel container e avvia lo script:</p>
            <code>pct enter <?= $vmid ?> <?= $installScriptPath ?></code>
        </div>
    <?php endif; ?>

    <div class="spinner-border text-info mt-3" role="status">
        <span class="visually-hidden">Loading...</span>
    </div>
</div>

<script>
function checkReady() {
    fetch('check_install_status.php?server_id=<?= $serverId ?>')
        .then(res => res.json())
        .then(data => {
            const el = document.getElementById('statusMessage');
            if (data.status === 'ready') {
                el.textContent = "Server pronto! Reindirizzamento...";
                setTimeout(() => window.location.href = "dashboard.php", 1500);
            } else if (data.status === 'ready_to_install') {
                el.textContent = "Container pronto, esegui lo script Bedrock manualmente.";
            } else {
                el.textContent = "Stato attuale: " + data.status;
                setTimeout(checkReady, 3000);
            }
        })
        .catch(() => {
            const el = document.getElementById('statusMessage');
            el.textContent = "Errore di comunicazione, riprovo...";
            setTimeout(checkReady, 5000);
        });
}
checkReady();
</script>
</body>
</html>
