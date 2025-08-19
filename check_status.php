<?php
include("auth_check.php");
require 'config/config.php';

header('Content-Type: application/json');

try {
    $serverId = intval($_GET['id'] ?? 0);
    $userId = $_SESSION['user_id'] ?? 0;

    if (!$serverId || !$userId) throw new Exception('Parametri non validi');

    // Recupera VMID del server
    $stmt = $pdo->prepare("SELECT proxmox_containerId FROM servers WHERE id=? AND user_id=?");
    $stmt->execute([$serverId, $userId]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$server) throw new Exception('Server non trovato');

    $vmid = intval($server['proxmox_containerId']);
    $nodeIp = '192.168.1.251';
    $sshKey = '/var/www/.ssh/id_proxmox';

    // Controlla se bedrock_server è in esecuzione
    $cmd = sprintf(
        'ssh -i %s -o StrictHostKeyChecking=no root@%s "pct exec %d -- pgrep -f \'bedrock_server\'"',
        escapeshellarg($sshKey),
        escapeshellarg($nodeIp),
        $vmid
    );

    exec($cmd . " 2>&1", $output, $status);

    // Determina status reale
    $statusText = (!empty($output)) ? 'running' : 'stopped';

    // Aggiorna DB se necessario
    $stmt = $pdo->prepare("UPDATE servers SET status=? WHERE id=?");
    $stmt->execute([$statusText, $serverId]);

    echo json_encode(['status' => $statusText]);

} catch (Exception $e) {
    echo json_encode(['status' => 'stopped', 'error' => $e->getMessage()]);
}
