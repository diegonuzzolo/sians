<?php
include("auth_check.php");
require 'config/config.php';
require_once 'proxmox_functions.php';

$userId = $_SESSION['user_id'] ?? 0;
$data = json_decode(file_get_contents('php://input'), true);

$serverId = intval($data['server_id'] ?? 0);
$field = $data['field'] ?? '';
$value = $data['value'] ?? '';

if ($serverId <= 0 || empty($field)) {
    echo json_encode(['success'=>false, 'message'=>'Parametri mancanti']);
    exit;
}

// Recupera server e container
$stmt = $pdo->prepare("
    SELECT s.*, c.proxmox_containerId
    FROM servers s
    LEFT JOIN containers c ON c.id = s.container_id
    WHERE s.id = ? AND s.user_id = ?
");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$server) {
    echo json_encode(['success'=>false, 'message'=>'Server non trovato']);
    exit;
}

// Campi consentiti
$allowedFields = [
    'server-name'=>'name',
    'motd'=>'motd',
    'max-players'=>'max_players',
    'view-distance'=>'view_distance',
    'server-port'=>'server_port',
    'ram_mb'=>'ram_mb',
    'cores'=>'cores'
];

if (!isset($allowedFields[$field])) {
    echo json_encode(['success'=>false,'message'=>'Campo non modificabile']);
    exit;
}

// Aggiorna DB
$column = $allowedFields[$field];
$stmt = $pdo->prepare("UPDATE servers SET $column=? WHERE id=? AND user_id=?");
$stmt->execute([$value, $serverId, $userId]);

// Aggiorna server.properties nel container (solo Bedrock)
$propsString = "$field=$value\n";
$escapedProps = escapeshellarg($propsString);
$vmid = $server['proxmox_containerId'];
$sshKey = "/var/www/.ssh/id_proxmox";
$nodeIp = "192.168.1.251";

$cmd = "ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp "
     . "\"echo $escapedProps | pct exec $vmid -- tee -a /root/server.properties > /dev/null\"";

exec($cmd, $output, $status);
if($status !== 0) {
    echo json_encode(['success'=>false,'message'=>'Errore aggiornamento container']);
    exit;
}

echo json_encode(['success'=>true]);
