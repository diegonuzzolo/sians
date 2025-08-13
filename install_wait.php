<?php
require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php';

$serverId = intval($_GET['server_id'] ?? 0);
if ($serverId <= 0) {
    die("Server ID non valido");
}

$stmt = $pdo->prepare("SELECT s.*, c.proxmox_containerId AS vmid, c.private_ip
                       FROM servers s
                       JOIN containers c ON c.id = s.container_id
                       WHERE s.id = ?");
$stmt->execute([$serverId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    die("Server non trovato");
}

$vmid = (int)$server['vmid'];
$containerIp = $server['private_ip'];
$sshUser = 'root';
$sshPass = 'Lgu8330Serve6';
?>

<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Installazione Server Minecraft</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
<style>
body { background-color: #111; color: #fff; }
.progress { height: 30px; }
.progress-bar { transition: width 0.5s ease-in-out; }
</style>
</head>
<body class="d-flex flex-column justify-content-center align-items-center vh-100">
<div class="text-center">
    <h2>Installazione del server in corso...</h2>
    <p>Attendi, il sistema gestirà automaticamente container e installazione.</p>

    <div class="progress w-75 mt-4">
        <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%">0%</div>
    </div>
    <div id="statusMessage" class="mt-3"></div>
</div>

<script>
const serverId = <?= json_encode($serverId) ?>;
const statusMessage = document.getElementById('statusMessage');
const progressBar = document.getElementById('progressBar');

const steps = [
    { label: 'Creazione container', value: 20 },
    { label: 'Avvio container', value: 40 },
    { label: 'Connessione SSH', value: 60 },
    { label: 'Installazione Bedrock', value: 90 },
    { label: 'Completato', value: 100 }
];

const maxRetries = 20;
const retryDelay = 3000;

let currentProgress = 0;

function setProgress(target) {
    if (currentProgress >= target) return;
    currentProgress++;
    progressBar.style.width = currentProgress + '%';
    progressBar.textContent = currentProgress + '%';
    setTimeout(() => setProgress(target), 50);
}

async function updateStep(stepLabel) {
    const step = steps.find(s => s.label === stepLabel);
    if (step) {
        statusMessage.textContent = step.label;
        setProgress(step.value);
    }
}

async function waitForContainerAndInstall(retries = 0) {
    try {
        await updateStep('Creazione container');

        const resp = await fetch(`install_wait_status.php?server_id=${serverId}`);
        const data = await resp.json();

        if (data.container_status !== 'running') {
            await updateStep('Avvio container');
            await fetch(`install_wait_start.php?server_id=${serverId}`);
            setTimeout(() => waitForContainerAndInstall(retries), 2000);
            return;
        }

        await updateStep('Connessione SSH');

        const sshResp = await fetch(`install_server.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ server_id: serverId })
        });
        const sshData = await sshResp.json();

        if (sshData.success) {
            await updateStep('Completato');
            setTimeout(() => window.location.href = "dashboard.php", 1500);
        } else {
            if (retries < maxRetries) {
                statusMessage.textContent = `Tentativo SSH fallito, riprovo... (${retries + 1}/${maxRetries})`;
                setTimeout(() => waitForContainerAndInstall(retries + 1), retryDelay);
            } else {
                statusMessage.textContent = "Errore: impossibile connettersi al container via SSH.";
            }
        }

    } catch (e) {
        if (retries < maxRetries) {
            statusMessage.textContent = `Errore comunicazione, riprovo... (${retries + 1}/${maxRetries})`;
            setTimeout(() => waitForContainerAndInstall(retries + 1), retryDelay);
        } else {
            statusMessage.textContent = "Errore irreversibile: " + e.message;
        }
    }
}

waitForContainerAndInstall();
</script>
</body>
</html>
