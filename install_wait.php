<?php
require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php';

$serverId = intval($_GET['server_id'] ?? 0);
if ($serverId <= 0) die("Server ID non valido");

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

// Funzione per avvio container con retry
function waitForContainer(int $vmid, array $auth, int $timeout = 120) {
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

// Fasi installazione
$stages = [
    'starting_container' => 'Avvio container',
    'downloading_bedrock' => 'Download Bedrock',
    'configuring_server' => 'Configurazione server',
    'ready' => 'Server pronto'
];

if ($server['status'] === 'not_installed') {
    $auth = proxmoxLogin();
    try {
        waitForContainer($containerVmid, $auth, 120);
        $stmt = $pdo->prepare("UPDATE servers SET status = 'starting_container' WHERE id = ?");
        $stmt->execute([$serverId]);

        installBedrockServer($containerIp, $server['ram_mb'], $pdo, $serverId); // aggiorna progressi
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
<div class="text-center" style="width: 480px;">
    <h2>Installazione del server in corso...</h2>
    <p>Attendi qualche istante, ti reindirizzeremo automaticamente appena tutto è pronto.</p>
    <div class="progress mb-3" style="height: 25px;">
        <div id="installProgress" class="progress-bar progress-bar-striped progress-bar-animated bg-info"
            role="progressbar" style="width: 0%">0%</div>
    </div>
    <div id="statusMessage" class="fw-bold">Preparazione...</div>
</div>

<script>
const serverId = <?= json_encode($serverId) ?>;
const statusMessage = document.getElementById('statusMessage');
const installProgress = document.getElementById('installProgress');

const stages = {
    'starting_container': 25,
    'downloading_bedrock': 50,
    'configuring_server': 75,
    'ready': 100,
    'error': 0
};

async function checkStatus() {
    try {
        const resp = await fetch(`check_install_status.php?server_id=${serverId}`);
        const data = await resp.json();
        let percent = stages[data.status] || 0;

        installProgress.style.width = percent + '%';
        installProgress.textContent = percent + '%';

        if (data.status === 'ready') {
            statusMessage.textContent = "Server pronto! Reindirizzamento...";
            setTimeout(() => window.location.href = "dashboard.php", 1500);
        } else if (data.status === 'error') {
            statusMessage.textContent = "Errore durante l'installazione.";
        } else {
            statusMessage.textContent = "Fase corrente: " + data.status.replace('_', ' ');
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
