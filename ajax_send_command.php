<?php
require 'auth_check.php';
require 'config/config.php';
require 'includes/csrf.php';

header('Content-Type: application/json');

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    echo json_encode(['success'=>false,'message'=>'Metodo non consentito.']);
    exit;
}

csrf_check_or_die();

$userId = $_SESSION['user_id'] ?? 0;
$serverId = intval($_POST['server_id'] ?? 0);
$command = trim($_POST['command'] ?? '');

if(empty($command)){
    echo json_encode(['success'=>false,'message'=>'Comando vuoto.']);
    exit;
}

// Recupera container e nodo
$stmt = $pdo->prepare("SELECT proxmox_containerId, proxmox_node FROM servers WHERE id=? AND user_id=?");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$server || empty($server['proxmox_node'])){
    echo json_encode(['success'=>false,'message'=>'Accesso negato o nodo non configurato.']);
    exit;
}

$containerId = $server['proxmox_containerId'];
$node = 'pve';

// Funzione invio comando
function sendCommandToContainer($containerId, $node, $command){
    global $proxmox_host, $proxmox_port, $proxmox_user, $proxmox_token_id, $proxmox_token_secret;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://$proxmox_host:$proxmox_port/api2/json/access/ticket");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: PVEAPIToken=$proxmox_user!$proxmox_token_id=$proxmox_token_secret"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $auth = curl_exec($ch);
    curl_close($ch);

    $authData = json_decode($auth,true);
    if(!isset($authData['data']['ticket'])) return false;
    $ticket = $authData['data']['ticket'];
    $csrf = $authData['data']['CSRFPreventionToken'];

    $screenCmd = "screen -S minecraft_server -p 0 -X stuff \"$command\\n\"";
    $apiCmd = json_encode(['/bin/sh','-c',$screenCmd]);
    $postData = http_build_query(['command'=>$apiCmd]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://$proxmox_host:$proxmox_port/api2/json/nodes/$node/lxc/$containerId/exec");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["CSRFPreventionToken: $csrf", "Cookie: PVEAuthCookie=$ticket"]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($res,true);
    return isset($data['data']);
}

$success = sendCommandToContainer($containerId,$node,$command);
echo json_encode(['success'=>$success,'message'=>$success?'Comando inviato':'Errore invio comando']);
