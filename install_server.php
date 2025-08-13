<?php
require 'auth_check.php';
require 'config/config.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$serverId = intval($input['server_id'] ?? 0);

if ($serverId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Server ID non valido']);
    exit;
}

// Recupera dati server + container
$stmt = $pdo->prepare("SELECT s.*, c.proxmox_containerId AS vmid, c.private_ip
                       FROM servers s
                       JOIN containers c ON c.id = s.container_id
                       WHERE s.id = ?");
$stmt->execute([$serverId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    echo json_encode(['success' => false, 'error' => 'Server non trovato']);
    exit;
}

$vmid = $server['vmid'];

// --- Proxmox API functions ---
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

// --- Esegui installazione ---
try {
    $auth = proxmoxLogin();

    // 1️⃣ Avvia container
    $start = startContainer($vmid, $auth);
    if (isset($start['errors'])) throw new Exception("Errore avvio container: " . json_encode($start['errors']));

    // 2️⃣ Comandi installazione Bedrock
    $commands = [
        'apt update && apt install -y wget unzip screen',
        'mkdir -p /opt/minecraft-bedrock && cd /opt/minecraft-bedrock',
        'wget -O bedrock-server.zip https://www.minecraft.net/content/dam/minecraft/edition-bedrock/bedrock-server-1.20.10.23.zip',
        'unzip -o bedrock-server.zip -d /opt/minecraft-bedrock',
        'chmod +x /opt/minecraft-bedrock/bedrock_server',
        'echo -e "#!/bin/bash\ncd /opt/minecraft-bedrock\n./bedrock_server" > /opt/minecraft-bedrock/start.sh',
        'chmod +x /opt/minecraft-bedrock/start.sh',
        'echo -e "#!/bin/bash\npkill -f bedrock_server" > /opt/minecraft-bedrock/stop.sh',
        'chmod +x /opt/minecraft-bedrock/stop.sh'
    ];

    foreach ($commands as $cmd) {
        $res = execCommandInContainer($vmid, $cmd, $auth);
        if (isset($res['errors'])) throw new Exception("Errore eseguendo: $cmd, output: " . json_encode($res['errors']));
    }

    // 3️⃣ Aggiorna DB a ready
    $stmt = $pdo->prepare("UPDATE servers SET status='ready', updated_at=NOW() WHERE id=?");
    $stmt->execute([$serverId]);

    echo json_encode(['success' => true, 'message' => 'Server Bedrock installato con successo']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
