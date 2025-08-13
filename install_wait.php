<?php
require 'config/config.php';
require 'auth_check.php';

$serverId = intval($_GET['server_id'] ?? 0);
if ($serverId <= 0) die("Server ID non valido");

// Recupera informazioni server e container dal DB
$stmt = $pdo->prepare("SELECT s.id AS server_id, s.name AS server_name, s.status AS server_status, 
                              c.proxmox_containerId AS vmid, c.private_ip AS container_ip,
                              s.cpu_cores, s.ram_mb
                       FROM servers s
                       JOIN containers c ON c.id = s.container_id
                       WHERE s.id = ?");
$stmt->execute([$serverId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$server) die("Server non trovato");

$vmid = $server['vmid'];
$containerIp = $server['container_ip'];
$serverName = htmlspecialchars($server['server_name']);
$cpuCores = $server['cpu_cores'] ?? 2;
$ramMb = $server['ram_mb'] ?? 4096;
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Installazione in corso...</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body { background-color: #111; color: #fff; }
</style>
</head>
<body class="d-flex flex-column justify-content-center align-items-center vh-100">
<div class="text-center">
    <h2>Creazione e installazione del server <?= $serverName ?> in corso...</h2>
    <p>Attendi qualche istante, ti reindirizzeremo automaticamente appena tutto è pronto.</p>
    <div class="spinner-border text-info" role="status"><span class="visually-hidden">Loading...</span></div>
    <div id="statusMessage" class="mt-3"></div>
</div>

<script>
const serverId = <?= json_encode($serverId) ?>;
const vmid = <?= json_encode($vmid) ?>;
const containerIp = <?= json_encode($containerIp) ?>;
const serverName = <?= json_encode($serverName) ?>;
const cpuCores = <?= json_encode($cpuCores) ?>;
const ramMb = <?= json_encode($ramMb) ?>;
const statusMessage = document.getElementById('statusMessage');

async function getContainerStatus() {
    try {
        const resp = await fetch('check_container_status.php?vmid=' + vmid);
        if (!resp.ok) throw new Error("Errore rete container");
        const data = await resp.json();
        return data.status; // running / stopped / unknown
    } catch (e) {
        console.error(e);
        return 'unknown';
    }
}

async function startContainer() {
    try {
        const resp = await fetch('start_container.php?vmid=' + vmid);
        if (!resp.ok) throw new Error("Errore avvio container");
        const data = await resp.json();
        return data.success;
    } catch (e) {
        console.error(e);
        return false;
    }
}

async function waitForSSH(ip, retries = 20, delay = 5000) {
    for (let i = 0; i < retries; i++) {
        try {
            const resp = await fetch(`check_ssh.php?ip=${ip}`);
            const data = await resp.json();
            if (data.sshReady) return true;
        } catch (e) {
            console.error(e);
        }
        statusMessage.textContent = `Attesa SSH... tentativo ${i+1}/${retries}`;
        await new Promise(r => setTimeout(r, delay));
    }
    return false;
}

async function installServer() {
    try {
        const resp = await fetch('install_server.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                server_id: serverId,
                container_id: vmid,
                server_name: serverName,
                cpu: cpuCores,
                ram: ramMb,
                ip: containerIp
            })
        });
        const data = await resp.json();
        return data.success;
    } catch (e) {
        console.error(e);
        return false;
    }
}

async function checkStatusLoop() {
    statusMessage.textContent = "Verifica stato container...";
    let containerStatus = await getContainerStatus();

    if (containerStatus !== 'running') {
        statusMessage.textContent = "Container fermo, avvio in corso...";
        const started = await startContainer();
        if (!started) {
            statusMessage.textContent = "Errore avvio container, riprovo tra 5s...";
            setTimeout(checkStatusLoop, 5000);
            return;
        }
        statusMessage.textContent = "Container avviato, attesa SSH...";
    }

    const sshReady = await waitForSSH(containerIp);
    if (!sshReady) {
        statusMessage.textContent = "SSH non disponibile, riprovo tra 10s...";
        setTimeout(checkStatusLoop, 10000);
        return;
    }

    statusMessage.textContent = "Avvio installazione server...";
    const installed = await installServer();
    if (!installed) {
        statusMessage.textContent = "Errore installazione server, riprovo tra 10s...";
        setTimeout(checkStatusLoop, 10000);
        return;
    }

    statusMessage.textContent = "Server in installazione... controllo stato finale...";
    async function pollServerStatus() {
        try {
            const resp = await fetch('check_install_status.php?server_id=' + serverId);
            const data = await resp.json();
            if (data.status === 'ready') {
                statusMessage.textContent = "Server pronto! Reindirizzamento...";
                setTimeout(() => window.location.href = "dashboard.php", 1500);
            } else {
                statusMessage.textContent = "Stato attuale: " + data.status;
                setTimeout(pollServerStatus, 3000);
            }
        } catch (e) {
            statusMessage.textContent = "Errore controllo stato server, riprovo...";
            setTimeout(pollServerStatus, 5000);
        }
    }

    pollServerStatus();
}

checkStatusLoop();
</script>
</body>
</html>
