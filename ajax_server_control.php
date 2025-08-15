<?php
include("auth_check.php");
require 'config/config.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Metodo non consentito');

    $userId = $_SESSION['user_id'] ?? 0;
    $serverId = intval($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if (!$userId || !$serverId || !in_array($action, ['start','stop'])) {
        throw new Exception('Parametri non validi');
    }

    // Recupera VMID e info container
    $stmt = $pdo->prepare("SELECT proxmox_containerId FROM servers WHERE id=? AND user_id=?");
    $stmt->execute([$serverId, $userId]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$server) throw new Exception('Server non trovato');

    $vmid = intval($server['proxmox_containerId']);
    $nodeIp = '192.168.1.251';
    $sshKey = '/var/www/.ssh/id_proxmox';

    if (!file_exists($sshKey)) throw new Exception("Chiave SSH non trovata: {$sshKey}");

    $script = $action === 'start' ? '/root/minecraft_bedrock/start.sh' : '/root/minecraft_bedrock/stop.sh';

    // Comando pct exec sul nodo
    $cmd = sprintf(
        'ssh -i %s -o StrictHostKeyChecking=no root@%s "pct exec %d -- bash %s"',
        escapeshellarg($sshKey),
        escapeshellarg($nodeIp),
        $vmid,
        escapeshellarg($script)
    );

    exec($cmd . " 2>&1", $output, $status);

    if ($status !== 0) {
        throw new Exception("Errore esecuzione {$action}: " . implode("\n",$output));
    }

    echo json_encode(['success'=>true, 'message'=>"Server {$action} avviato con successo"]);

} catch (Exception $e) {
    echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
}
