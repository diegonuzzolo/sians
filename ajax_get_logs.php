<?php
require 'auth_check.php';
require 'config/config.php';

header('Content-Type: application/json');

$serverId = intval($_GET['server_id'] ?? 0);
$userId = $_SESSION['user_id'] ?? 0;
$offset = intval($_GET['offset'] ?? 0);

$stmt = $pdo->prepare("SELECT proxmox_containerId, proxmox_node FROM servers WHERE id=? AND user_id=?");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server || empty($server['proxmox_node'])) {
    echo json_encode(['success' => false, 'message' => 'Accesso negato o nodo Proxmox non configurato.']);
    exit;
}

$proxmox_host = '192.168.1.251';
$proxmox_port = 8006;
$proxmox_user = 'root@pam';
$proxmox_token_id = 'sians';
$proxmox_token_secret = '0b4b070d-90bc-40a5-b0d1-9db4df95aa79';

$containerId = $server['proxmox_containerId'];
$node = $server['proxmox_node'];

$logFilePathInContainer = "/root/minecraft_bedrock/debug_output.log";

// Funzione helper per chiamare Proxmox API
function callProxmox($url) {
    global $proxmox_host, $proxmox_port, $proxmox_user, $proxmox_token_id, $proxmox_token_secret;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://{$proxmox_host}:{$proxmox_port}/api2/json" . $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: PVEAPIToken={$proxmox_user}!{$proxmox_token_id}={$proxmox_token_secret}"
    ]);
    $res = curl_exec($ch);
    if (curl_errno($ch)) {
        throw new Exception('Errore cURL: '.curl_error($ch));
    }
    curl_close($ch);
    return json_decode($res, true);
}

try {
    // 1. Stat file
    $stat_data = callProxmox("/nodes/{$node}/lxc/{$containerId}/files/stat?filepath=" . urlencode($logFilePathInContainer));

    if (!$stat_data || !isset($stat_data['data']['size'])) {
        echo json_encode(['success' => true, 'log' => '', 'new_size' => 0]);
        exit;
    }

    $fileSize = $stat_data['data']['size'];
    $logContent = '';

    // 2. Se il file è cresciuto, leggo la parte nuova
    if ($fileSize > $offset) {
        $read_data = callProxmox("/nodes/{$node}/lxc/{$containerId}/files/read?filepath=" . urlencode($logFilePathInContainer) . "&start={$offset}");
        if (isset($read_data['data']['content'])) {
            $logContent = $read_data['data']['content'];
        }
    }

    $logContent = htmlspecialchars($logContent);

    echo json_encode([
        'success' => true,
        'log' => nl2br($logContent),
        'new_size' => $fileSize
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Errore API Proxmox: '.$e->getMessage()]);
}
