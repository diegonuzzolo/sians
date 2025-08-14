<?php
include("auth_check.php");
require 'config/config.php';

$userId = $_SESSION['user_id'] ?? 0;
$serverId = intval($_POST['server_id'] ?? 0);

if($serverId <=0 || !isset($_FILES['icon'])) {
    echo json_encode(['success'=>false,'message'=>'Parametri mancanti']);
    exit;
}

$file = $_FILES['icon'];
if($file['error'] !== 0) {
    echo json_encode(['success'=>false,'message'=>'Errore upload file']);
    exit;
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if($ext !== 'png') {
    echo json_encode(['success'=>false,'message'=>'Solo PNG consentiti']);
    exit;
}

// Recupera container
$stmt = $pdo->prepare("
    SELECT c.proxmox_containerId
    FROM servers s
    LEFT JOIN containers c ON c.id = s.container_id
    WHERE s.id=? AND s.user_id=?
");
$stmt->execute([$serverId, $userId]);
$container = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$container) {
    echo json_encode(['success'=>false,'message'=>'Server non trovato']);
    exit;
}

$vmid = $container['proxmox_containerId'];
$sshKey = "/var/www/.ssh/id_proxmox";
$nodeIp = "192.168.1.251";
$tmpFile = $file['tmp_name'];

// Copia icona nel container
$cmd = "scp -i $sshKey -o StrictHostKeyChecking=no $tmpFile root@$nodeIp:/root/icon.png && "
     . "ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp "
     . "\"pct push $vmid /root/icon.png /root/icon.png\"";

exec($cmd, $output, $status);
if($status !== 0) {
    echo json_encode(['success'=>false,'message'=>'Errore caricamento icona']);
    exit;
}

echo json_encode(['success'=>true]);
