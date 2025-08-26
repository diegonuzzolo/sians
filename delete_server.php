<?php
// delete_server.php (Versione aggiornata per AJAX con output JSON)

// Mostra errori per debug (puoi disattivarli in produzione)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// --- 0. DIPENDENZE E CONFIGURAZIONE ---
require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php';
require_once 'includes/csrf.php';
require 'vendor/autoload.php';

// --- COSTANTI ---
define('VPS_USER', 'root');
define('VPS_IP', '195.231.66.137');
define('VPS_SSH_KEY', '/var/www/.ssh/id_proxmox');
define('VPS_PUBLIC_INTERFACE', 'enx3');
define('WEBSERVER_TUNNEL_IP', '10.10.0.2');
define('WEBSERVER_WIREGUARD_INTERFACE', 'wg0');
define('MINECRAFT_BEDROCK_PORT', 19132);
define('PROXMOX_NODE_IP', '192.168.1.251');

// Risposta JSON
header('Content-Type: application/json');

// Sicurezza: solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Metodo non valido.']);
    exit;
}
csrf_check_or_die();

// Controlla login
$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) {
    echo json_encode(['status' => 'error', 'message' => 'Accesso negato.']);
    exit;
}

// --- 1. VALIDAZIONE INPUT ---
$serverId = filter_input(INPUT_POST, 'server_id', FILTER_VALIDATE_INT);
if (!$serverId) {
    echo json_encode(['status' => 'error', 'message' => 'ID server non valido.']);
    exit;
}

// --- INIZIO TRANSAZIONE ---
$pdo->beginTransaction();

try {
    // --- 2. RECUPERA I DATI DEL SERVER DAL DB ---
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

    // --- 3. FERMA PROCESSI E DISTRUGGI IL CONTAINER ---

        // Termina screen + tail
        $screenSessionName = "mc-{$vmid}";
        $killCmd = "pct exec $vmid -- bash -c 'screen -S {$screenSessionName} -X quit; pkill -f \"tail -f server.log\"'";
        exec("ssh -i " . VPS_SSH_KEY . " -o StrictHostKeyChecking=no root@" . PROXMOX_NODE_IP . " \"$killCmd\"");

        // Ferma e distruggi container
        callProxmox("https://".PROXMOX_NODE_IP.":8006/api2/json/nodes/pve/lxc/$vmid/status/stop", 'POST');
        sleep(5);
        callProxmox("https://".PROXMOX_NODE_IP.":8006/api2/json/nodes/pve/lxc/$vmid", 'DELETE');

    // Pulisci log su host
    $localLogFile = "/var/log/game_logs/log_server_{$vmid}.txt";
    exec("ssh -i " . VPS_SSH_KEY . " -o StrictHostKeyChecking=no root@" . PROXMOX_NODE_IP . " \"rm -f {$localLogFile}\"");

    // --- 4. CANCELLA LE REGOLE FIREWALL ---
    $saveRules = "netfilter-persistent save";

    // VPS Pubblico
    $vpsDnatRule = "iptables -t nat -D PREROUTING -i " . VPS_PUBLIC_INTERFACE . " -p udp --dport {$publicPort} -j DNAT --to-destination " . WEBSERVER_TUNNEL_IP . ":" . MINECRAFT_BEDROCK_PORT;
    $remoteCommand = escapeshellarg("{$vpsDnatRule} && {$saveRules}");
    $sshCommand = "ssh -i " . VPS_SSH_KEY . " -o StrictHostKeyChecking=no " . VPS_USER . "@" . VPS_IP . " {$remoteCommand}";
    exec($sshCommand);

    // Webserver locale
    $webserverDnatRule = "sudo iptables -t nat -D PREROUTING -i " . WEBSERVER_WIREGUARD_INTERFACE . " -p udp --dport " . MINECRAFT_BEDROCK_PORT . " -j DNAT --to-destination {$containerIp}:" . MINECRAFT_BEDROCK_PORT;
    exec("{$webserverDnatRule} && sudo {$saveRules}");

    // --- 5. DNS Cloudflare (TODO se serve) ---
    // findAndDeleteDnsRecord($serverName, 'A');
    // findAndDeleteDnsRecord($serverName, 'SRV');

    // --- 6. PULIZIA DEL DATABASE ---
    $pdo->prepare("DELETE FROM servers WHERE id = ?")->execute([$serverId]);
    $pdo->prepare("DELETE FROM containers WHERE proxmox_containerId = ?")->execute([$vmid]);
    $pdo->prepare("UPDATE server_slots SET status = 'pending', server_id = NULL WHERE server_id = ?")->execute([$serverId]);
    $pdo->prepare(
        "UPDATE resources SET used_cpu_cores = used_cpu_cores - ?, used_ram_mb = used_ram_mb - ?, used_storage_gb = used_storage_gb - ? WHERE id = 1"
    )->execute([$server['cpu_cores'], $server['ram_mb'], $server['storage_gb']]);

    // --- 7. COMMIT ---
    $pdo->commit();

    echo json_encode([
        'status' => 'success',
        'message' => "Server '{$serverName}' e tutte le sue risorse sono stati cancellati."
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();

    echo json_encode([
        'status' => 'error',
        'message' => 'Cancellazione fallita: ' . $e->getMessage()
    ]);
}

exit;
