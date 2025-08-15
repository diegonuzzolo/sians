<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include("auth_check.php");
require 'config/config.php';

header('Content-Type: application/json');

$userId = $_SESSION['user_id'] ?? 0;
$serverId = intval($_POST['server_id'] ?? 0);

// Recupera server e container con info nodo
$stmt = $pdo->prepare("
    SELECT s.*, c.proxmox_containerId, n.hostname AS node_host, n.ssh_user, n.ssh_key_path
    FROM servers s
    LEFT JOIN containers c ON c.id = s.container_id
    LEFT JOIN proxmox_nodes n ON n.id = c.node_id
    WHERE s.id=? AND s.user_id=?
");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$server){
    echo json_encode(['success'=>false, 'message'=>'Server non trovato']);
    exit;
}

$vmid = $server['proxmox_containerId'];
$nodeHost = $server['node_host'];
$sshUser = $server['ssh_user'] ?? 'root';
$sshKeyPath = $server['ssh_key_path'] ?? '/var/www/.ssh/id_proxmox';

// Verifica file upload
if(!isset($_FILES['server_icon']) || $_FILES['server_icon']['error'] !== UPLOAD_ERR_OK){
    echo json_encode(['success'=>false, 'message'=>'Errore upload']);
    exit;
}

$fileTmp = $_FILES['server_icon']['tmp_name'];

// Controlla PNG 64x64
$imageInfo = getimagesize($fileTmp);
if(!$imageInfo || $imageInfo[0] !== 64 || $imageInfo[1] !== 64 || $imageInfo['mime'] !== 'image/png'){
    echo json_encode(['success'=>false, 'message'=>'Il file deve essere PNG 64x64 px']);
    exit;
}

// Copia file sul nodo Proxmox (SCP) e poi push nel container
$tmpRemotePath = "/tmp/server_icon.png";

// 1️⃣ Copia temporanea sul nodo
$scpCmd = sprintf(
    'scp -i %s -o StrictHostKeyChecking=no %s %s@%s:%s',
    escapeshellarg($sshKeyPath),
    escapeshellarg($fileTmp),
    escapeshellarg($sshUser),
    escapeshellarg($nodeHost),
    escapeshellarg($tmpRemotePath)
);
exec($scpCmd, $scpOutput, $scpStatus);

if($scpStatus !== 0){
    echo json_encode(['success'=>false, 'message'=>'Errore copia file sul nodo Proxmox']);
    exit;
}

// 2️⃣ Esegui pct push sul nodo
$remoteFinalPath = "/root/minecraft_bedrock/server_icon.png";
$sshCmd = sprintf(
    'ssh -i %s -o StrictHostKeyChecking=no %s@%s "pct push %d %s %s"',
    escapeshellarg($sshKeyPath),
    escapeshellarg($sshUser),
    escapeshellarg($nodeHost),
    $vmid,
    escapeshellarg($tmpRemotePath),
    escapeshellarg($remoteFinalPath)
);
exec($sshCmd, $sshOutput, $sshStatus);

if($sshStatus !== 0){
    echo json_encode(['success'=>false, 'message'=>'Errore pct push nel container']);
    exit;
}

// Pulizia file temporaneo sul nodo
$sshClean = sprintf(
    'ssh -i %s -o StrictHostKeyChecking=no %s@%s "rm -f %s"',
    escapeshellarg($sshKeyPath),
    escapeshellarg($sshUser),
    escapeshellarg($nodeHost),
    escapeshellarg($tmpRemotePath)
);
exec($sshClean);

echo json_encode(['success'=>true, 'message'=>'Icona aggiornata']);
