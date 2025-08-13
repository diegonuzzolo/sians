<?php
require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php';

$serverId = intval($_GET['server_id'] ?? 0);
if ($serverId <= 0) die("Server ID non valido.");

// Recupera dati server e container
$stmt = $pdo->prepare("
    SELECT s.id AS server_id, s.name AS server_name, s.status,
           c.proxmox_containerId, c.private_ip, c.ram_mb
    FROM servers s
    JOIN containers c ON c.id = s.container_id
    WHERE s.id = ?
");
$stmt->execute([$serverId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$server) die("Server non trovato.");

$vmid = $server['proxmox_containerId'];
$privateIp = $server['private_ip'];
$status = $server['status'];
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Installazione server in corso</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #1e1e1e; color: #fff; display: flex; flex-direction: column; justify-content: center; align-items: center; height: 100vh; }
        .spinner-border { width: 3rem; height: 3rem; }
        .status-box { margin-top: 20px; text-align: center; }
    </style>
</head>
<body>
    <div class="text-center">
        <h2>Installazione del server: <?= htmlspecialchars($server['server_name']) ?></h2>
        <div class="spinner-border text-info" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
        <div id="statusMessage" class="status-box">Stato attuale: <?= htmlspecialchars($status) ?></div>
    </div>

<script>
function checkInstallStatus() {
    fetch('check_container_status.php?server_id=<?= $serverId ?>')
        .then(res => res.json())
        .then(data => {
            const el = document.getElementById('statusMessage');
            el.textContent = "Stato attuale: " + data.status;

            if (data.status === 'ready') {
                el.textContent = "Server pronto! Reindirizzamento...";
                setTimeout(() => window.location.href = "dashboard.php", 1500);
            } else {
                setTimeout(checkInstallStatus, 3000);
            }
        })
        .catch(() => setTimeout(checkInstallStatus, 5000));
}
checkInstallStatus();
</script>

</body>
</html>
