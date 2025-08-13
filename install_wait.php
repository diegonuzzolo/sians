<?php
require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php';

$serverId = intval($_GET['server_id'] ?? 0);
if ($serverId <= 0) die("Server ID non valido.");

$stmt = $pdo->prepare("SELECT s.id AS server_id, s.name AS server_name, s.status, c.proxmox_containerId
                       FROM servers s
                       JOIN containers c ON c.id = s.container_id
                       WHERE s.id = ?");
$stmt->execute([$serverId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$server) die("Server non trovato.");

$vmid = $server['proxmox_containerId'];
$statusMessage = "Preparazione del container...";

try {
    // Login Proxmox
    $auth = proxmoxLogin();

    // Attendi che il container sia running
    $wait = waitForContainerRunning($vmid, $auth, 60);
    if (!$wait['success']) throw new Exception($wait['message']);

    $statusMessage = "Container pronto, avvio installazione Bedrock...";
    $result = prepareBedrockServer($vmid, $pdo, $serverId);
    if (!$result['success']) throw new Exception($result['message']);

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
