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
$action = $_POST['action']; // 'start', 'stop' o 'delete'

// Verifica proprietà del server
$stmt = $pdo->prepare("SELECT subdomain, proxmox_vmid FROM servers WHERE id = ? AND user_id = ?");
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

function proxmoxApiRequest($method, $url, $postData = null) {
    global $tokenId, $tokenSecret;

    $headers = [
        "Authorization: PVEAPIToken=$tokenId=$tokenSecret",
        "Accept: application/json"
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    if ($postData !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $httpCode, 'response' => $response];
}

switch ($action) {
    case 'start':
        $url = "$host/api2/json/nodes/$node/qemu/$vmid/status/start";
        $result = proxmoxApiRequest('POST', $url);
        if ($result['code'] === 200) {
            $updateStmt = $pdo->prepare("UPDATE servers SET status = 'running' WHERE id = ?");
            $updateStmt->execute([$serverId]);
            header('Location: dashboard.php?msg=started');
        } else {
            error_log("Proxmox start error: {$result['response']}");
            header('Location: dashboard.php?msg=error_start');
        }
        exit;

    case 'stop':
        $url = "$host/api2/json/nodes/$node/qemu/$vmid/status/stop";
        $result = proxmoxApiRequest('POST', $url);
        if ($result['code'] === 200) {
            $updateStmt = $pdo->prepare("UPDATE servers SET status = 'stopped' WHERE id = ?");
            $updateStmt->execute([$serverId]);
            header('Location: dashboard.php?msg=stopped');
        } else {
            error_log("Proxmox stop error: {$result['response']}");
            header('Location: dashboard.php?msg=error_stop');
        }
        exit;

    case 'delete':
        // Spegni VM (soft shutdown)
        $urlShutdown = "$host/api2/json/nodes/$node/qemu/$vmid/status/shutdown";
        proxmoxApiRequest('POST', $urlShutdown);
        sleep(5); // aspetta un po’

        // Elimina VM
        $urlDelete = "$host/api2/json/nodes/$node/qemu/$vmid";
        $delResult = proxmoxApiRequest('DELETE', $urlDelete);

        if ($delResult['code'] !== 200) {
            error_log("Proxmox delete VM error: {$delResult['response']}");
            header('Location: dashboard.php?msg=error_delete');
            exit;
        }

        // Elimina record DNS Cloudflare se esiste
        if (!empty($server['subdomain'])) {
            // Usa la tua funzione per cancellare record DNS (da includere o copiare)
            deleteCloudflareDnsRecord($server['subdomain']);
        }

        // Disassocia VM dal DB
        $updateVmStmt = $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = NULL, assigned_server_id = NULL WHERE proxmox_vmid = ?");
        $updateVmStmt->execute([$vmid]);

        // Elimina record server
        $delStmt = $pdo->prepare("DELETE FROM servers WHERE id = ?");
        $delStmt->execute([$serverId]);

        header('Location: dashboard.php?msg=deleted');
        exit;

    default:
        http_response_code(400);
        exit('Azione non valida');
}

// Funzione per cancellare record DNS Cloudflare (aggiungi da te o includi il file che la contiene)
function deleteCloudflareDnsRecord($subdomain) {
    // la tua implementazione qui
}
