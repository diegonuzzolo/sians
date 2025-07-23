<?php
session_start();
require 'config/config.php';
require 'includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['server_id'], $_POST['action'])) {
    http_response_code(400);
    exit('Richiesta non valida');
}

$userId = $_SESSION['user_id'];
$serverId = intval($_POST['server_id']);
$action = $_POST['action']; // 'start' o 'stop'

// Verifica proprietà del server
$stmt = $pdo->prepare("SELECT proxmox_vmid FROM servers WHERE id = ? AND user_id = ?");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    http_response_code(403);
    exit('Server non trovato o accesso negato');
}

$vmid = $server['proxmox_vmid'];
$node = PROXMOX_NODE;
$host = rtrim(PROXMOX_HOST, '/');
$tokenId = PROXMOX_API_TOKEN_ID;
$tokenSecret = PROXMOX_API_TOKEN_SECRET;

// Endpoint API Proxmox per start/stop VM
switch ($action) {
    case 'start':
        $url = "$host/api2/json/nodes/$node/qemu/$vmid/status/start";
        break;
    case 'stop':
        $url = "$host/api2/json/nodes/$node/qemu/$vmid/status/stop";
        break;
    default:
        http_response_code(400);
        exit('Azione non valida');
}

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: PVEAPIToken=$tokenId=$tokenSecret",
    "Accept: application/json"
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    // Aggiorna lo stato nel DB (facoltativo, per avere stato più reattivo in DB)
    $newStatus = ($action === 'start') ? 'running' : 'stopped';
    $updateStmt = $pdo->prepare("UPDATE servers SET status = ? WHERE id = ?");
    $updateStmt->execute([$newStatus, $serverId]);

    // Redirect con messaggio di successo
    header('Location: dashboard.php?msg=success');
    exit;
} else {
    // Log errore (facoltativo) - utile per debug
    error_log("Proxmox API error: HTTP $httpCode, response: $response");

    // Redirect con messaggio di errore
    header('Location: dashboard.php?msg=error&code=' . $httpCode);
    exit;
}
