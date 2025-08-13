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

try {
    // Login Proxmox
    $auth = proxmoxLogin();

    // Attendi container running
    $waitResult = waitForContainerRunning($vmid, $auth, 60);
    if (!$waitResult['success']) throw new Exception($waitResult['message']);

    // Aggiorna stato a installing
    $stmt = $pdo->prepare("UPDATE servers SET status = ? WHERE id = ?");
    $stmt->execute(['installing', $serverId]);
    $statusMessage = "Container pronto, avvio script Bedrock...";

    // Esegui lo script presente nel template usando pct exec
    $cmd = "pct exec $vmid -- bash /root/prepareBedrockServer.sh $ramMb";
    exec($cmd, $output, $ret);
    if ($ret !== 0) {
        throw new Exception("Errore esecuzione script Bedrock: " . implode("\n", $output));
    }

    // Aggiorna stato a ready
    $stmt = $pdo->prepare("UPDATE servers SET status = ? WHERE id = ?");
    $stmt->execute(['ready', $serverId]);
    $statusMessage = "Server pronto!";

} catch(Exception $e) {
    $statusMessage = "Errore: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Installazione in corso...</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark text-white d-flex flex-column justify-content-center align-items-center vh-100">
<div class="text-center">
    <h2>Creazione e installazione del server in corso...</h2>
    <p>Attendi qualche istante, ti reindirizzeremo automaticamente appena tutto è pronto.</p>
    <div class="spinner-border text-info" role="status">
        <span class="visually-hidden">Loading...</span>
    </div>
    <div id="statusMessage" class="mt-3"><?= htmlspecialchars($statusMessage) ?></div>
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
