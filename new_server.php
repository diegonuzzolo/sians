<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php';
require_once 'includes/csrf.php';
require_once 'init.php';

csrf_check_or_die();

$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) die(json_encode(['success'=>false,'message'=>'Accesso negato']));

$planCode = $_GET['plan_code'] ?? null;
$serverName = trim($_GET['server_name'] ?? 'mcserver');
if (!$planCode) die(json_encode(['success'=>false,'message'=>'Piano non specificato']));

// Recupera piano
$stmt = $pdo->prepare("SELECT * FROM plans WHERE code=? AND active=1 LIMIT 1");
$stmt->execute([$planCode]);
$plan = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$plan) die(json_encode(['success'=>false,'message'=>'Piano non valido']));

// Controlla subscription attiva
$stmt = $pdo->prepare("SELECT * FROM subscriptions WHERE user_id=? AND plan_id=? LIMIT 1");
$stmt->execute([$userId, $plan['id']]);
$subscription = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$subscription) die(json_encode(['success'=>false,'message'=>'Subscription non trovata']));

// Risorse
$ramGb = $plan['ram_gb'];
$cores = $plan['cpu_cores'];
$storageGb = $plan['storage_gb'];

// Prossimo VMID e IP liberi
$vmid = getNextFreeVmid($pdo);
$vmIp = getNextFreePrivateIp($pdo);

// Inserisci server (temporaneo, status stopped)
$stmt = $pdo->prepare("
    INSERT INTO servers (name, user_id, plan_id, proxmox_containerId, status, type, cpu_cores, ram_mb, progress)
    VALUES (?, ?, ?, ?, 'stopped', 'bedrock', ?, ?, 0)
");
$stmt->execute([$serverName, $userId, $plan['id'], $vmid, $cores, $ramGb*1024]);
$serverId = $pdo->lastInsertId();

// Inserisci container
$stmt = $pdo->prepare("
    INSERT INTO containers (proxmox_containerId, ram_mb, storage_gb, user_id, hostname, ip, status, cores, server_id)
    VALUES (?, ?, ?, ?, ?, ?, 'Stopped', ?, ?)
");
$stmt->execute([$vmid, $ramGb*1024, $storageGb, $userId, $serverName, $vmIp, $cores, $serverId]);
$containerId = $pdo->lastInsertId();

// Aggiorna server con container_id
$stmt = $pdo->prepare("UPDATE servers SET container_id=? WHERE id=?");
$stmt->execute([$containerId, $serverId]);

// Aggiorna risorse
$stmt = $pdo->prepare("UPDATE resources SET used_cpu_cores = used_cpu_cores + ?, used_ram_mb = used_ram_mb + ?, used_storage_gb = used_storage_gb + ? WHERE id=1");
$stmt->execute([$cores, $ramGb*1024, $storageGb]);

// Crea container in Proxmox
$createResult = createLxcContainer($vmid, $ramGb, $storageGb, $serverName, $vmIp, $cores);
if (!$createResult['success']) die(json_encode(['success'=>false,'message'=>$createResult['message']]));
sleep(4);
// Setup server remoto
$sshKey = "/var/www/.ssh/id_proxmox";
$nodeIp = "192.168.1.251";
$localScript = '/var/www/setup_server.sh';
$remoteTemp = "/root/setup_server.sh";

exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- apt-get update && pct exec $vmid -- apt-get install -y curl jq unzip screen\"");
exec("scp -i $sshKey -o StrictHostKeyChecking=no $localScript root@$nodeIp:$remoteTemp");
exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct push $vmid $remoteTemp /root/setup_server.sh\"");
exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- chmod +x /root/setup_server.sh && pct exec $vmid -- /root/setup_server.sh $containerId > /dev/null 2>&1 &\"");

// Risposta JSON per frontend
echo json_encode([
    'success' => true,
    'server_id' => $serverId,
    'container_id' => $containerId,
    'vmid' => $vmid,
    'ip' => $vmIp,
    'message' => 'Server creato correttamente'
]);
exit;
