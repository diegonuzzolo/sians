<?php
include 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php';

header('Content-Type: application/json');

$userId = $_SESSION['user_id'] ?? 0;
$planCode = $_GET['plan_code'] ?? null;
$serverName = $_GET['server_name'] ?? "Nuovo Server";

if (!$planCode) {
    echo json_encode(['success' => false, 'step' => 'check_plan', 'message' => 'Piano non specificato']);
    exit;
}

// Recupera piano e subscription attiva
$stmt = $pdo->prepare("
    SELECT p.*, sub.id AS subscription_id 
    FROM plans p 
    JOIN subscriptions sub ON sub.plan_id = p.id 
    WHERE sub.user_id = ? AND p.code = ? AND sub.status='active'
");
$stmt->execute([$userId, $planCode]);
$plan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$plan) {
    echo json_encode(['success' => false, 'step' => 'check_subscription', 'message' => 'Nessuna subscription attiva per questo piano']);
    exit;
}

// Controllo slot
$stmt = $pdo->prepare("SELECT COUNT(*) FROM servers WHERE user_id = ? AND plan_id = ?");
$stmt->execute([$userId, $plan['id']]);
$usedSlots = (int)$stmt->fetchColumn();
$maxSlots = 1;
if ($usedSlots >= $maxSlots) {
    echo json_encode(['success' => false, 'step' => 'check_slots', 'message' => 'Slot esauriti']);
    exit;
}

// Genera VMID
$stmt = $pdo->query("SELECT MAX(vmid) FROM containers");
$maxVmid = $stmt->fetchColumn();
$vmid = $maxVmid ? $maxVmid + 1 : 100;

// Genera IP
$baseIp = "192.168.1.";
$stmt = $pdo->query("SELECT ip FROM containers");
$usedIps = $stmt->fetchAll(PDO::FETCH_COLUMN);
$ipSuffix = 100;
while (in_array($baseIp . $ipSuffix, $usedIps)) $ipSuffix++;
$ip = $baseIp . $ipSuffix;

// Parametri container
$cores = $plan['cpu_cores'];
$ramGb = $plan['ram_gb'];
$storageGb = $plan['storage_gb'] ?? 64;
$hostname = "mcserver-$vmid";

// Crea container
$res = createLxcContainer($vmid, $ramGb, $storageGb, $hostname, $ip, $cores);
if (!$res['success']) {
    echo json_encode(['success' => false, 'step' => 'create_container', 'message' => $res['message'] ?? 'Errore sconosciuto']);
    exit;
}

// Inserisci container nel DB
try {
    $stmt = $pdo->prepare("INSERT INTO containers (vmid, ip, ram_mb, cores, storage_gb) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$vmid, $ip, $ramGb * 1024, $cores, $storageGb]);
    $containerId = $pdo->lastInsertId();
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'step' => 'insert_container_db', 'message' => $e->getMessage()]);
    exit;
}

// Inserisci server nel DB
try {
    $stmt = $pdo->prepare("
        INSERT INTO servers (name, user_id, plan_id, container_id, status, type, cpu_cores, ram_mb, progress)
        VALUES (?, ?, ?, ?, 'stopped', 'bedrock', ?, ?, 0)
    ");
    $stmt->execute([$serverName, $userId, $plan['id'], $containerId, $cores, $ramGb * 1024]);
    $serverId = $pdo->lastInsertId();
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'step' => 'insert_server_db', 'message' => $e->getMessage()]);
    exit;
}

// SSH e setup_server.sh
$sshKey = "/var/www/.ssh/id_proxmox";
$nodeIp = "192.168.1.251";
$localScript = "/var/www/setup_server.sh";
$remoteTemp = "/tmp/setup_server.sh";

// Esegui comandi
$cmds = [
    "ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- apt-get update && pct exec $vmid -- apt-get install -y curl jq unzip screen\"",
    "scp -i $sshKey -o StrictHostKeyChecking=no $localScript root@$nodeIp:$remoteTemp",
    "ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct push $vmid $remoteTemp /root/setup_server.sh\"",
    "ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- chmod +x /root/setup_server.sh && pct exec $vmid -- /root/setup_server.sh $containerId > /dev/null 2>&1 &\""
];

$cmdOutput = [];
foreach ($cmds as $idx => $cmd) {
    exec($cmd, $output, $ret);
    $cmdOutput[] = [
        'command' => $cmd,
        'return' => $ret,
        'output' => $output
    ];
    if ($ret !== 0) {
        echo json_encode(['success' => false, 'step' => 'ssh_scp', 'message' => 'Errore durante l\'esecuzione di un comando SSH/SCP', 'cmd_index' => $idx, 'cmd_output' => $output]);
        exit;
    }
}

// Tutto ok
echo json_encode([
    'success' => true,
    'message' => 'Server creato con successo',
    'server_id' => $serverId,
    'vmid' => $vmid,
    'ip' => $ip,
    'container_id' => $containerId,
    'cmd_output' => $cmdOutput
]);
