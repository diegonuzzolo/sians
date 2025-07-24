<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/config.php';
require_once 'includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['server_id'])) {
    http_response_code(400);
    exit('Richiesta non valida');
}

$userId = $_SESSION['user_id'];
$serverId = intval($_POST['server_id']);

// Recupera il server per verificarne la proprietà e ottenere info per DNS e Proxmox VM ID
$stmt = $pdo->prepare("SELECT subdomain, proxmox_vmid FROM servers WHERE id = ? AND user_id = ?");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    http_response_code(403);
    exit('Server non trovato o accesso negato');
}

$subdomain = trim($server['subdomain'] ?? '');
$vmid = $server['proxmox_vmid'];
$node = PROXMOX_NODE; // Definito nel config.php

// Funzione per cancellare record DNS Cloudflare (come da tuo script precedente)
function deleteCloudflareDnsRecord(string $subdomain): bool {
    if (!$subdomain) {
        return true;
    }

    $zoneId = CLOUDFLARE_ZONE_ID;
    $apiToken = CLOUDFLARE_API_TOKEN;
    $apiBase = CLOUDFLARE_API_BASE;

    $fqdn = $subdomain . '.' . DOMAIN;

    $url = "$apiBase/zones/$zoneId/dns_records?type=CNAME&name=$fqdn";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $apiToken",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    if ($response === false) {
        error_log('Cloudflare API curl error: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }
    curl_close($ch);

    $data = json_decode($response, true);
    if (!isset($data['result']) || count($data['result']) === 0) {
        return true;
    }

    $dnsRecordId = $data['result'][0]['id'];

    $delUrl = "$apiBase/zones/$zoneId/dns_records/$dnsRecordId";

    $ch = curl_init($delUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $apiToken",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $delResponse = curl_exec($ch);
    if ($delResponse === false) {
        error_log('Cloudflare API curl error during delete: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }
    curl_close($ch);

    $delData = json_decode($delResponse, true);

    if (isset($delData['success']) && $delData['success'] === true) {
        return true;
    } else {
        error_log('Cloudflare API delete response error: ' . print_r($delData, true));
        return false;
    }
}

// Funzione helper per chiamare API Proxmox
function proxmoxApiRequest(string $method, string $endpoint, array $postData = null): array {
    $host = PROXMOX_HOST;  // es. https://pve.miodominio.it:8006/api2/json
    $tokenId = PROXMOX_API_TOKEN_ID;
    $tokenSecret = PROXMOX_API_TOKEN_SECRET;

    $url = rtrim($host, '/') . '/' . ltrim($endpoint, '/');

    $headers = [
        "Authorization: PVEAPIToken=$tokenId=$tokenSecret",
        "Accept: application/json",
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    if ($postData !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    }

    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception('Errore curl: ' . curl_error($ch));
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new Exception("Errore API Proxmox: HTTP $httpCode, risposta: $response");
    }

    return json_decode($response, true);
}

try {
    // 1) Spegni VM (soft shutdown)
    proxmoxApiRequest('POST', "nodes/$node/qemu/$vmid/status/shutdown");

    // Attendi 5 secondi (opzionale, per permettere lo spegnimento)
    sleep(5);

    // 2) Forza stop se la VM non si spegne (opzionale)
    // proxmoxApiRequest('POST', "nodes/$node/qemu/$vmid/status/stop");

    // 3) Elimina VM
    proxmoxApiRequest('DELETE', "nodes/$node/qemu/$vmid");

} catch (Exception $e) {
    error_log("Errore eliminazione VM Proxmox: " . $e->getMessage());
    // Decidi se bloccare o continuare comunque
    // Per ora continuiamo l’esecuzione
}

// Elimina record DNS Cloudflare
if ($subdomain) {
    $dnsDeleted = deleteCloudflareDnsRecord($subdomain);
    if (!$dnsDeleted) {
        error_log("Errore durante l'eliminazione del record DNS per $subdomain");
    }
}

// Disassocia VM dal DB
$updateVmStmt = $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = NULL, assigned_server_id = NULL WHERE proxmox_vmid = ?");
$updateVmStmt->execute([$vmid]);

// Elimina record server dal DB
$delStmt = $pdo->prepare("DELETE FROM servers WHERE id = ? AND user_id = ?");
$delStmt->execute([$serverId, $userId]);

header('Location: dashboard.php?msg=deleted');
exit;
