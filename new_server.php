<?php
// Mostra errori per il debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// --- 0. DIPENDENZE E CONFIGURAZIONE ---
require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php';
require_once 'includes/csrf.php';
require 'vendor/autoload.php'; // Symfony YAML

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

// --- NUOVE COSTANTI PER IL VPS ---
define('VPS_USER', 'root'); // Utente SSH sul VPS
define('VPS_IP', '195.231.66.137'); // IP Pubblico del VPS
define('VPS_SSH_KEY', '/var/www/.ssh/id_proxmox'); // Chiave SSH per il VPS (consigliata una chiave dedicata)
define('PUBLIC_INTERFACE', 'eth0'); // Interfaccia pubblica del VPS
define('WIREGUARD_INTERFACE', 'wg0'); // Interfaccia WireGuard del VPS
define('MINECRAFT_BEDROCK_PORT', 19132);
define('START_PUBLIC_PORT', 20000); // Porta pubblica da cui iniziare ad assegnare

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
$slotId = $_POST['slot_id'] ?? null;
$planCode = $_POST['plan_code'] ?? null;
$serverName = trim($_POST['server_name'] ?? 'mcserver');

if (!preg_match('/^[a-zA-Z0-9-]{3,20}$/', $serverName)) {
    header("Location: dashboard.php?error=" . urlencode("Nome server non valido."));
    exit;
}
if (!$slotId || !$planCode) {
    header("Location: dashboard.php?error=" . urlencode("Parametri mancanti."));
    exit;
}

// --- INIZIO TRANSAZIONE ---
$pdo->beginTransaction();
$newPublicPort = 0; // Inizializza la variabile
$vmIp = ''; // Inizializza per averla nello scope del catch

try {
    // --- 2. BLOCCO SLOT ---
    $stmtLock = $pdo->prepare(
        "UPDATE server_slots SET status = 'processing' 
         WHERE id = ? AND user_id = ? AND status = 'pending'"
    );
    $stmtLock->execute([$slotId, $userId]);
    if ($stmtLock->rowCount() === 0) {
        throw new Exception("Slot non disponibile o già in uso.");
    }

    $stmtSlot = $pdo->prepare(
        "SELECT ss.*, p.stripe_price_id, p.ram_gb, p.cpu_cores, p.storage_gb, p.id as plan_id 
         FROM server_slots ss 
         JOIN plans p ON ss.plan_id = p.stripe_price_id 
         WHERE ss.id = ?"
    );
    $stmtSlot->execute([$slotId]);
    $slot = $stmtSlot->fetch(PDO::FETCH_ASSOC);
    if (!$slot) throw new Exception("Errore critico: slot non trovato.");

    $plan = [
        'id' => $slot['plan_id'],
        'ram_gb' => $slot['ram_gb'],
        'cpu_cores' => $slot['cpu_cores'],
        'storage_gb' => $slot['storage_gb']
    ];
    $ramMb = $plan['ram_gb'] * 1024;

    // --- 3. ALLOCAZIONE RISORSE ---
    $vmid = getNextFreeVmid($pdo);
    $vmIp = getNextFreePrivateIp($pdo); // Questo sarà l'IP del container nel tunnel WireGuard

    // --- NUOVO: ALLOCAZIONE PORTA PUBBLICA ---
    $stmtPort = $pdo->query("SELECT MAX(public_port) as last_port FROM servers");
    $lastPort = $stmtPort->fetchColumn();
    $newPublicPort = $lastPort ? $lastPort + 1 : START_PUBLIC_PORT;


    // --- 4. INSERIMENTO DB ---
    $stmtServer = $pdo->prepare(
        "INSERT INTO servers (name, user_id, plan_id, slot_id, proxmox_containerId, status, type, cpu_cores, ram_mb, public_port) 
         VALUES (?, ?, ?, ?, ?, 'creating', 'bedrock', ?, ?, ?)"
    );
    $stmtServer->execute([$serverName, $userId, $plan['id'], $slotId, $vmid, $plan['cpu_cores'], $ramMb, $newPublicPort]);
    $serverId = $pdo->lastInsertId();

    $stmtContainer = $pdo->prepare(
        "INSERT INTO containers (proxmox_containerId, ram_mb, storage_gb, user_id, hostname, ip, status, cores, server_id) 
         VALUES (?, ?, ?, ?, ?, ?, 'Creating', ?, ?)"
    );
    $stmtContainer->execute([$vmid, $ramMb, $plan['storage_gb'], $userId, $serverName, $vmIp, $plan['cpu_cores'], $serverId]);
    $containerId = $pdo->lastInsertId();

    $pdo->prepare("UPDATE servers SET container_id = ? WHERE id = ?")->execute([$containerId, $serverId]);
    $pdo->prepare("UPDATE server_slots SET server_id = ?, status = 'created' WHERE id = ?")->execute([$serverId, $slotId]);
    $pdo->prepare(
        "UPDATE resources SET used_cpu_cores = used_cpu_cores + ?, used_ram_mb = used_ram_mb + ?, used_storage_gb = used_storage_gb + ? WHERE id = 1"
    )->execute([$plan['cpu_cores'], $ramMb, $plan['storage_gb']]);


    // --- 5. CREAZIONE CONTAINER SU PROXMOX ---
    $createResult = createLxcContainer($vmid, $plan['ram_gb'], $plan['storage_gb'], $serverName, $vmIp, $plan['cpu_cores']);
    if (!$createResult['success']) throw new Exception("Errore creazione container: " . $createResult['message']);
    
    // Attendi qualche secondo per assicurarti che il container sia completamente avviato prima di procedere
    sleep(5); 
    
    // --- 6. CONFIGURAZIONE PORT FORWARDING SU VPS ---
    $escapedVmIp = escapeshellarg($vmIp);
    $escapedNewPublicPort = escapeshellarg($newPublicPort);
    $minecraftPort = escapeshellarg(MINECRAFT_BEDROCK_PORT);

    // Regola DNAT: Inoltra la porta pubblica alla porta 19132 del container
    $dnatRule = "iptables -t nat -A PREROUTING -i " . PUBLIC_INTERFACE . " -p udp --dport {$escapedNewPublicPort} -j DNAT --to-destination {$escapedVmIp}:{$minecraftPort}";
    
    // Regola FORWARD: Permetti il passaggio dei pacchetti
    $forwardRule = "iptables -A FORWARD -i " . PUBLIC_INTERFACE . " -o " . WIREGUARD_INTERFACE . " -d {$escapedVmIp} -p udp --dport {$minecraftPort} -m state --state NEW,ESTABLISHED,RELATED -j ACCEPT";
    
    // Comando per salvare le regole
    $saveRules = "netfilter-persistent save";

    $remoteCommand = escapeshellarg("{$dnatRule} && {$forwardRule} && {$saveRules}");
    $sshCommand = "ssh -i " . VPS_SSH_KEY . " -o StrictHostKeyChecking=no " . VPS_USER . "@" . VPS_IP . " {$remoteCommand}";

    exec($sshCommand, $output, $return_var);

    if ($return_var !== 0) {
        throw new Exception("Configurazione del firewall sul VPS fallita. Output: " . implode("\n", $output));
    }
    
    // Commit della transazione solo se tutto è andato a buon fine
    $pdo->commit();

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();

    // --- Rollback delle regole IPTABLES in caso di fallimento ---
    if ($newPublicPort > 0 && !empty($vmIp)) {
        // Tenta di rimuovere le regole iptables se sono state aggiunte prima del fallimento
        $escapedVmIp = escapeshellarg($vmIp);
        $escapedNewPublicPort = escapeshellarg($newPublicPort);
        $minecraftPort = escapeshellarg(MINECRAFT_BEDROCK_PORT);
        $dnatRule = "iptables -t nat -D PREROUTING -i " . PUBLIC_INTERFACE . " -p udp --dport {$escapedNewPublicPort} -j DNAT --to-destination {$escapedVmIp}:{$minecraftPort}";
        $forwardRule = "iptables -D FORWARD -i " . PUBLIC_INTERFACE . " -o " . WIREGUARD_INTERFACE . " -d {$escapedVmIp} -p udp --dport {$minecraftPort} -m state --state NEW,ESTABLISHED,RELATED -j ACCEPT";
        $saveRules = "netfilter-persistent save";
        $remoteCommand = escapeshellarg("{$dnatRule} && {$forwardRule} && {$saveRules}");
        $sshCommand = "ssh -i " . VPS_SSH_KEY . " -o StrictHostKeyChecking=no " . VPS_USER . "@" . VPS_IP . " {$remoteCommand}";
        exec($sshCommand); // Esegui senza controllare l'output, è un tentativo di pulizia
    }

    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => strpos($e->getMessage(), '1062 Duplicate entry') !== false
            ? 'Nome server già esistente.'
            : 'Creazione fallita: ' . $e->getMessage()
    ];
    header("Location: dashboard.php");
    exit;
}

// --- 7. CONFIGURAZIONE DNS SU CLOUDFLARE ---
try {
    $subdomain = "{$serverName}.sians.it";
    $headers = ["Authorization: Bearer " . CLOUDFLARE_API_TOKEN, "Content-Type: application/json"];

    // A Record punta al VPS pubblico
    $aRecord = ["type" => "A", "name" => $serverName, "content" => VPS_IP, "ttl" => 120, "proxied" => false];
    $ch = curl_init("https://api.cloudflare.com/client/v4/zones/" . CLOUDFLARE_ZONE_ID . "/dns_records");
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($aRecord));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);

    // SRV Record per Minecraft Bedrock che punta alla porta pubblica
    $srvRecord = [
        "type" => "SRV",
        "data" => [
            "service" => "_minecraft",
            "proto" => "_udp",
            "name" => $serverName,
            "priority" => 1,
            "weight" => 1,
            "port" => $newPublicPort, // Usa la porta pubblica assegnata
            "target" => $subdomain
        ],
        "ttl" => 120
    ];
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($srvRecord));
    curl_exec($ch);
    curl_close($ch);

} catch (Exception $e) {
    // Non bloccare il processo se il DNS fallisce, ma avvisa l'utente
    $_SESSION['flash_message'] = [
        'type' => 'warning',
        'text' => "Server creato ma configurazione DNS fallita: " . $e->getMessage()
    ];
}


// --- 8. SETUP SOFTWARE REMOTO NEL CONTAINER ---
try {
    $sshKey = "/var/www/.ssh/id_proxmox";
    $nodeIp = "192.168.1.251"; // Nodo Proxmox locale
    $localScript = '/var/www/setup_server.sh';
    $remoteTemp = "/root/setup_server.sh";

    // Aggiorna pacchetti e installa software nel container
    exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- apt-get update && pct exec $vmid -- apt-get install -y curl jq unzip screen\"");

    // Copia script setup
    exec("scp -i $sshKey -o StrictHostKeyChecking=no $localScript root@$nodeIp:$remoteTemp");
    exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct push $vmid $remoteTemp $remoteTemp\"");

    // Esegui script in background
    $remoteExecCommand = "bash -c 'chmod +x {$remoteTemp} && {$remoteTemp} {$containerId} > /root/setup.log 2>&1 &'";
    exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- {$remoteExecCommand}\"");

} catch (Exception $e) {
     $_SESSION['flash_message'] = [
        'type' => 'warning',
        'text' => "Server creato ma il setup del software remoto potrebbe essere fallito: " . $e->getMessage()
    ];
}

// --- 9. FINALIZZA STATO SERVER ---
$pdo->prepare("UPDATE servers SET status = 'stopped' WHERE id = ?")->execute([$serverId]);

// Se non c'è già un messaggio di warning, imposta quello di successo
if (!isset($_SESSION['flash_message'])) {
    $_SESSION['flash_message'] = [
        'type' => 'success',
        'text' => "Server creato! Connettiti usando l'indirizzo: {$serverName}.sians.it"
    ];
}

header("Location: dashboard.php");
exit;
?>
