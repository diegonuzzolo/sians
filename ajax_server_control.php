<?php
include("auth_check.php");
require 'config/config.php';

header('Content-Type: application/json');

try {
    $userId = $_SESSION['user_id'] ?? 0;
    $serverId = intval($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if (!$userId || !$serverId) throw new Exception("Parametri non validi");
    if (!in_array($action, ['start', 'stop'])) throw new Exception("Azione non valida");

    // Recupera VMID del server
    $stmt = $pdo->prepare("SELECT proxmox_containerId FROM servers WHERE id=? AND user_id=?");
    $stmt->execute([$serverId, $userId]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$server) throw new Exception("Server non trovato");

    $vmid = intval($server['proxmox_containerId']);
    $nodeIp = '192.168.1.251';
    $sshKey = '/var/www/.ssh/id_proxmox';
    $remoteScript = ($action === 'start') ? '/root/minecraft_bedrock/start.sh' : '/root/minecraft_bedrock/stop.sh';

    // Comando SSH per lanciare lo script nel container con screen
    $cmd = sprintf(
        'ssh -i %s -o StrictHostKeyChecking=no root@%s "pct exec %d -- screen -dmS mcserver %s"',
        escapeshellarg($sshKey),
        escapeshellarg($nodeIp),
        $vmid,
        escapeshellarg($remoteScript)
    );

    exec($cmd . " 2>&1", $output, $ret);

    if ($ret !== 0) {
        throw new Exception("Errore esecuzione {$action}. Output: " . implode("\n", $output));
    }

    // Aggiorna lo status nel DB
    $newStatus = ($action === 'start') ? 'running' : 'stopped';
    $stmt = $pdo->prepare("UPDATE servers SET status=? WHERE id=? AND user_id=?");
    $stmt->execute([$newStatus, $serverId, $userId]);

    echo json_encode(['success' => true, 'message' => ucfirst($action) . " del server eseguita con successo", 'status' => $newStatus]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
