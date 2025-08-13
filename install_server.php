<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'config/config.php';
require 'auth_check.php';

header('Content-Type: application/json');

// Ricezione dati POST
$input = json_decode(file_get_contents('php://input'), true);

$required = ['server_id', 'container_id', 'server_name', 'cpu', 'ram', 'ip', 'type'];
foreach ($required as $key) {
    if (!isset($input[$key])) {
        echo json_encode(['success' => false, 'error' => "Parametro mancante: $key"]);
        exit;
    }
}

$serverId    = intval($input['server_id']);
$containerId = intval($input['container_id']);
$serverName  = $input['server_name'];
$cpu         = intval($input['cpu']);
$ramMb       = intval($input['ram']);
$ip          = $input['ip'];
$type        = $input['type'];

if ($type !== 'bedrock') {
    echo json_encode(['success' => false, 'error' => "Tipo server non supportato"]);
    exit;
}

// --- Funzioni Proxmox ---
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
    if (!isset($json['data']['ticket']) || !isset($json['data']['CSRFPreventionToken'])) {
        throw new Exception('Login Proxmox fallito: ' . $response);
    }

    return [
        'ticket' => $json['data']['ticket'],
        'csrf'   => $json['data']['CSRFPreventionToken']
    ];
}

function isContainerRunning($vmid, $auth) {
    $url = PROXMOX_API_URL . "/nodes/" . PROXMOX_NODE . "/lxc/$vmid/status/current";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "CSRFPreventionToken: {$auth['csrf']}",
        "Cookie: PVEAuthCookie={$auth['ticket']}"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);

    $json = json_decode($response, true);
    if (!isset($json['data']['status'])) {
        throw new Exception("Impossibile leggere lo stato del container: $vmid");
    }

    return $json['data']['status'] === 'running';
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

    $json = json_decode($response, true);
    if (isset($json['errors'])) {
        throw new Exception("Errore avvio container: " . json_encode($json));
    }
}

// --- Aggiorna stato server a installing ---
$stmt = $pdo->prepare("UPDATE servers SET status='installing', updated_at=NOW() WHERE id=?");
$stmt->execute([$serverId]);

try {
    $auth = proxmoxLogin();

    // Controlla stato container e avvia se necessario
    if (!isContainerRunning($containerId, $auth)) {
        startContainer($containerId, $auth);
        sleep(10); // attesa che SSH sia pronto
    }

    // Parametri SSH
    $sshUser = 'root';
    $sshHost = $ip;
    $sshPass = 'Lgu8330Serve6';

    // Comandi da eseguire nel container
    $commands = [
        "apt update && apt install -y wget unzip screen",
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

    foreach ($commands as $cmd) {
        $sshCommand = sprintf(
            'sshpass -p %s ssh -o StrictHostKeyChecking=no %s@%s "%s"',
            escapeshellarg($sshPass),
            $sshUser,
            $sshHost,
            $cmd
        );
        exec($sshCommand, $output, $return_var);
        if ($return_var !== 0) {
            throw new Exception("Errore eseguendo: $cmd, output: " . implode("\n", $output));
        }
    }

    // Aggiorna stato a ready
    $stmt = $pdo->prepare("UPDATE servers SET status='ready', updated_at=NOW() WHERE id=?");
    $stmt->execute([$serverId]);

    echo json_encode(['success' => true, 'message' => 'Server Bedrock installato correttamente']);
    exit;

} catch (Exception $e) {
    $stmt = $pdo->prepare("UPDATE servers SET status='error', updated_at=NOW() WHERE id=?");
    $stmt->execute([$serverId]);

    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}
