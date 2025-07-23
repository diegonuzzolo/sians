<?php
session_start();
require 'config/config.php';
require 'includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['server_id'])) {
    http_response_code(400);
    exit('Richiesta non valida');
}

$userId = $_SESSION['user_id'];
$serverId = intval($_POST['server_id']);

// Recupera il server per verificarne la proprietà e ottenere info per DNS
$stmt = $pdo->prepare("SELECT subdomain, proxmox_vmid FROM servers WHERE id = ? AND user_id = ?");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    http_response_code(403);
    exit('Server non trovato o accesso negato');
}

$subdomain = $server['subdomain'];

// Funzione per cancellare record DNS Cloudflare
function deleteCloudflareDnsRecord($subdomain) {
    $zoneId = CLOUDFLARE_ZONE_ID;
    $apiToken = CLOUDFLARE_API_TOKEN;
    $apiBase = CLOUDFLARE_API_BASE;

    // Ottieni lista record DNS per trovare ID del record da eliminare
    $url = "$apiBase/zones/$zoneId/dns_records?type=CNAME&name=$subdomain." . DOMAIN;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $apiToken",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (!isset($data['result']) || count($data['result']) === 0) {
        // Nessun record trovato, niente da eliminare
        return true;
    }

    // Prendi l'ID del record DNS da eliminare
    $dnsRecordId = $data['result'][0]['id'];

    // Elimina il record DNS
    $delUrl = "$apiBase/zones/$zoneId/dns_records/$dnsRecordId";

    $ch = curl_init($delUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $apiToken",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $delResponse = curl_exec($ch);
    curl_close($ch);

    $delData = json_decode($delResponse, true);

    return isset($delData['success']) && $delData['success'] === true;
}

// Elimina record DNS se esiste
if ($subdomain) {
    $dnsDeleted = deleteCloudflareDnsRecord($subdomain);
    if (!$dnsDeleted) {
        // Puoi loggare o gestire errore a piacere
        error_log("Errore durante l'eliminazione del record DNS per $subdomain");
    }
}

// Disassocia VM (rimuove assegnazioni)
$updateVmStmt = $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = NULL, assigned_server_id = NULL WHERE proxmox_vmid = ?");
$updateVmStmt->execute([$server['proxmox_vmid']]);

// Elimina server dal DB
$delStmt = $pdo->prepare("DELETE FROM servers WHERE id = ? AND user_id = ?");
$delStmt->execute([$serverId, $userId]);

header('Location: dashboard.php?msg=deleted');
exit;
