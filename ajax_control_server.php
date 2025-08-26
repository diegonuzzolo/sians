<?php
include("auth_check.php");
require 'config/config.php';
require_once 'includes/csrf.php';

csrf_check_or_die_json();
header('Content-Type: application/json');

try {
    $action = $_POST['action'] ?? '';
    $serverId = intval($_POST['server_id'] ?? 0);
    $userId   = $_SESSION['user_id'] ?? 0;

    if (!$serverId || !$userId) throw new Exception('Parametri mancanti');

    // Recupera VMID e nodo
    $stmt = $pdo->prepare("
        SELECT s.name, c.proxmox_containerId, c.node_ip
        FROM servers s
        LEFT JOIN containers c ON c.id = s.container_id
        WHERE s.id=? AND s.user_id=?
    ");
    $stmt->execute([$serverId, $userId]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$server) throw new Exception('Server non trovato');

    $vmid = intval($server['proxmox_containerId']);
    $nodeIp = $server['node_ip'];
    $sshKey = '/var/www/.ssh/id_proxmox';
    $sessionName = "mc-" . $server['name'];

    if ($action === 'start') {
        // Avvia il server in screen con parametri
        $cmd = sprintf(
            'ssh -i %s -o StrictHostKeyChecking=no root@%s "pct exec %d -- screen -dmS %s /root/minecraft_bedrock/start.sh %d"',
            escapeshellarg($sshKey),
            escapeshellarg($nodeIp),
            $vmid,
            escapeshellarg($sessionName),
            $serverId
        );
        $newStatus = 'running';
        $successMessage = 'Server avviato!';
    } elseif ($action === 'stop') {
        // Ferma server inviando comando 'stop' allo screen
        $cmd = sprintf(
            'ssh -i %s -o StrictHostKeyChecking=no root@%s "pct exec %d -- screen -S %s -p 0 -X stuff %s"',
            escapeshellarg($sshKey),
            escapeshellarg($nodeIp),
            $vmid,
            escapeshellarg($sessionName),
            escapeshellarg("stop\n")
        );
        $newStatus = 'stopped';
        $successMessage = 'Server fermato!';
    } else {
        throw new Exception('Azione non valida');
    }

    exec($cmd . ' 2>&1', $output, $status);
    if ($status !== 0) throw new Exception('Errore comando: ' . implode("\n", $output));

    // Aggiorna status DB
    $stmt = $pdo->prepare("UPDATE servers SET status=? WHERE id=?");
    $stmt->execute([$newStatus, $serverId]);

    echo json_encode(['success'=>true, 'message'=>$successMessage, 'status'=>$newStatus]);

} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
