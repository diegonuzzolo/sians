<?php
require 'auth_check.php';
require 'config/config.php';

header('Content-Type: application/json');

$userId = $_SESSION['user_id'] ?? 0;
$serverId = intval($_GET['server_id'] ?? 0);
$offset = intval($_GET['offset'] ?? 0);

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

// Percorso del log dentro il container
$logPath = "/root/minecraft_bedrock/debug_output.log";

// Funzione di recupero log (diretto da Proxmox)
function getContainerLog($containerId, $node, $filePath, $offset = 0) {
    global $proxmox_host, $proxmox_port, $proxmox_user, $proxmox_token_id, $proxmox_token_secret;

    // Autenticazione
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://$proxmox_host:$proxmox_port/api2/json/access/ticket");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: PVEAPIToken=$proxmox_user!$proxmox_token_id=$proxmox_token_secret"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $auth = curl_exec($ch);
    curl_close($ch);

    $authData = json_decode($auth,true);
    if(!isset($authData['data']['ticket'])) return ['log'=>'','new_size'=>0];
    $ticket = $authData['data']['ticket'];
    $csrf = $authData['data']['CSRFPreventionToken'];

    // Stat file
    $statUrl = "https://$proxmox_host:$proxmox_port/api2/json/nodes/$node/lxc/$containerId/files/stat?filepath=".urlencode($filePath);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $statUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["CSRFPreventionToken: $csrf", "Cookie: PVEAuthCookie=$ticket"]);
    $statRes = curl_exec($ch);
    curl_close($ch);

    $statData = json_decode($statRes,true);
    $size = $statData['data']['size'] ?? 0;
    if($size <= $offset) return ['log'=>'','new_size'=>$size];

    // Read file
    $readUrl = "https://$proxmox_host:$proxmox_port/api2/json/nodes/$node/lxc/$containerId/files/read?filepath=".urlencode($filePath)."&start=$offset";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $readUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["CSRFPreventionToken: $csrf", "Cookie: PVEAuthCookie=$ticket"]);
    $readRes = curl_exec($ch);
    curl_close($ch);

    $readData = json_decode($readRes,true);
    $log = $readData['data']['content'] ?? '';

    return ['log'=>$log,'new_size'=>$size];
}

$result = getContainerLog($containerId,$node,$logPath,$offset);
$result['log'] = htmlspecialchars($result['log']);
$result['log'] = nl2br($result['log']);

echo json_encode(array_merge(['success'=>true],$result));
