<?php
// FILE: delete_server.php (Versione Finale e Definitiva)

ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'auth_check.php';
require_once 'includes/csrf.php';
require 'config/config.php';
require_once 'proxmox_functions.php';

/**
 * Funzione robusta per la pulizia completa delle risorse Cloudflare.
 * Ora riceve l'hostname direttamente, rendendola più flessibile.
 */
function deleteCloudflareResources(string $hostname, string $tunnelName, string $tunnelUuid) {
    // --- PARTE API: Eliminazione diretta del record DNS ---
    $ch = curl_init("https://api.cloudflare.com/client/v4/zones/" . CLOUDFLARE_ZONE_ID . "/dns_records?name=" . $hostname);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . CLOUDFLARE_API_TOKEN,
        "Content-Type: application/json"
    ]);
    $response = curl_exec($ch);
    $data = json_decode($response, true);
    curl_close($ch);

    if (isset($data['result']) && !empty($data['result'])) {
        $recordId = $data['result'][0]['id'];
        $ch_del = curl_init("https://api.cloudflare.com/client/v4/zones/" . CLOUDFLARE_ZONE_ID . "/dns_records/" . $recordId);
        curl_setopt($ch_del, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($ch_del, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_del, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . CLOUDFLARE_API_TOKEN,
            "Content-Type: application/json"
        ]);
        curl_exec($ch_del);
        curl_close($ch_del);
    }

    // --- PARTE CLI: Pulizia del tunnel e dei file locali ---
    exec("sudo /usr/bin/systemctl disable --now cloudflared@{$tunnelName}.service");
    exec("sudo /usr/bin/cloudflared tunnel delete -y {$tunnelName}");
    if (file_exists("/root/.cloudflared/{$tunnelName}.yml")) exec("sudo /bin/rm /root/.cloudflared/{$tunnelName}.yml");
    if (file_exists("/root/.cloudflared/{$tunnelUuid}.json")) exec("sudo /bin/rm /root/.cloudflared/{$tunnelUuid}.json");
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

    // Aggiorna la query per leggere anche la nuova colonna 'hostname'
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

    // Passa l'hostname letto dal DB alla funzione di pulizia
    if (!empty($server['hostname']) && !empty($server['tunnel_id']) && !empty($server['tunnel_uuid'])) {
        deleteCloudflareResources($server['hostname'], $server['tunnel_id'], $server['tunnel_uuid']);
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