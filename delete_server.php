<?php
// FILE: delete_server.php

ini_set('display_errors', 1);
error_reporting(E_ALL);

// --- 0. DIPENDENZE E CONFIGURAZIONE ---
require 'auth_check.php';
require_once 'includes/csrf.php';
require 'config/config.php';
require_once 'proxmox_functions.php';

// --- COSTANTI PER LA CONNESSIONE ---
define('VPS_USER', 'root');
define('VPS_IP', '195.231.66.137');
define('VPS_SSH_KEY', '/var/www/.ssh/id_proxmox'); // Chiave per connettersi al VPS
define('PUBLIC_INTERFACE', 'enx3'); // Interfaccia pubblica del VPS

define('WEBSERVER_TUNNEL_IP', '10.10.0.2'); // IP del webserver nel tunnel
define('WEBSERVER_LAN_INTERFACE', 'eth0'); // Interfaccia LAN del webserver (da verificare con `ip a`)
define('MINECRAFT_BEDROCK_PORT', 19132);


// --- FUNZIONI HELPER ---

/**
 * Rimuove le regole iptables dal VPS Pubblico.
 * @param int $publicPort La porta pubblica da chiudere.
 */
function removeVpsFirewallRules(int $publicPort) {
    $escapedPublicPort = escapeshellarg($publicPort);
    $webserverTunnelIp = escapeshellarg(WEBSERVER_TUNNEL_IP);
    $minecraftPort = escapeshellarg(MINECRAFT_BEDROCK_PORT);

    // Comando per rimuovere la regola DNAT
    $dnatRule = "iptables -t nat -D PREROUTING -i " . PUBLIC_INTERFACE . " -p udp --dport {$escapedPublicPort} -j DNAT --to-destination {$webserverTunnelIp}:{$minecraftPort}";
    
    // Comando per salvare le regole
    $saveRules = "netfilter-persistent save";

    $remoteCommand = escapeshellarg("{$dnatRule} && {$saveRules}");
    $sshCommand = "ssh -i " . VPS_SSH_KEY . " -o StrictHostKeyChecking=no -o ConnectTimeout=10 " . VPS_USER . "@" . VPS_IP . " {$remoteCommand}";

    exec($sshCommand, $output, $return_var);
    if ($return_var !== 0) {
        // Non blocchiamo l'eliminazione per un errore firewall, ma lo logghiamo
        error_log("Fallita rimozione regola firewall su VPS per porta {$publicPort}. Output: " . implode("\n", $output));
    }
}

/**
 * Rimuove le regole iptables dal Webserver locale.
 * @param string $containerIp L'IP locale del container (es. 192.168.1.101).
 */
function removeWebserverFirewallRules(string $containerIp) {
    $escapedContainerIp = escapeshellarg($containerIp);
    $minecraftPort = escapeshellarg(MINECRAFT_BEDROCK_PORT);

    // Comando per rimuovere la regola DNAT locale
    $dnatRule = "iptables -t nat -D PREROUTING -i wg0 -p udp --dport {$minecraftPort} -j DNAT --to-destination {$escapedContainerIp}:{$minecraftPort}";
    
    // Comando per rimuovere la regola MASQUERADE locale
    $masqueradeRule = "iptables -t nat -D POSTROUTING -o " . WEBSERVER_LAN_INTERFACE . " -j MASQUERADE";
    
    // Comando per salvare le regole
    $saveRules = "netfilter-persistent save";

    // Eseguiamo i comandi localmente (assumendo che lo script giri sul webserver)
    exec("{$dnatRule} && {$masqueradeRule} && {$saveRules}", $output, $return_var);
    if ($return_var !== 0) {
        error_log("Fallita rimozione regola firewall su Webserver per IP {$containerIp}.");
    }
}


/**
 * Cancella i record A e SRV da Cloudflare per un dato server.
 * @param string $serverName Il nome del server (senza dominio).
 */
function deleteCloudflareDnsRecords(string $serverName) {
    $headers = [
        "Authorization: Bearer " . CLOUDFLARE_API_TOKEN,
        "Content-Type: application/json"
    ];
    $zoneId = CLOUDFLARE_ZONE_ID;
    
    // Trova e cancella tutti i record associati al nome del server (A e SRV)
    $ch = curl_init("https://api.cloudflare.com/client/v4/zones/$zoneId/dns_records?name=" . urlencode($serverName . '.sians.it'));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resp = curl_exec($ch);
    if (curl_errno($ch)) return; // Non bloccare in caso di errore
    $data = json_decode($resp, true);
    curl_close($ch);

    if (!empty($data['result'])) {
        foreach ($data['result'] as $record) {
            $recordId = $record['id'];
            $chDel = curl_init("https://api.cloudflare.com/client/v4/zones/$zoneId/dns_records/$recordId");
            curl_setopt($chDel, CURLOPT_CUSTOMREQUEST, "DELETE");
            curl_setopt($chDel, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($chDel, CURLOPT_RETURNTRANSFER, true);
            curl_exec($chDel);
            curl_close($chDel);
        }
    }
}

// --- SCRIPT PRINCIPALE ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'message' => 'Metodo non consentito']));
}
csrf_check_or_die();

header('Content-Type: application/json');
$idUtente = $_SESSION['user_id'] ?? 0;
$vmid = intval($_POST['vmid'] ?? 0);
if ($vmid <= 0) die(json_encode(['success' => false, 'message' => 'VMID non valido']));

try {
    $pdo->beginTransaction();

    // Recupera i dettagli del server dal DB
    $stmt = $pdo->prepare("
        SELECT s.*, c.ip, c.ram_mb, c.storage_gb, c.id as container_id 
        FROM servers s 
        LEFT JOIN containers c ON c.id = s.container_id 
        WHERE s.user_id = ? AND s.proxmox_containerId = ?
    ");
    $stmt->execute([$idUtente, $vmid]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$server) throw new Exception("Server non trovato o non appartiene all'utente");
    
    $nomeServer = $server['name'];
    $publicPort = $server['public_port'];
    $containerIp = $server['ip'];
    $idContainer = $server['container_id'] ?? null;

    // --- 1. Rimuove le regole firewall ---
    if ($publicPort > 0) {
        removeVpsFirewallRules($publicPort);
    }
    if (!empty($containerIp)) {
        removeWebserverFirewallRules($containerIp);
    }

    // --- 2. Rimuove i record DNS da Cloudflare ---
    if (!empty($nomeServer)) {
        deleteCloudflareDnsRecords($nomeServer);
    }
    
    // --- 3. Ferma e distruggi il container su Proxmox ---
    callProxmox("https://192.168.1.251:8006/api2/json/nodes/pve/lxc/$vmid/status/stop", 'POST');
    sleep(5); // Attendi un po' per assicurarti che il container sia fermo
    callProxmox("https://192.168.1.251:8006/api2/json/nodes/pve/lxc/$vmid", 'DELETE');

    // --- 4. Aggiorna le risorse globali nel DB ---
    $pdo->prepare("UPDATE resources SET used_cpu_cores = used_cpu_cores - ?, used_ram_mb = used_ram_mb - ?, used_storage_gb = used_storage_gb - ? WHERE id = 1 AND used_cpu_cores >= ? AND used_ram_mb >= ? AND used_storage_gb >= ?")
        ->execute([
            intval($server['cpu_cores']),
            intval($server['ram_mb']),
            intval($server['storage_gb']),
            intval($server['cpu_cores']),
            intval($server['ram_mb']),
            intval($server['storage_gb'])
        ]);

    // --- 5. Resetta lo slot del server ---
    if (!empty($server['slot_id'])) {
        $pdo->prepare("UPDATE server_slots SET server_id = NULL, status = 'pending' WHERE id = ?")
            ->execute([$server['slot_id']]);
    }

    // --- 6. Elimina il server e il container dal DB ---
    if ($idContainer) {
        $pdo->prepare("DELETE FROM containers WHERE id = ?")->execute([$idContainer]);
    }
    $pdo->prepare("DELETE FROM servers WHERE id = ?")->execute([$server['id']]);

    $pdo->commit();
    
    echo json_encode(['success' => true, 'message' => 'Server eliminato correttamente']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
