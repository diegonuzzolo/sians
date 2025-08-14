<?php
include("auth_check.php");
require 'config/config.php';
require_once 'proxmox_functions.php';

$userId = $_SESSION['user_id'] ?? 0;
$serverId = intval($_GET['server_id'] ?? 0);

if($serverId <= 0){
    echo json_encode(['status'=>'unknown','progress'=>0,'error'=>'Server ID non valido']);
    exit;
}

// Recupera server e container
$stmt = $pdo->prepare("
    SELECT s.status, s.progress, c.proxmox_containerId
    FROM servers s
    LEFT JOIN containers c ON c.id = s.container_id
    WHERE s.id=? AND s.user_id=?
");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$server){
    echo json_encode(['status'=>'notfound','progress'=>0,'error'=>'Server non trovato']);
    exit;
}

$vmid = $server['proxmox_containerId'];
$nodeIp = "192.168.1.251";
$sshKey = "/var/www/.ssh/id_proxmox";

// Controlla stato container su Proxmox
$cmd = "ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp pct status $vmid";
exec($cmd,$output,$status);
$proxmoxStatus = $status===0 ? trim(implode("\n",$output)) : 'unknown';

echo json_encode([
    'status'=>$proxmoxStatus,
    'progress'=>intval($server['progress']),
    'error'=>$status!==0 ? implode("\n",$output) : null
]);
