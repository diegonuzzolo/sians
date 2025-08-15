<?php
include("auth_check.php");
require 'config/config.php';

header('Content-Type: application/json');

try {
    $action = $_POST['action'] ?? '';
    $serverId = intval($_POST['id'] ?? 0);
    $userId = $_SESSION['user_id'] ?? 0;

    if (!$serverId || !$userId || !in_array($action, ['start','stop'])) {
        throw new Exception('Parametri non validi');
    }

    // Recupera VMID e nodo dal DB
    $stmt = $pdo->prepare("SELECT proxmox_containerId FROM servers WHERE id=? AND user_id=?");
    $stmt->execute([$serverId,$userId]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!$server) throw new Exception("Server non trovato");

    $vmid = intval($server['proxmox_containerId']);
    $nodeIp = '192.168.1.251';
    $sshKey = '/var/www/.ssh/id_proxmox';

    $script = ($action === 'start') ? '/root/minecraft_bedrock/start.sh' : '/root/minecraft_bedrock/stop.sh';

    // Comando pct exec con screen
    $cmd = sprintf(
        'ssh -i %s -o StrictHostKeyChecking=no root@%s "pct exec %d -- screen -dmS bedrock bash %s"',
        escapeshellarg($sshKey),
        escapeshellarg($nodeIp),
        $vmid,
        escapeshellarg($script)
    );

    exec($cmd . " 2>&1", $output, $status);

    if($status !== 0){
        throw new Exception("Errore esecuzione {$action}. Output: ".implode("\n",$output));
    }

    // Aggiorna stato nel DB: 'running' se start, 'stopped' se stop
    $newStatus = ($action === 'start') ? 'running' : 'stopped';
    $stmt = $pdo->prepare("UPDATE servers SET status=? WHERE id=? AND user_id=?");
    $stmt->execute([$newStatus,$serverId,$userId]);

    echo json_encode(['success'=>true,'message'=>"Server {$action}ato con successo",'status'=>$newStatus]);

} catch(Exception $e){
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
