<?php
// new_server.php - versione aggiornata e completa con log e debug

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
define('WEBSERVER_LAN_INTERFACE', 'eth0');
define('WEBSERVER_WIREGUARD_INTERFACE', 'wg0');
define('PROXMOX_NODE_IP', '192.168.1.251');
define('MINECRAFT_BEDROCK_PORT', 19132);
define('START_PUBLIC_PORT', 20000);

// ======================
// FUNZIONE SSH CON STDIN
// ======================
function executeSshWithStdin(string $ssh_command, string $data_to_pipe): array
{
    $descriptorspec = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];

    $process = proc_open($ssh_command, $descriptorspec, $pipes);
    if (!is_resource($process)) return ['success' => false, 'stdout' => '', 'stderr' => 'Impossibile avviare il processo.'];

    fwrite($pipes[0], $data_to_pipe);
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $return_value = proc_close($process);

    return ['success' => ($return_value === 0), 'stdout' => $stdout, 'stderr' => $stderr];
}

// ======================
// CONTROLLO METODO POST
// ======================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php?error=" . urlencode("Metodo non valido."));
    exit;
}
csrf_check_or_die();

// ======================
// CONTROLLO LOGIN
// ======================
$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) {
    header("Location: login.php?error=" . urlencode("Accesso negato."));
    exit;
}

// ======================
// VALIDAZIONE INPUT
// ======================
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

// ======================
// INIZIO TRANSAZIONE
// ======================
$pdo->beginTransaction();
$newPublicPort = 0;
$containerLanIp = '';
$vmid = 0;

try {
    // --- 1. BLOCCO SLOT ---
    $stmtLock = $pdo->prepare("UPDATE server_slots SET status = 'processing' WHERE id = ? AND user_id = ? AND status = 'pending'");
    $stmtLock->execute([$slotId, $userId]);
    if ($stmtLock->rowCount() === 0) throw new Exception("Slot non disponibile o già in uso.");

    $stmtSlot = $pdo->prepare("SELECT p.ram_gb, p.cpu_cores, p.storage_gb, p.id as plan_id 
                               FROM server_slots ss 
                               JOIN plans p ON ss.plan_id = p.stripe_price_id 
                               WHERE ss.id = ?");
    $stmtSlot->execute([$slotId]);
    $slot = $stmtSlot->fetch(PDO::FETCH_ASSOC);
    if (!$slot) throw new Exception("Errore critico: slot non trovato.");

    $plan = ['id' => $slot['plan_id'], 'ram_gb' => $slot['ram_gb'], 'cpu_cores' => $slot['cpu_cores'], 'storage_gb' => $slot['storage_gb']];
    $ramMb = $plan['ram_gb'] * 1024;

    // --- 2. ALLOCAZIONE RISORSE ---
    $vmid = getNextFreeVmid($pdo);
    $containerLanIp = getNextFreePrivateIp($pdo);
    $stmtPort = $pdo->query("SELECT MAX(public_port) as last_port FROM servers");
    $lastPort = $stmtPort->fetchColumn();
    $newPublicPort = $lastPort ? $lastPort + 1 : START_PUBLIC_PORT;

    // --- 3. INSERT SERVER
    $stmtServer = $pdo->prepare("INSERT INTO servers 
        (name, user_id, plan_id, slot_id, proxmox_containerId, status, type, cpu_cores, ram_mb, public_port)
        VALUES (?, ?, ?, ?, ?, 'creating', 'bedrock', ?, ?, ?)");
    $stmtServer->execute([$serverName, $userId, $plan['id'], $slotId, $vmid, $plan['cpu_cores'], $ramMb, $newPublicPort]);
    $serverId = $pdo->lastInsertId();

    // --- 4. INSERT CONTAINER
    $stmtContainer = $pdo->prepare("INSERT INTO containers 
        (proxmox_containerId, ram_mb, storage_gb, user_id, hostname, ip, status, cores, server_id)
        VALUES (?, ?, ?, ?, ?, ?, 'Creating', ?, ?)");
    $stmtContainer->execute([$vmid, $ramMb, $plan['storage_gb'], $userId, $serverName, $containerLanIp, $plan['cpu_cores'], $serverId]);
    $containerId = $pdo->lastInsertId();

    // --- 5. UPDATE SERVER e SLOT
    $pdo->prepare("UPDATE servers SET container_id = ? WHERE id = ?")->execute([$containerId, $serverId]);
    $pdo->prepare("UPDATE server_slots SET server_id = ?, status = 'created' WHERE id = ?")->execute([$serverId, $slotId]);
    $pdo->prepare("UPDATE resources SET used_cpu_cores = used_cpu_cores + ?, used_ram_mb = used_ram_mb + ?, used_storage_gb = used_storage_gb + ? WHERE id = 1")
        ->execute([$plan['cpu_cores'], $ramMb, $plan['storage_gb']]);

    // --- 6. CREAZIONE CONTAINER PROXMOX ---
    $createResult = createLxcContainer($vmid, $plan['ram_gb'], $plan['storage_gb'], $serverName, $containerLanIp, $plan['cpu_cores']);
    if (!$createResult['success']) throw new Exception("Errore creazione container: " . $createResult['message']);
    sleep(3);

    // --- 7. CONFIGURAZIONE BIND MOUNT ---
    $bindMountLine = "mp0: /var/log/game_logs,mp=/mnt/shared_logs";
    $vmidEscaped = escapeshellarg($vmid);
    $bindMountLineEscaped = escapeshellarg($bindMountLine);
    $helperCmd = "/usr/local/bin/add_bind_mount.sh $vmidEscaped $bindMountLineEscaped";
    $sshCmd = "ssh -i " . VPS_SSH_KEY . " -o StrictHostKeyChecking=no root@" . PROXMOX_NODE_IP . " " . escapeshellarg($helperCmd);
    exec($sshCmd, $output, $return_var);
    if ($return_var !== 0) throw new Exception("Errore bind mount: " . implode("\n", $output));
    exec("ssh -i " . VPS_SSH_KEY . " -o StrictHostKeyChecking=no root@" . PROXMOX_NODE_IP . " 'pct start $vmid'");
    sleep(3);

    // --- 8. CONFIGURAZIONE PORT FORWARDING ---
    $escapedPublicPort = escapeshellarg($newPublicPort);
    $webserverTunnelIp = escapeshellarg(WEBSERVER_TUNNEL_IP);
    $minecraftPort = escapeshellarg(MINECRAFT_BEDROCK_PORT);
    $escapedContainerIp = escapeshellarg($containerLanIp);
    $saveRules = "netfilter-persistent save";

    $vpsDnatRule = "iptables -t nat -A PREROUTING -i " . VPS_PUBLIC_INTERFACE . " -p udp --dport {$escapedPublicPort} -j DNAT --to-destination {$webserverTunnelIp}:{$minecraftPort}";
    $remoteCommand = escapeshellarg("{$vpsDnatRule} && {$saveRules}");
    $sshCommand = "ssh -i " . VPS_SSH_KEY . " -o StrictHostKeyChecking=no " . VPS_USER . "@" . VPS_IP . " {$remoteCommand}";
    exec($sshCommand, $outputVps, $return_var_vps);
    if ($return_var_vps !== 0) throw new Exception("Firewall VPS fallito.");

    $webserverDnatRule = "sudo iptables -t nat -A PREROUTING -i " . WEBSERVER_WIREGUARD_INTERFACE . " -p udp --dport {$minecraftPort} -j DNAT --to-destination {$escapedContainerIp}:{$minecraftPort}";
    exec("{$webserverDnatRule} && sudo {$saveRules}", $output_local, $return_var_local);
    if ($return_var_local !== 0) throw new Exception("Firewall Webserver fallito.");

    // --- 9. CONFIGURAZIONE DNS CLOUDFLARE ---
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
                "service" => "_minecraft",
                "proto" => "_udp",
                "name" => $serverName,
                "priority" => 1,
                "weight" => 1,
                "port" => $newPublicPort,
                "target" => $subdomain
            ],
            "ttl" => 120
        ];
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($srvRecord));
        curl_exec($ch);
        curl_close($ch);
    } catch (Exception $e) {
        $_SESSION['flash_message'] = ['type' => 'warning', 'text' => "Server creato ma DNS fallito: " . $e->getMessage()];
    }

    // --- 10. SETUP SOFTWARE NEL CONTAINER ---
    $sshKey = VPS_SSH_KEY;
    $nodeIp = PROXMOX_NODE_IP;

    $installCmd = "pct exec $vmid -- bash -c 'apt-get update && apt-get install -y curl jq unzip screen inotify-tools'";
    exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"$installCmd\"");

    $localScript = '/var/www/setup_server.sh';
    $remoteTemp = "/root/setup_server.sh";
    exec("scp -i $sshKey -o StrictHostKeyChecking=no $localScript root@$nodeIp:$remoteTemp");
    exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct push $vmid $remoteTemp $remoteTemp\"");

    $remoteExecCommand = "bash -c 'chmod +x {$remoteTemp} && {$remoteTemp} {$containerId} > /root/setup.log 2>&1 &'";
    exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- {$remoteExecCommand}\"");

    // --- 11. FINALIZZA ---
    $pdo->prepare("UPDATE servers SET status = 'stopped' WHERE id = ?")->execute([$serverId]);

    if (!isset($_SESSION['flash_message'])) {
        $_SESSION['flash_message'] = ['type' => 'success', 'text' => "Server creato! Connettiti usando: {$serverName}.sians.it"];
    }

    $pdo->commit();
    header("Location: dashboard.php");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => strpos($e->getMessage(), '1062 Duplicate entry') !== false
            ? 'Nome server già esistente.'
            : 'Creazione fallita: ' . $e->getMessage()
    ];
    header("Location: dashboard.php");
    exit;
}
?>
