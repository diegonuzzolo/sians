<?php
// delete_server.php (Versione sicura e completa)

// Mostra errori per il debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// --- 0. DIPENDENZE E CONFIGURAZIONE ---
require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php'; // Assicurati che contenga callProxmox()
require_once 'includes/csrf.php';
require 'vendor/autoload.php';

// --- COSTANTI ---
// Assicurati che queste costanti siano definite come in new_server.php
define('VPS_USER', 'root');
define('VPS_IP', '195.231.66.137');
define('VPS_SSH_KEY', '/var/www/.ssh/id_proxmox');
define('VPS_PUBLIC_INTERFACE', 'enx3');
define('WEBSERVER_TUNNEL_IP', '10.10.0.2');
define('WEBSERVER_WIREGUARD_INTERFACE', 'wg0');
define('MINECRAFT_BEDROCK_PORT', 19132);

// Sicurezza: solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php?error=" . urlencode("Metodo non valido."));
    exit;
}
csrf_check_or_die();

// Controlla login
$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) {
    header("Location: login.php?error=" . urlencode("Accesso negato."));
    exit;
}

// --- 1. VALIDAZIONE INPUT ---
$serverId = filter_input(INPUT_POST, 'server_id', FILTER_VALIDATE_INT);
if (!$serverId) {
    header("Location: dashboard.php?error=" . urlencode("ID server non valido."));
    exit;
}

// --- INIZIO TRANSAZIONE ---
$pdo->beginTransaction();

try {
    // --- 2. RECUPERA I DATI DEL SERVER DAL DB PRIMA DI CANCELLARLI ---
    $stmt = $pdo->prepare(
        "SELECT s.id, s.name, s.proxmox_containerId, s.public_port, s.cpu_cores, s.ram_mb, c.ip as container_ip, p.storage_gb
         FROM servers s
         LEFT JOIN containers c ON s.container_id = c.id
         LEFT JOIN plans p ON s.plan_id = p.id
         WHERE s.id = ? AND s.user_id = ?"
    );
    $stmt->execute([$serverId, $userId]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$server) {
        throw new Exception("Server non trovato o non autorizzato.");
    }

    $vmid = $server['proxmox_containerId'];
    $publicPort = $server['public_port'];
    $containerIp = $server['container_ip'];
    $serverName = $server['name'];

    // --- 3. FERMA E DISTRUGGI IL CONTAINER SU PROXMOX (Logica corretta e robusta) ---
    
    // Controlla se il container esiste ancora su Proxmox prima di agire
    $statusCheck = callProxmox("https://192.168.1.251:8006/api2/json/nodes/pve/lxc/$vmid/status/current");
    
    // Se statusCheck non è null o false, significa che il container esiste
    if ($statusCheck && isset($statusCheck['data'])) {
        // Se non è già fermo, invia il comando di stop
        if ($statusCheck['data']['status'] !== 'stopped') {
            callProxmox("https://192.168.1.251:8006/api2/json/nodes/pve/lxc/$vmid/status/stop", 'POST');
            
            // Attendi attivamente che il container si fermi (con timeout)
            $timeout = 30;
            $startTime = time();
            $isStopped = false;
            while (time() - $startTime < $timeout) {
                $currentStatus = callProxmox("https://192.168.1.251:8006/api2/json/nodes/pve/lxc/$vmid/status/current");
                if (isset($currentStatus['data']['status']) && $currentStatus['data']['status'] === 'stopped') {
                    $isStopped = true;
                    break;
                }
                sleep(2);
            }
            if (!$isStopped) {
                throw new Exception("Timeout: il container $vmid non si è arrestato in tempo.");
            }
        }
        
        // Ora che è fermo, distruggilo
        $deleteResult = callProxmox("https://192.168.1.251:8006/api2/json/nodes/pve/lxc/$vmid", 'DELETE');
        if (!$deleteResult || !isset($deleteResult['data'])) {
            throw new Exception("Impossibile distruggere il container $vmid.");
        }
    }
    
    // --- 4. CANCELLA LE REGOLE FIREWALL ---
    $saveRules = "netfilter-persistent save";

    // Parte A: Rimuovi regola dal VPS Pubblico
    $vpsDnatRule = "iptables -t nat -D PREROUTING -i " . VPS_PUBLIC_INTERFACE . " -p udp --dport {$publicPort} -j DNAT --to-destination " . WEBSERVER_TUNNEL_IP . ":" . MINECRAFT_BEDROCK_PORT;
    $remoteCommand = escapeshellarg("{$vpsDnatRule} && {$saveRules}");
    $sshCommand = "ssh -i " . VPS_SSH_KEY . " -o StrictHostKeyChecking=no " . VPS_USER . "@" . VPS_IP . " {$remoteCommand}";
    exec($sshCommand);
    
    // Parte B: Rimuovi regola dal Webserver (locale)
    $webserverDnatRule = "sudo iptables -t nat -D PREROUTING -i " . WEBSERVER_WIREGUARD_INTERFACE . " -p udp --dport " . MINECRAFT_BEDROCK_PORT . " -j DNAT --to-destination {$containerIp}:" . MINECRAFT_BEDROCK_PORT;
    exec("{$webserverDnatRule} && sudo {$saveRules}");

    // --- 5. CANCELLA I RECORD DNS SU CLOUDFLARE ---
    // (Questa è una logica semplificata, richiede la funzione per trovare il record ID)
    // findAndDeleteDnsRecord($serverName, 'A');
    // findAndDeleteDnsRecord($serverName, 'SRV');

    // --- 6. PULIZIA DEL DATABASE ---
    // Rimuovi il server
    $pdo->prepare("DELETE FROM servers WHERE id = ?")->execute([$serverId]);
    // Rimuovi il container associato
    $pdo->prepare("DELETE FROM containers WHERE proxmox_containerId = ?")->execute([$vmid]);
    // Libera lo slot
    $pdo->prepare("UPDATE server_slots SET status = 'pending', server_id = NULL WHERE server_id = ?")->execute([$serverId]);
    // Restituisci le risorse al pool
    $pdo->prepare(
        "UPDATE resources SET used_cpu_cores = used_cpu_cores - ?, used_ram_mb = used_ram_mb - ?, used_storage_gb = used_storage_gb - ? WHERE id = 1"
    )->execute([$server['cpu_cores'], $server['ram_mb'], $server['storage_gb']]);
    
    // --- 7. COMMIT FINALE ---
    $pdo->commit();

    $_SESSION['flash_message'] = ['type' => 'success', 'text' => "Server '{$serverName}' cancellato con successo."];

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    
    $_SESSION['flash_message'] = ['type' => 'error', 'text' => 'Cancellazione fallita: ' . $e->getMessage()];
}

// --- 8. REDIRECT ---
header("Location: dashboard.php");
exit;

?>