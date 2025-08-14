<?php
include("auth_check.php");
require 'config/config.php';
require_once 'proxmox_functions.php';

$userId = $_SESSION['user_id'] ?? 0;
$data = json_decode(file_get_contents('php://input'), true);

$serverId = intval($data['server_id'] ?? 0);
$action = $data['action'] ?? '';

if($serverId <= 0 || !in_array($action, ['start','stop'])) {
    echo json_encode(['success'=>false,'message'=>'Parametri non validi']);
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
$cmdAction = $action === 'start' ? 'start' : 'stop';

$cmd = "ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp pct $cmdAction $vmid";
exec($cmd, $output, $status);
if($status !== 0) {
    echo json_encode(['success'=>false,'message'=>'Errore esecuzione azione: '.implode("\n",$output)]);
    exit;
}

echo json_encode(['success'=>true]);
