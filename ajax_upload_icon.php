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

// Verifica file upload
if(!isset($_FILES['server_icon']) || $_FILES['server_icon']['error'] !== UPLOAD_ERR_OK){
    echo json_encode(['success'=>false, 'message'=>'Errore upload']);
    exit;
}

$fileTmp = $_FILES['server_icon']['tmp_name'];

// Controlla PNG e dimensioni
$imageInfo = getimagesize($fileTmp);
if(!$imageInfo || $imageInfo[0] !== 64 || $imageInfo[1] !== 64 || $imageInfo['mime'] !== 'image/png'){
    echo json_encode(['success'=>false, 'message'=>'Il file deve essere PNG 64x64 px']);
    exit;
}

// Copia il file nel container usando pct push
$remotePath = "/root/minecraft_bedrock/server_icon.png";
$cmd = "pct push $vmid $fileTmp $remotePath";
exec($cmd, $output, $status);

if($status !== 0){
    echo json_encode(['success'=>false, 'message'=>'Errore copia icona nel container']);
    exit;
}

echo json_encode(['success'=>true]);
