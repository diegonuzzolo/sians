<?php
include 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php';

$userId = $_SESSION['user_id'] ?? 0;
$planCode = $_GET['plan_code'] ?? null;
$serverName = $_GET['server_name'] ?? "Nuovo Server";

if (!$planCode) {
    die(json_encode(['success' => false, 'message' => 'Piano non specificato']));
}

// Recupera il piano e la subscription attiva
$stmt = $pdo->prepare("
    SELECT p.*, sub.id AS subscription_id 
    FROM plans p 
    JOIN subscriptions sub ON sub.plan_id = p.id 
    WHERE sub.user_id = ? AND p.code = ? AND sub.status='active'
");
$stmt->execute([$userId, $planCode]);
$plan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$plan) {
    die(json_encode(['success' => false, 'message' => 'Nessuna subscription attiva per questo piano']));
}

// Calcola slot disponibili
$stmt = $pdo->prepare("SELECT COUNT(*) FROM servers WHERE user_id = ? AND plan_id = ?");
$stmt->execute([$userId, $plan['id']]);
$usedSlots = $stmt->fetchColumn();
$maxSlots = 1;
if ($usedSlots >= $maxSlots) {
    die(json_encode(['success' => false, 'message' => 'Slot esauriti']));
}

// Genera il prossimo VMID libero partendo da 101
$startVmid = 101;
$stmt = $pdo->query("SELECT proxmox_containerId FROM containers");
$usedVmids = $stmt->fetchAll(PDO::FETCH_COLUMN);
$vmid = $startVmid;
while (in_array($vmid, $usedVmids)) {
    $vmid++;
}

// Genera IP privato libero
$baseIp = "192.168.1."; // subnet privata
$stmt = $pdo->query("SELECT ip FROM containers");
$usedIps = $stmt->fetchAll(PDO::FETCH_COLUMN);
$ipSuffix = 100;
while (in_array($baseIp . $ipSuffix, $usedIps)) {
    $ipSuffix++;
}
$ip = $baseIp . $ipSuffix;

// Parametri server
$cores = $plan['cpu_cores'];
$ramGb = $plan['ram_gb'];
$storageGb = $plan['storage_gb'] ?? 64;

// Crea container
$hostname = "mcserver-$vmid";
$res = createLxcContainer($vmid, $ramGb, $storageGb, $hostname, $ip, $cores);

if (!$res['success']) {
    die(json_encode(['success' => false, 'message' => 'Errore creazione container: '.$res['message']]));
}

sleep(4);
// Inserisci container nel DB
$stmt = $pdo->prepare("
    INSERT INTO containers (proxmox_containerId, ip, ram_mb, cores, storage_gb, hostname, user_id, server_id, status) 
    VALUES (?, ?, ?, ?, ?, ?, ?, 0, 'stopped')
");
$stmt->execute([$vmid, $ip, $ramGb * 1024, $cores, $storageGb, $hostname, $userId]);
$containerId = $pdo->lastInsertId();

// Inserisci server nel DB
$stmt = $pdo->prepare("
    INSERT INTO servers (name, user_id, plan_id, container_id, status, type, cpu_cores, ram_mb, progress) 
    VALUES (?, ?, ?, ?, 'stopped', 'bedrock', ?, ?, 0)
");
$stmt->execute([$serverName, $userId, $plan['id'], $containerId, $cores, $ramGb * 1024]);
$serverId = $pdo->lastInsertId();

// Percorsi SSH/SCP
$sshKey = "/var/www/.ssh/id_proxmox";
$nodeIp = "192.168.1.251";
$localScript = "/var/www/scripts/setup_server.sh";
$remoteTemp = "/tmp/setup_server.sh";

// Comandi SSH/SCP
exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- apt-get update && pct exec $vmid -- apt-get install -y curl jq unzip screen\"");
exec("scp -i $sshKey -o StrictHostKeyChecking=no $localScript root@$nodeIp:$remoteTemp");
exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct push $vmid $remoteTemp /root/setup_server.sh\"");
exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- chmod +x /root/setup_server.sh && pct exec $vmid -- /root/setup_server.sh $containerId > /dev/null 2>&1 &\"");

echo json_encode([
    'success' => true,
    'message' => 'Server creato con successo',
    'server_id' => $serverId,
    'vmid' => $vmid,
    'ip' => $ip
]);
