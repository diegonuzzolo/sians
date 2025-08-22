<?php
require 'auth_check.php';
require 'config/config.php';
require 'includes/csrf.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Metodo non consentito.']);
    exit;
}

csrf_check_or_die();

$serverId = intval($_POST['server_id'] ?? 0);
$userId   = $_SESSION['user_id'] ?? 0;
$command  = trim($_POST['command'] ?? '');

$stmt = $pdo->prepare("SELECT proxmox_containerId, proxmox_node FROM servers WHERE id=? AND user_id=?");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server || empty($command) || empty($server['proxmox_node'])) {
    echo json_encode(['success' => false, 'message' => 'Dati non validi o nodo non configurato.']);
    exit;
}

$proxmox_host   = '192.168.1.251';
$proxmox_port   = 8006;
$proxmox_user   = 'root@pam';
$proxmox_token_id = 'sians';
$proxmox_token_secret = '0b4b070d-90bc-40a5-b0d1-9db4df95aa79';

$containerId = $server['proxmox_containerId'];
$node        = $server['proxmox_node'];

try {
    // Comando screen
    $screenCommand = "screen -S minecraft_server -p 0 -X stuff \"{$command}\\n\"";

    // JSON array come richiesto da PVE exec
    $post_data = json_encode([
        'command' => ['/bin/sh', '-c', $screenCommand]
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://{$proxmox_host}:{$proxmox_port}/api2/json/nodes/{$node}/lxc/{$containerId}/exec");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: PVEAPIToken={$proxmox_user}!{$proxmox_token_id}={$proxmox_token_secret}",
        "Content-Type: application/json"
    ]);

    $exec_response = curl_exec($ch);
    if (curl_errno($ch)) {
        throw new Exception('Errore cURL: ' . curl_error($ch));
    }
    curl_close($ch);

    $exec_data = json_decode($exec_response, true);

    if ($exec_data === null || !isset($exec_data['data']['pid'])) {
        throw new Exception('Risposta API non valida: ' . $exec_response);
    }

    echo json_encode(['success' => true, 'pid' => $exec_data['data']['pid']]);

} catch (\Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Errore API Proxmox: ' . $e->getMessage()]);
}
