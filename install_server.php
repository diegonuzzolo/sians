<?php
header('Content-Type: application/json');
require_once 'config/config.php';
require_once 'auth_check.php';
require_once 'proxmox_functions.php'; // dove hai definito proxmoxLogin()

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !isset($data['server_id'], $data['container_id'], $data['server_name'], $data['cpu'], $data['ram'], $data['ip'])) {
    echo json_encode(['success' => false, 'error' => 'Parametri mancanti']);
    exit;
}

$serverId = (int)$data['server_id'];
$containerId = (int)$data['container_id'];
$serverName = $data['server_name'];
$cpu = (int)$data['cpu'];
$ram = (int)$data['ram'];
$ip = $data['ip'];

// Recupera credenziali Proxmox
$auth = proxmoxLogin();

// --- Controllo stato container ---
$ch = curl_init(PROXMOX_API_URL . "/nodes/" . PROXMOX_NODE . "/lxc/$containerId/status/current");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "CSRFPreventionToken: {$auth['csrf']}",
    "Cookie: PVEAuthCookie={$auth['ticket']}"
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
curl_close($ch);
$json = json_decode($response, true);

if (!$json || !isset($json['data']['status'])) {
    echo json_encode(['success' => false, 'error' => 'Impossibile ottenere stato container']);
    exit;
}

if ($json['data']['status'] !== 'running') {
    // Avvia il container
    $ch = curl_init(PROXMOX_API_URL . "/nodes/" . PROXMOX_NODE . "/lxc/$containerId/status/start");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "CSRFPreventionToken: {$auth['csrf']}",
        "Cookie: PVEAuthCookie={$auth['ticket']}"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $startResp = curl_exec($ch);
    curl_close($ch);
    sleep(15); // aspetta che SSH sia pronto
}

// --- Comandi da eseguire nel container ---
$commands = [
    "export DEBIAN_FRONTEND=noninteractive",
    "apt update -y",
    "apt install -y wget unzip screen",
    "mkdir -p /opt/minecraft-bedrock && cd /opt/minecraft-bedrock",
    "wget -O bedrock-server.zip https://minecraft.azureedge.net/bin-linux/bedrock-server.zip",
    "unzip -o bedrock-server.zip",
    "rm bedrock-server.zip",
    "echo -e \"#!/bin/bash\ncd /opt/minecraft-bedrock\nLD_LIBRARY_PATH=. ./bedrock_server\" > /opt/minecraft-bedrock/start.sh",
    "chmod +x /opt/minecraft-bedrock/start.sh",
    "echo -e \"#!/bin/bash\npkill -f bedrock_server\" > /opt/minecraft-bedrock/stop.sh",
    "chmod +x /opt/minecraft-bedrock/stop.sh",
    "echo \"eula=true\" > /opt/minecraft-bedrock/eula.txt"
];

// --- Esecuzione comandi via SSH ---
$sshUser = 'root';
$sshHost = $ip;
$sshCmd = implode(' && ', $commands);

// Usa sshpass se necessario
$cmd = "ssh -o StrictHostKeyChecking=no $sshUser@$sshHost '$sshCmd'";
exec($cmd . " 2>&1", $output, $ret);

if ($ret !== 0) {
    echo json_encode(['success' => false, 'error' => 'Errore eseguendo: ' . implode("\n", $output)]);
    exit;
}

// Aggiorna stato server a installing
$stmt = $pdo->prepare("UPDATE servers SET status='installing', updated_at=NOW() WHERE id=?");
$stmt->execute([$serverId]);

echo json_encode(['success' => true]);
