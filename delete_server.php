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

// Recupera il server per verificarne la proprietà e ottenere info per DNS
$stmt = $pdo->prepare("SELECT subdomain, proxmox_vmid FROM servers WHERE id = ? AND user_id = ?");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    http_response_code(403);
    exit('Server non trovato o accesso negato');
}

$subdomain = trim($server['subdomain'] ?? '');

// Funzione per cancellare record DNS Cloudflare
function deleteCloudflareDnsRecord(string $subdomain): bool {
    if (!$subdomain) {
        // Nessun subdomain da eliminare
        return true;
    }

    $zoneId = CLOUDFLARE_ZONE_ID;
    $apiToken = CLOUDFLARE_API_TOKEN;
    $apiBase = CLOUDFLARE_API_BASE;

    $fqdn = $subdomain . '.' . DOMAIN;

    // Ottieni lista record DNS
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
        // Nessun record trovato, niente da eliminare
        return true;
    }

    // Prendi l'ID del primo record DNS da eliminare
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

// Elimina record DNS Cloudflare se necessario
if ($subdomain) {
    $dnsDeleted = deleteCloudflareDnsRecord($subdomain);
    if (!$dnsDeleted) {
        error_log("Errore durante l'eliminazione del record DNS per $subdomain");
        // Qui potresti decidere se bloccare la cancellazione oppure continuare comunque
    }
}

// Disassocia VM (rimuove assegnazioni)
$updateVmStmt = $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = NULL, assigned_server_id = NULL WHERE proxmox_vmid = ?");
$updateVmStmt->execute([$server['proxmox_vmid']]);

// Elimina server dal DB
$delStmt = $pdo->prepare("DELETE FROM servers WHERE id = ? AND user_id = ?");
$delStmt->execute([$serverId, $userId]);

// Redirect con messaggio di successo
header('Location: dashboard.php?msg=deleted');
exit;
