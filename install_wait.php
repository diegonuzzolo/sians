<?php
require 'auth_check.php';
require 'config/config.php';

$serverId = intval($_GET['server_id'] ?? 0);
if ($serverId <= 0) die("Server ID non valido");

// Recupera dati server + container
$stmt = $pdo->prepare("SELECT s.*, c.proxmox_containerId AS vmid, c.private_ip
                       FROM servers s
                       JOIN containers c ON c.id = s.container_id
                       WHERE s.id = ?");
$stmt->execute([$serverId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) die("Server non trovato");

// Funzioni Proxmox
function proxmoxLogin() {
    $url = PROXMOX_API_URL . '/access/ticket';
    $data = [
        'username' => PROXMOX_USERNAME,
        'password' => PROXMOX_PASSWORD,
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);
    $json = json_decode($response, true);
    if (!isset($json['data']['ticket'])) throw new Exception("Login Proxmox fallito");
    return [
        'ticket' => $json['data']['ticket'],
        'csrf' => $json['data']['CSRFPreventionToken']
    ];
}

function startContainer($vmid, $auth) {
    $url = PROXMOX_API_URL . "/nodes/" . PROXMOX_NODE . "/lxc/$vmid/status/start";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "CSRFPreventionToken: {$auth['csrf']}",
        "Cookie: PVEAuthCookie={$auth['ticket']}"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

function execCommandInContainer($vmid, $command, $auth) {
    $url = PROXMOX_API_URL . "/nodes/" . PROXMOX_NODE . "/lxc/$vmid/exec";
    $postData = [
        'command' => '/bin/bash',
        'args' => ['-c', $command],
        'background' => 0,
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "CSRFPreventionToken: {$auth['csrf']}",
        "Cookie: PVEAuthCookie={$auth['ticket']}",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

// HTML + JS per progress
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Installazione Server</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark text-white d-flex flex-column justify-content-center align-items-center vh-100">
<div class="text-center">
    <h2>Installazione server in corso...</h2>
    <div class="progress mt-4" style="height: 25px; width: 400px;">
        <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%">0%</div>
    </div>
    <div id="statusMessage" class="mt-3"></div>
</div>

<script>
const serverId = <?= json_encode($serverId) ?>;

async function updateProgress(percent, message) {
    document.getElementById('progressBar').style.width = percent + '%';
    document.getElementById('progressBar').textContent = percent + '%';
    document.getElementById('statusMessage').textContent = message;
}

async function installServer() {
    await updateProgress(10, "Login a Proxmox...");
    const respLogin = await fetch('install_wait_ajax.php?action=login&server_id=' + serverId);
    let data = await respLogin.json();
    if (!data.success) { alert(data.error); return; }

    await updateProgress(20, "Avvio container...");
    const respStart = await fetch('install_wait_ajax.php?action=start&server_id=' + serverId);
    data = await respStart.json();
    if (!data.success) { alert(data.error); return; }

    await updateProgress(40, "Installazione pacchetti di base...");
    const respInstall = await fetch('install_wait_ajax.php?action=install&server_id=' + serverId);
    data = await respInstall.json();
    if (!data.success) { alert(data.error); return; }

    await updateProgress(100, "Server pronto! Reindirizzamento...");
    setTimeout(() => { window.location.href = "dashboard.php"; }, 1500);
}

installServer();
</script>
</body>
</html>
