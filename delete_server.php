<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'auth_check.php';
require_once 'includes/csrf.php';
require 'config/config.php';
require_once 'proxmox_functions.php';

/**
 * Funzione robusta per la pulizia completa delle risorse Cloudflare.
 * Utilizza l'API di Cloudflare (tramite cURL) per eliminare il record DNS in modo affidabile.
 */
function deleteCloudflareResources(string $serverName, string $tunnelName, string $tunnelUuid) {
    $hostname = $serverName . '.mc.sians.it';
    
    // --- PARTE 1: Eliminazione del record DNS tramite API ---
    
    // 1a. Trova l'ID del record DNS
    $findUrl = "https://api.cloudflare.com/client/v4/zones/" . CLOUDFLARE_ZONE_ID . "/dns_records?name=" . urlencode($hostname);
    $ch_find = curl_init($findUrl);
    curl_setopt($ch_find, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_find, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . CLOUDFLARE_API_TOKEN,
        "Content-Type: application/json"
    ]);
    $response = curl_exec($ch_find);
    if (curl_errno($ch_find)) {
        throw new Exception("Errore cURL nella ricerca DNS: " . curl_error($ch_find));
    }
    $data = json_decode($response, true);
    curl_close($ch_find);

    // 1b. Se il record esiste, eliminalo
    if (isset($data['result']) && !empty($data['result'])) {
        $recordId = $data['result'][0]['id'];
        
        $deleteUrl = "https://api.cloudflare.com/client/v4/zones/" . CLOUDFLARE_ZONE_ID . "/dns_records/" . $recordId;
        $ch_del = curl_init($deleteUrl);
        curl_setopt($ch_del, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($ch_del, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_del, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . CLOUDFLARE_API_TOKEN,
            "Content-Type: application/json"
        ]);
        $del_response = curl_exec($ch_del);
        if (curl_errno($ch_del)) {
            throw new Exception("Errore cURL nell'eliminazione DNS: " . curl_error($ch_del));
        }
        $del_data = json_decode($del_response, true);
        curl_close($ch_del);

        if (!isset($del_data['success']) || !$del_data['success']) {
            throw new Exception("Errore API Cloudflare: Impossibile eliminare il record DNS.");
        }
    }
    // Se il record non esiste, non facciamo nulla e procediamo.

    // --- PARTE 2: Pulizia del tunnel e dei file locali tramite CLI ---
    $output = []; $return_var = 0;
    
    exec("sudo /usr/bin/systemctl disable --now cloudflared@{$tunnelName}.service 2>&1", $output, $return_var);
    if ($return_var !== 0 && !str_contains(implode('', $output), 'does not exist')) {
        throw new Exception("Errore nel fermare il servizio del tunnel.");
    }

    exec("sudo /usr/bin/cloudflared tunnel delete {$tunnelName} 2>&1", $output, $return_var);
    if ($return_var !== 0 && !str_contains(implode('', $output), 'not found')) {
        throw new Exception("Errore nell'eliminare il tunnel da Cloudflare.");
    }
    
    exec("sudo /bin/rm /root/.cloudflared/{$tunnelName}.yml");
}

// --- Script Principale ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); die("Metodo non consentito.");
}
csrf_check_or_die();

$userId = $_SESSION['user_id'] ?? 0;
$vmid = intval($_POST['vmid'] ?? 0);

if ($vmid <= 0) {
    http_response_code(400); die("VMID non valido.");
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT s.*, c.ram_mb, c.storage_gb FROM servers s LEFT JOIN containers c ON c.id = s.container_id WHERE s.user_id = ? AND s.proxmox_containerId = ?");
    $stmt->execute([$userId, $vmid]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$server) {
        throw new Exception("Server non trovato o non appartiene all'utente. VMID: $vmid");
    }

    // Distruzione Risorse Esterne
    callProxmox("https://192.168.1.251:8006/api2/json/nodes/pve/lxc/$vmid/status/stop", 'POST');
    sleep(4);
    $destroyResult = callProxmox("https://192.168.1.251:8006/api2/json/nodes/pve/lxc/$vmid", 'DELETE');
    if (!$destroyResult['success']) {
        throw new Exception("Errore durante la distruzione del container Proxmox: " . $destroyResult['message']);
    }

    // Chiama la funzione di pulizia potenziata
    if (!empty($server['name']) && !empty($server['tunnel_id']) && !empty($server['tunnel_uuid'])) {
        deleteCloudflareResources($server['name'], $server['tunnel_id'], $server['tunnel_uuid']);
    }

    // Aggiornamento Database
    $stmt = $pdo->prepare("UPDATE resources SET used_cpu_cores = used_cpu_cores - ?, used_ram_mb = used_ram_mb - ?, used_storage_gb = used_storage_gb - ? WHERE id = 1");
    $stmt->execute([intval($server['cpu_cores']), intval($server['ram_mb']), intval($server['storage_gb'])]);
    
    $stmt = $pdo->prepare("UPDATE server_slots SET server_id=NULL, status='pending' WHERE id = ?");
    $stmt->execute([$server['slot_id']]);

    $stmt = $pdo->prepare("DELETE FROM servers WHERE id = ?");
    $stmt->execute([$server['id']]);
    
    $pdo->commit();

    echo "Server eliminato correttamente";
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    die("Errore: " . $e->getMessage());
}
?>