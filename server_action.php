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
$action = $_POST['action']; // 'start', 'stop', 'delete'

// Recupera il server per verificarne la proprietà e info VM
$stmt = $pdo->prepare("SELECT id, subdomain, proxmox_vmid FROM servers WHERE id = ? AND user_id = ?");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    http_response_code(403);
    exit('Server non trovato o accesso negato');
}

$vmid = intval($server['proxmox_vmid']);
$node = PROXMOX_NODE;
$host = rtrim(PROXMOX_HOST, '/');
$tokenId = PROXMOX_API_TOKEN_ID;
$tokenSecret = PROXMOX_API_TOKEN_SECRET;

function proxmoxApiRequest($method, $url) {
    global $tokenId, $tokenSecret;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: PVEAPIToken=$tokenId=$tokenSecret",
        "Accept: application/json"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    return ['response' => $response, 'code' => $httpCode];
}

switch ($action) {
    case 'start':
        $url = "$host/api2/json/nodes/$node/qemu/$vmid/status/start";
        $res = proxmoxApiRequest('POST', $url);
        if ($res['code'] === 200) {
            $newStatus = 'running';
        } else {
            error_log("Proxmox start VM error: HTTP {$res['code']}, response: {$res['response']}");
            header('Location: dashboard.php?msg=error_start');
            exit;
        }
        break;

    case 'stop':
        $url = "$host/api2/json/nodes/$node/qemu/$vmid/status/stop";
        $res = proxmoxApiRequest('POST', $url);
        if ($res['code'] === 200) {
            $newStatus = 'stopped';
        } else {
            error_log("Proxmox stop VM error: HTTP {$res['code']}, response: {$res['response']}");
            header('Location: dashboard.php?msg=error_stop');
            exit;
        }
        break;

    case 'delete':
        // Spegni VM (soft shutdown) - opzionale, o usa stop
        $urlShutdown = "$host/api2/json/nodes/$node/qemu/$vmid/status/shutdown";
        proxmoxApiRequest('POST', $urlShutdown);
        sleep(5); // attesa

        // Elimina VM
        $urlDelete = "$host/api2/json/nodes/$node/qemu/$vmid";
        $delRes = proxmoxApiRequest('DELETE', $urlDelete);
        error_log("Proxmox delete VM response: HTTP {$delRes['code']}, body: {$delRes['response']}");

        if ($delRes['code'] !== 200) {
            header('Location: dashboard.php?msg=error_delete');
            exit;
        }

        // Elimina record DNS Cloudflare
        if (!empty($server['subdomain'])) {
            if (!function_exists('deleteCloudflareDnsRecord')) {
                function deleteCloudflareDnsRecord($subdomain) {
                    $zoneId = CLOUDFLARE_ZONE_ID;
                    $apiToken = CLOUDFLARE_API_TOKEN;
                    $apiBase = CLOUDFLARE_API_BASE;

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
                    curl_close($ch);

                    $delData = json_decode($delResponse, true);

                    return isset($delData['success']) && $delData['success'] === true;
                }
            }

            $dnsDeleted = deleteCloudflareDnsRecord($server['subdomain']);
            if (!$dnsDeleted) {
                error_log("Errore eliminazione DNS per {$server['subdomain']}");
            }
        }

        // Disassocia VM in DB
        $updateVmStmt = $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = NULL, assigned_server_id = NULL WHERE proxmox_vmid = ?");
        $updateVmStmt->execute([$vmid]);

        // Elimina server dal DB
        $delStmt = $pdo->prepare("DELETE FROM servers WHERE id = ?");
        $delStmt->execute([$serverId]);

        header('Location: dashboard.php?msg=deleted');
        exit;

    default:
        http_response_code(400);
        exit('Azione non valida');
}

if (isset($newStatus)) {
    // Aggiorna stato nel DB
    $updateStmt = $pdo->prepare("UPDATE servers SET status = ? WHERE id = ?");
    $updateStmt->execute([$newStatus, $serverId]);

    header('Location: dashboard.php?msg=success');
    exit;
}
