<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'auth_check.php';
require_once 'includes/csrf.php';
require 'config/config.php';
require_once 'proxmox_functions.php';

// --- 1. Sicurezza: Accetta solo richieste POST ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Metodo non consentito.");
}
csrf_check_or_die();

$userId = $_SESSION['user_id'] ?? 0;
// Il VMID ora viene passato tramite POST
$vmid = intval($_POST['vmid'] ?? 0);

if ($vmid <= 0) {
    die("VMID non valido.");
}

try {
    $pdo->beginTransaction();

    // --- 2. Recupera tutte le informazioni necessarie del server, incluso il tunnel ---
    $stmt = $pdo->prepare("
        SELECT 
            s.id AS server_id, 
            s.slot_id, 
            s.cpu_cores, 
            s.tunnel_id, 
            s.tunnel_uuid,
            c.ram_mb, 
            c.storage_gb, 
            c.proxmox_containerId
        FROM servers s
        LEFT JOIN containers c ON c.id = s.container_id
        WHERE s.user_id = ? AND c.proxmox_containerId = ?
    ");
    $stmt->execute([$userId, $vmid]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$server) {
        throw new Exception("Server non trovato o non appartiene all'utente. VMID: $vmid");
    }

    // --- 3. Distruzione Risorse Esterne (Proxmox e Cloudflare) ---
    
    // Ferma e distruggi il container su Proxmox
    $stopResult = callProxmox("https://192.168.1.251:8006/api2/json/nodes/pve/lxc/$vmid/status/stop", 'POST');
    // Nota: Non consideriamo un fallimento se il container è già fermo.
    sleep(4);
    $destroyResult = callProxmox("https://192.168.1.251:8006/api2/json/nodes/pve/lxc/$vmid", 'DELETE');
    if (!$destroyResult['success']) {
        throw new Exception("Errore durante la distruzione del container Proxmox: " . $destroyResult['message']);
    }

    // NUOVO: Ferma e distruggi il tunnel Cloudflare associato
    if (!empty($server['tunnel_id']) && !empty($server['tunnel_uuid'])) {
        $tunnelName = $server['tunnel_id'];
        $tunnelUuid = $server['tunnel_uuid'];
        $configFile = "/root/.cloudflared/{$tunnelName}.yml";
        $credentialsFile = "/root/.cloudflared/{$tunnelUuid}.json";

        // a. Ferma e disabilita il servizio systemd
        exec("sudo /usr/bin/systemctl disable --now cloudflared@{$tunnelName}.service");

        // b. Elimina il tunnel da Cloudflare (-y per confermare)
        exec("sudo /usr/bin/cloudflared tunnel delete -y {$tunnelName}");

        // c. Pulisci il file di configurazione e le credenziali
        if (file_exists($configFile)) {
            exec("sudo /bin/rm {$configFile}");
        }
        if (file_exists($credentialsFile)) {
            exec("sudo /bin/rm {$credentialsFile}");
        }
    }

    // --- 4. Aggiornamento Database ---
    
    // Libera le risorse
    $stmt = $pdo->prepare(
        "UPDATE resources SET used_cpu_cores = used_cpu_cores - ?, used_ram_mb = used_ram_mb - ?, used_storage_gb = used_storage_gb - ? WHERE id = 1"
    );
    $stmt->execute([intval($server['cpu_cores']), intval($server['ram_mb']), intval($server['storage_gb'])]);
    
    // Libera lo slot
    $stmt = $pdo->prepare("UPDATE server_slots SET server_id=NULL, status='pending' WHERE id = ?");
    $stmt->execute([$server['slot_id']]);

    // Elimina il record del server (e a cascata il container)
    $stmt = $pdo->prepare("DELETE FROM servers WHERE id = ?");
    $stmt->execute([$server['server_id']]);
    
    // Se tutto è andato a buon fine, conferma
    $pdo->commit();

    echo "Server eliminato correttamente";
    //header("Location: dashboard.php?msg=Server eliminato correttamente");
    exit;

} catch (Exception $e) {
    // Se qualcosa va storto, annulla tutte le modifiche al DB
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Invia un errore HTTP 500 (Internal Server Error) e il messaggio
    http_response_code(500);
    die("Errore: " . $e->getMessage());
}
?>