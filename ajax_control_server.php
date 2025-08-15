<?php
include("auth_check.php");
require 'config/config.php';

header('Content-Type: application/json');

try {
    $action = $_POST['action'] ?? '';
    $serverId = intval($_POST['id'] ?? 0);
    $userId = $_SESSION['user_id'] ?? 0;

    if (!$serverId || !$userId) throw new Exception('Parametri non validi');

    // Recupera VMID e IP del container
    $stmt = $pdo->prepare("SELECT proxmox_containerId FROM servers WHERE id=? AND user_id=?");
    $stmt->execute([$serverId, $userId]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$server) throw new Exception('Server non trovato');

    $vmid = intval($server['proxmox_containerId']);
    $nodeIp = '192.168.1.251';
    $sshKey = '/var/www/.ssh/id_proxmox';

    // Determina comando
    if ($action === 'start') {
        $cmd = "ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp "
             . "\"pct exec $vmid -- screen -dmS bedrock /root/minecraft_bedrock/start.sh\"";
        $newStatus = 'running';
        $successMessage = 'Server avviato!';
    } elseif ($action === 'stop') {
        $cmd = "ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp "
             . "\"pct exec $vmid -- /root/minecraft_bedrock/stop.sh\"";
        $newStatus = 'stopped';
        $successMessage = 'Server fermato!';
    } else {
        throw new Exception('Azione non valida');
    }

    // Esegui comando
    exec($cmd . " 2>&1", $output, $status);
    if ($status !== 0) throw new Exception('Errore esecuzione comando: ' . implode("\n", $output));

    // Aggiorna lo status nel DB
    $stmt = $pdo->prepare("UPDATE servers SET status=? WHERE id=?");
    $stmt->execute([$newStatus, $serverId]);

    echo json_encode(['success' => true, 'message' => $successMessage, 'status' => $newStatus]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
