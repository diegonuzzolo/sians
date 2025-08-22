<?php
include("auth_check.php");
require 'config/config.php';
require_once 'includes/csrf.php';

csrf_check_or_die_json();
header('Content-Type: application/json');

try {
    csrf_check_or_die();

    $serverId = intval($_POST['id'] ?? 0);
    $command  = trim($_POST['command'] ?? '');
    $userId = $_SESSION['user_id'] ?? 0;

    if (!$serverId || !$userId || !$command) throw new Exception('Parametri non validi');

    $stmt = $pdo->prepare("SELECT proxmox_containerId FROM servers WHERE id=? AND user_id=?");
    $stmt->execute([$serverId, $userId]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$server) throw new Exception('Server non trovato');

    $vmid = intval($server['proxmox_containerId']);
    $nodeIp = '192.168.1.251';
    $sshKey = '/var/www/.ssh/id_proxmox';

    // Invia comando alla sessione screen "bedrock"
    $cmd = "ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp "
         . "\"pct exec $vmid -- screen -S bedrock -X stuff '" . addslashes($command) . "\\n'\"";

    exec($cmd . " 2>&1", $output, $status);
    if ($status !== 0) throw new Exception('Errore invio comando');

    echo json_encode(['success'=>true, 'message'=>'Comando inviato!']);

} catch (Exception $e) {
    echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
}
