<?php
// FILE: delete_server.php (Compatibile con new_server.php)

ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'auth_check.php';
require_once 'includes/csrf.php';
require 'config/config.php';
require_once 'proxmox_functions.php';

// Funzione per rimuovere record DNS A e SRV su Cloudflare
function deleteCloudflareRecords(string $serverName) {
    $headers = [
        "Authorization: Bearer " . CLOUDFLARE_API_TOKEN,
        "Content-Type: application/json"
    ];

    $zoneId = CLOUDFLARE_ZONE_ID;
    $hostname = $serverName . '.sians.it';

    // --- 1. Trova tutti i record DNS per questo hostname ---
    $ch = curl_init("https://api.cloudflare.com/client/v4/zones/$zoneId/dns_records?name=" . urlencode($hostname));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resp = curl_exec($ch);
    if (curl_errno($ch)) throw new Exception("Errore cURL nella ricerca DNS: " . curl_error($ch));
    $data = json_decode($resp, true);
    curl_close($ch);

    if (!empty($data['result'])) {
        foreach ($data['result'] as $record) {
            $recordId = $record['id'];
            $chDel = curl_init("https://api.cloudflare.com/client/v4/zones/$zoneId/dns_records/$recordId");
            curl_setopt($chDel, CURLOPT_CUSTOMREQUEST, "DELETE");
            curl_setopt($chDel, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($chDel, CURLOPT_RETURNTRANSFER, true);
            $delResp = curl_exec($chDel);
            if (curl_errno($chDel)) throw new Exception("Errore cURL nell'eliminazione DNS: " . curl_error($chDel));
            $delData = json_decode($delResp, true);
            curl_close($chDel);

            if (!($delData['success'] ?? false)) {
                throw new Exception("Impossibile eliminare il record DNS: {$record['name']} ({$record['type']})");
            }
        }
    }
}

// --- Script Principale ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die("Metodo non consentito");
}
csrf_check_or_die();

$userId = $_SESSION['user_id'] ?? 0;
$vmid = intval($_POST['vmid'] ?? 0);
if ($vmid <= 0) die("VMID non valido");

try {
    $pdo->beginTransaction();

    // Recupera server e container
    $stmt = $pdo->prepare("
        SELECT s.*, c.ip, c.ram_mb, c.storage_gb, c.id as container_id 
        FROM servers s 
        LEFT JOIN containers c ON c.id = s.container_id 
        WHERE s.user_id = ? AND s.proxmox_containerId = ?
    ");
    $stmt->execute([$userId, $vmid]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$server) throw new Exception("Server non trovato o non appartiene all'utente");

    $containerId = $server['container_id'] ?? null;

    // --- 1. Ferma e distruggi container Proxmox ---
    callProxmox("https://192.168.1.251:8006/api2/json/nodes/pve/lxc/$vmid/status/stop", 'POST');
    sleep(3);
    $destroy = callProxmox("https://192.168.1.251:8006/api2/json/nodes/pve/lxc/$vmid", 'DELETE');
    if (!$destroy['success']) throw new Exception("Errore distruzione container: " . $destroy['message']);

    // --- 2. Rimuove record DNS A e SRV ---
    if (!empty($server['name'])) {
        deleteCloudflareRecords($server['name']);
    }

    // --- 3. Aggiorna risorse nel DB ---
    $pdo->prepare("UPDATE resources SET used_cpu_cores = used_cpu_cores - ?, used_ram_mb = used_ram_mb - ?, used_storage_gb = used_storage_gb - ? WHERE id = 1")
        ->execute([
            intval($server['cpu_cores']),
            intval($server['ram_mb']),
            intval($server['storage_gb'])
        ]);

    // --- 4. Reset slot ---
    if (!empty($server['slot_id'])) {
        $pdo->prepare("UPDATE server_slots SET server_id = NULL, status = 'pending' WHERE id = ?")
            ->execute([$server['slot_id']]);
    }

    // --- 5. Elimina server e container dal DB ---
    if ($containerId) {
        $pdo->prepare("DELETE FROM containers WHERE id = ?")->execute([$containerId]);
    }
    $pdo->prepare("DELETE FROM servers WHERE id = ?")->execute([$server['id']]);

    $pdo->commit();

    echo "Server eliminato correttamente";

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    die("Errore: " . $e->getMessage());
}
