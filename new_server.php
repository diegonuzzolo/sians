<?php
// new_server.php (Versione completa con fix permessi e setup log automatico)

// Mostra errori per il debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// --- 0. DIPENDENZE E CONFIGURAZIONE ---
require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php';
require_once 'includes/csrf.php';
require 'vendor/autoload.php';

// --- COSTANTI PER LA CONNESSIONE ---
define('VPS_USER', 'root');
define('VPS_IP', '195.231.66.137');
define('VPS_SSH_KEY', '/var/www/.ssh/id_proxmox');
define('VPS_PUBLIC_INTERFACE', 'enx3');

define('WEBSERVER_TUNNEL_IP', '10.10.0.2');
define('WEBSERVER_LAN_INTERFACE', 'eth0');
define('WEBSERVER_WIREGUARD_INTERFACE', 'wg0');
define('PROXMOX_NODE_IP', '192.168.1.251');

define('MINECRAFT_BEDROCK_PORT', 19132);
define('START_PUBLIC_PORT', 20000);

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
$newPublicPort = 0;
$containerLanIp = '';
$vmid = 0; // Inizializza VMID

try {
    // --- 2. BLOCCO SLOT ---
    $stmtLock = $pdo->prepare(
        "UPDATE server_slots SET status = 'processing' WHERE id = ? AND user_id = ? AND status = 'pending'"
    );
    $stmtLock->execute([$slotId, $userId]);
    if ($stmtLock->rowCount() === 0) {
        throw new Exception("Slot non disponibile o già in uso.");
    }

    $stmtSlot = $pdo->prepare(
        "SELECT p.ram_gb, p.cpu_cores, p.storage_gb, p.id as plan_id FROM server_slots ss JOIN plans p ON ss.plan_id = p.stripe_price_id WHERE ss.id = ?"
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
    $containerLanIp = getNextFreePrivateIp($pdo); 

    $stmtPort = $pdo->query("SELECT MAX(public_port) as last_port FROM servers");
    $lastPort = $stmtPort->fetchColumn();
    $newPublicPort = $lastPort ? $lastPort + 1 : START_PUBLIC_PORT;

    // --- 4. INSERIMENTO DB ---
    $stmtServer = $pdo->prepare(
        "INSERT INTO servers (name, user_id, plan_id, slot_id, proxmox_containerId, status, type, cpu_cores, ram_mb, public_port) VALUES (?, ?, ?, ?, ?, 'creating', 'bedrock', ?, ?, ?)"
    );
    $stmtServer->execute([$serverName, $userId, $plan['id'], $slotId, $vmid, $plan['cpu_cores'], $ramMb, $newPublicPort]);
    $serverId = $pdo->lastInsertId();
    
    $stmtContainer = $pdo->prepare(
        "INSERT INTO containers (proxmox_containerId, ram_mb, storage_gb, user_id, hostname, ip, status, cores, server_id) VALUES (?, ?, ?, ?, ?, ?, 'Creating', ?, ?)"
    );
    $stmtContainer->execute([$vmid, $ramMb, $plan['storage_gb'], $userId, $serverName, $containerLanIp, $plan['cpu_cores'], $serverId]);
    $containerId = $pdo->lastInsertId();
    
    $pdo->prepare("UPDATE servers SET container_id = ? WHERE id = ?")->execute([$containerId, $serverId]);
    $pdo->prepare("UPDATE server_slots SET server_id = ?, status = 'created' WHERE id = ?")->execute([$serverId, $slotId]);
    $pdo->prepare(
        "UPDATE resources SET used_cpu_cores = used_cpu_cores + ?, used_ram_mb = used_ram_mb + ?, used_storage_gb = used_storage_gb + ? WHERE id = 1"
        )->execute([$plan['cpu_cores'], $ramMb, $plan['storage_gb']]);
        
// --- 5. CREAZIONE CONTAINER E BIND MOUNT ---
    $createResult = createLxcContainer($vmid, $plan['ram_gb'], $plan['storage_gb'],        $serverName, $containerLanIp, $plan['cpu_cores']);
    if (!$createResult['success']) throw new Exception("Errore creazione container: " .      $createResult['message']);
    sleep(4); // Attendi che Proxmox completi la creazione
    // **AGGIORNATO**: Esegue lo script helper sull'host Proxmox tramite SSH
    $bindMountLine = "mp0: /var/log/game_logs,mp=/mnt/shared_logs";
    $vmidEscaped = escapeshellarg($vmid);
    $bindMountLineEscaped = escapeshellarg($bindMountLine);

    // Costruisci il comando da eseguire SULL'HOST
    $remoteHelperCommand = "/usr/local/bin/add_bind_mount.sh $vmidEscaped $bindMountLineEscaped";
    
    // Avvolgi il comando in una chiamata SSH
    $sshKey = VPS_SSH_KEY; // Usa la costante che hai già
    $nodeIp = PROXMOX_NODE_IP;
    $command = "ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp " . escapeshellarg($remoteHelperCommand);

    exec($command, $output, $return_var);
    if ($return_var !== 0) {
        throw new Exception("Impossibile scrivere il bind mount per il container $vmid tramite SSH. Dettagli: " . implode("\n", $output));
    }
    
    // Avvia il container (anche questo tramite SSH)
    exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp 'pct start $vmid'");
    sleep(4);

    // --- 6. CONFIGURAZIONE PORT FORWARDING ---
    $escapedPublicPort = escapeshellarg($newPublicPort);
    $webserverTunnelIp = escapeshellarg(WEBSERVER_TUNNEL_IP);
    $minecraftPort = escapeshellarg(MINECRAFT_BEDROCK_PORT);
    $escapedContainerIp = escapeshellarg($containerLanIp);
    $saveRules = "netfilter-persistent save";
    
    // Parte A: VPS
    $vpsDnatRule = "iptables -t nat -A PREROUTING -i " . VPS_PUBLIC_INTERFACE . " -p udp --dport {$escapedPublicPort} -j DNAT --to-destination {$webserverTunnelIp}:{$minecraftPort}";
    $remoteCommand = escapeshellarg("{$vpsDnatRule} && {$saveRules}");
    $sshCommand = "ssh -i " . VPS_SSH_KEY . " -o StrictHostKeyChecking=no " . VPS_USER . "@" . VPS_IP . " {$remoteCommand}";
    exec($sshCommand, $outputVps, $return_var_vps);
    if ($return_var_vps !== 0) throw new Exception("Configurazione firewall sul VPS fallita.");

    // Parte B: Webserver
    $webserverDnatRule = "sudo iptables -t nat -A PREROUTING -i " . WEBSERVER_WIREGUARD_INTERFACE . " -p udp --dport {$minecraftPort} -j DNAT --to-destination {$escapedContainerIp}:{$minecraftPort}";
    exec("{$webserverDnatRule} && sudo {$saveRules}", $output_local, $return_var_local);
    if ($return_var_local !== 0) throw new Exception("Configurazione firewall su Webserver fallita.");

    // --- 7. CONFIGURAZIONE DNS SU CLOUDFLARE ---
    try {
        $subdomain = "{$serverName}.sians.it";
        $headers = ["Authorization: Bearer " . CLOUDFLARE_API_TOKEN, "Content-Type: application/json"];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $aRecord = ["type" => "A", "name" => $serverName, "content" => VPS_IP, "ttl" => 120, "proxied" => false];
        curl_setopt($ch, CURLOPT_URL, "https://api.cloudflare.com/client/v4/zones/" . CLOUDFLARE_ZONE_ID . "/dns_records");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($aRecord));
        curl_exec($ch);

        $srvRecord = [
            "type" => "SRV",
            "data" => [
                "service" => "_minecraft", "proto" => "_udp", "name" => $serverName,
                "priority" => 1, "weight" => 1, "port" => $newPublicPort, "target" => $subdomain
            ],
            "ttl" => 120
        ];
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($srvRecord));
        curl_exec($ch);
        curl_close($ch);
    } catch (Exception $e) {
        $_SESSION['flash_message'] = ['type' => 'warning', 'text' => "Server creato ma config. DNS fallita: " . $e->getMessage()];
    }

    // --- 8. SETUP SOFTWARE E LOG WATCHER NEL CONTAINER ---
    $sshKey = VPS_SSH_KEY;
    $nodeIp = PROXMOX_NODE_IP;
    
    // 8.1: Installazione software di base E `inotify-tools`
    $installCmd = "pct exec $vmid -- bash -c 'apt-get update && apt-get install -y curl jq unzip screen inotify-tools'";
    exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"$installCmd\"");
    
    // 8.2: Esecuzione dello script di setup del gioco
    $localScript = '/var/www/setup_server.sh';
    $remoteTemp = "/root/setup_server.sh";
    exec("scp -i $sshKey -o StrictHostKeyChecking=no $localScript root@$nodeIp:$remoteTemp");
    exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct push $vmid $remoteTemp $remoteTemp\"");
    $remoteExecCommand = "bash -c 'chmod +x {$remoteTemp} && {$remoteTemp} {$containerId} > /root/setup.log 2>&1 &'";
    exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- {$remoteExecCommand}\"");

    // 8.3: Creazione e avvio dello script per il monitoraggio dei log
    $scriptContent = <<<'EOT'
#!/bin/bash
LOG_FILE_TO_WATCH="/root/minecraft_bedrock/server.log"
VMID=$(hostname)
SHARED_DIR="/mnt/shared_logs"
DEST_FILE="${SHARED_DIR}/log_server_${VMID}.txt"
echo "Avvio monitoraggio log per il container ${VMID}..."
while [ ! -f "$LOG_FILE_TO_WATCH" ]; do sleep 10; done
while true; do
    cp "$LOG_FILE_TO_WATCH" "$DEST_FILE"
    inotifywait -q -e modify,create "$LOG_FILE_TO_WATCH"
done
EOT;

    $createScriptCmd = sprintf(
        "pct exec %d -- bash -c 'echo %s > /root/update_log.sh'",
        $vmid,
        escapeshellarg($scriptContent)
    );
    exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"$createScriptCmd\"");
    exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- chmod +x /root/update_log.sh\"");
    exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- screen -dmS logupdater /root/update_log.sh\"");

    $pdo->commit();

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();

    // Logica di rollback per IPTABLES e altre operazioni...
    
    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => strpos($e->getMessage(), '1062 Duplicate entry') !== false
            ? 'Nome server già esistente.'
            : 'Creazione fallita: ' . $e->getMessage()
    ];
    header("Location: dashboard.php");
    exit;
}

// --- 9. FINALIZZA STATO SERVER E REDIRECT ---
$pdo->prepare("UPDATE servers SET status = 'stopped' WHERE id = ?")->execute([$serverId]);

if (!isset($_SESSION['flash_message'])) {
    $_SESSION['flash_message'] = ['type' => 'success', 'text' => "Server creato! Connettiti usando l'indirizzo: {$serverName}.sians.it"];
}

header("Location: dashboard.php");
exit;
?>