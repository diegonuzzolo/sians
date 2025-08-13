<?php
require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php';
require_once 'install_server.php'; // Funzione installBedrockServer()

$serverId = intval($_GET['server_id'] ?? 0);
if ($serverId <= 0) die("Server ID non valido");

// Recupera informazioni server e container
$stmt = $pdo->prepare("SELECT s.*, c.proxmox_containerId, c.private_ip FROM servers s JOIN containers c ON s.container_id=c.id WHERE s.id=?");
$stmt->execute([$serverId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$server) die("Server non trovato");

$auth = proxmoxLogin();

function isContainerRunning($vmid, $auth) {
    $url = PROXMOX_API_URL."/nodes/".PROXMOX_NODE."/lxc/{$vmid}/status/current";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "CSRFPreventionToken: {$auth['csrf']}",
        "Cookie: PVEAuthCookie={$auth['ticket']}"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $resp = curl_exec($ch);
    curl_close($ch);
    if ($resp) {
        $data = json_decode($resp,true);
        return isset($data['data']['status']) && $data['data']['status'] === 'running';
    }
    return false;
}

// Se il container non è running, lo avviamo
if (!isContainerRunning($server['proxmox_containerId'], $auth)) {
    startContainer($server['proxmox_containerId'], $auth);
    // attendiamo che diventi running
    $timeout = 60;
    $startTime = time();
    while(!isContainerRunning($server['proxmox_containerId'], $auth)) {
        if(time()-$startTime > $timeout) die("Timeout avvio container");
        sleep(3);
    }
}

// Container running -> avvia installazione Bedrock
if ($server['status'] === 'not_installed') {
    try {
        installBedrockServer($server['private_ip'], $server['ram_mb']);
        $stmt = $pdo->prepare("UPDATE servers SET status='ready' WHERE id=?");
        $stmt->execute([$serverId]);
    } catch(Exception $e) {
        $stmt = $pdo->prepare("UPDATE servers SET status='error' WHERE id=?");
        $stmt->execute([$serverId]);
        die("Errore installazione: ".$e->getMessage());
    }
}

// HTML progress bar
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
    <div id="statusMessage" class="mt-3">Installazione in corso...</div>
</div>

<script>
setTimeout(() => { window.location.href = "dashboard.php"; }, 5000);
</script>
</body>
</html>
