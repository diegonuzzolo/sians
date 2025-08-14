<?php
include("auth_check.php");
require 'config/config.php';
require 'proxmox_functions.php';

header('Content-Type: application/json');

$userId = $_SESSION['user_id'] ?? 0;
$serverId = intval($_POST['server_id'] ?? 0);


// Recupera server e container
$stmt = $pdo->prepare("
    SELECT s.*, c.proxmox_containerId
    FROM servers s
    LEFT JOIN containers c ON c.id = s.container_id
    WHERE s.id=? AND s.user_id=?
");
$stmt->execute([$serverId,$userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$server){
    echo json_encode(['success'=>false, 'message'=>'Server non trovato']);
    exit;
}

$vmid = $server['proxmox_containerId'];

// Genera server.properties
$propsString = generateBedrockProperties($_POST);

// Scrive direttamente nel container
$tmpFile = "/root/minecraft_bedrock/server.properties";
$cmd = "pct exec $vmid -- /bin/bash -c " . escapeshellarg("echo " . escapeshellarg($propsString) . " > $tmpFile");
exec($cmd, $output, $status);

if($status !== 0){
    echo json_encode(['success'=>false, 'message'=>'Errore scrittura server.properties']);
    exit;
}

// Aggiorna anche il DB per valori memorizzati
$stmt = $pdo->prepare("
    UPDATE servers 
    SET motd=?, gamemode=?, difficulty=?, max_players=?, view_distance=?, whitelist=?, blacklist=?, enable_command_blocks=?, level_name=?, seed=?, spawn_protection=?, white_list=? 
    WHERE id=? AND user_id=?
");
$stmt->execute([
    trim($_POST['motd'] ?? ''),
    $_POST['gamemode'] ?? 'survival',
    $_POST['difficulty'] ?? 'easy',
    max(1,min(100,intval($_POST['max_players'] ?? 10))),
    max(2,min(32,intval($_POST['view_distance'] ?? 6))),
    trim($_POST['whitelist'] ?? ''),
    trim($_POST['blacklist'] ?? ''),
    in_array($_POST['enable_command_blocks'] ?? 'false',['true','false']) ? $_POST['enable_command_blocks'] : 'false',
    trim($_POST['level_name'] ?? 'world'),
    trim($_POST['seed'] ?? ''),
    max(0,intval($_POST['spawn_protection'] ?? 16)),
    in_array($_POST['white_list'] ?? 'false',['true','false']) ? $_POST['white_list'] : 'false',
    $serverId,
    $userId
]);

echo json_encode(['success'=>true]);
