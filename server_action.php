<?php
require 'config/config.php';
require 'includes/auth.php';

$serverId = $_POST['server_id'] ?? null;
$action = $_POST['action'] ?? null;

if (!$serverId || !in_array($action, ['start', 'stop'])) {
    http_response_code(400);
    exit('Richiesta non valida');
}

$stmt = $pdo->prepare("SELECT s.*, v.proxmox_vmid FROM servers s
                       JOIN minecraft_vms v ON v.assigned_server_id = s.id
                       WHERE s.id = ? AND s.user_id = ?");
$stmt->execute([$serverId, $_SESSION['user_id']]);
$server = $stmt->fetch();

if (!$server) {
    http_response_code(403);
    exit('Non autorizzato o server non trovato');
}

$vmid = $server['proxmox_vmid'];

$proxmoxHost = PROXMOX_HOST;
$node = PROXMOX_NODE;
$tokenId = PROXMOX_API_TOKEN_ID;
$tokenSecret = PROXMOX_API_TOKEN_SECRET;

if ($action === 'start') {
    $url = "$proxmoxHost/api2/json/nodes/$node/qemu/$vmid/status/start";
    $statusToSet = 'attivo';
} else {
    $url = "$proxmoxHost/api2/json/nodes/$node/qemu/$vmid/status/stop"; // cambiato da shutdown
    $statusToSet = 'spento';
}

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: PVEAPIToken=$tokenId=$tokenSecret"
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode == 200) {
    $stmt = $pdo->prepare("UPDATE servers SET status = ? WHERE id = ?");
    $stmt->execute([$statusToSet, $serverId]);
    header("Location: dashboard.php");
    exit;
} else {
    exit("Errore nell'operazione. Codice HTTP: $httpCode, risposta: $response");
}
