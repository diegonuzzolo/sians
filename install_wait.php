<?php
require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php';

$serverId = intval($_GET['server_id'] ?? 0);
if ($serverId <= 0) die("Server ID non valido");

// Recupera dati server e container
$stmt = $pdo->prepare("
    SELECT s.id AS server_id, s.name AS server_name, s.status, c.proxmox_containerId, c.private_ip
    FROM servers s
    JOIN containers c ON s.container_id = c.id
    WHERE s.id = ?
");
$stmt->execute([$serverId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$server) die("Server non trovato");

$containerVmid = $server['proxmox_containerId'];
$containerIp = $server['private_ip'];

// Funzione che tenta di avviare il container fino a quando Proxmox segnala pronto
function waitForContainer(int $vmid, array $auth, int $timeout = 60) {
    $start = time();
    do {
        try {
            startContainer($vmid, $auth);
            return true;
        } catch (Exception $e) {
            sleep(2);
        }
    } while ((time() - $start) < $timeout);

    throw new Exception("Timeout avvio container $vmid");
}

// Se server non ancora installato, avvia installazione
if ($server['status'] === 'not_installed') {
    // Login Proxmox
    $auth = proxmoxLogin();

    // Avvia il container e aspetta che sia pronto
    try {
        waitForContainer($containerVmid, $auth, 120); // max 2 minuti
    } catch (Exception $e) {
        die("Errore avvio container: " . $e->getMessage());
    }

    // Aggiorna stato a installing
    $stmt = $pdo->prepare("UPDATE servers SET status = 'installing' WHERE id = ?");
    $stmt->execute([$serverId]);

    try {
        // Installa Bedrock Server
        installBedrockServer($containerIp, $server['ram_mb']);
        $stmt = $pdo->prepare("UPDATE servers SET status = 'ready' WHERE id = ?");
        $stmt->execute([$serverId]);
    } catch (Exception $e) {
        $stmt = $pdo->prepare("UPDATE servers SET status = 'error' WHERE id = ?");
        $stmt->execute([$serverId]);
        die("Errore installazione: " . $e->getMessage());
    }
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
    <div id="statusMessage" class="mt-3">Avvio container...</div>
</div>

<script>
const serverId = <?= json_encode($serverId) ?>;
const statusMessage = document.getElementById('statusMessage');

async function checkStatus() {
    try {
        const resp = await fetch(`check_install_status.php?server_id=${serverId}`);
        const data = await resp.json();
        if (data.status === 'ready') {
            statusMessage.textContent = "Server pronto! Reindirizzamento...";
            setTimeout(() => { window.location.href = "dashboard.php"; }, 1500);
        } else if (data.status === 'installing') {
            statusMessage.textContent = "Installazione Bedrock in corso...";
            setTimeout(checkStatus, 3000);
        } else if (data.status === 'error') {
            statusMessage.textContent = "Errore durante l'installazione, contatta l'amministratore.";
        } else {
            statusMessage.textContent = "Stato attuale: " + data.status;
            setTimeout(checkStatus, 3000);
        }
    } catch (e) {
        statusMessage.textContent = "Errore comunicazione, riprovo...";
        setTimeout(checkStatus, 5000);
    }
}

checkStatus();
</script>
</body>
</html>
