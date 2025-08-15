<?php
include("auth_check.php");
require 'config/config.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Metodo non consentito');

    $userId = $_SESSION['user_id'] ?? 0;
    $serverId = intval($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if (!$userId || !$serverId) throw new Exception('Parametri non validi');
    if (!in_array($action, ['start','stop'])) throw new Exception('Azione non valida');

    // Recupera VMID del container
    $stmt = $pdo->prepare("SELECT proxmox_containerId FROM servers WHERE id=? AND user_id=?");
    $stmt->execute([$serverId, $userId]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$server) throw new Exception('Server non trovato');

    $vmid = intval($server['proxmox_containerId']);
    $nodeIp = '192.168.1.251';
    $sshKey = '/var/www/.ssh/id_proxmox';

    // Script da eseguire
    $script = ($action === 'start') ? '/root/minecraft_bedrock/start.sh' : '/root/minecraft_bedrock/stop.sh';

    // Comando SSH + pct exec + screen
    $cmd = sprintf(
        'ssh -i %s -o StrictHostKeyChecking=no root@%s "pct exec %d -- screen -dmS mcserver bash %s"',
        escapeshellarg($sshKey),
        escapeshellarg($nodeIp),
        $vmid,
        escapeshellarg($script)
    );

    exec($cmd . " 2>&1", $output, $status);
    if ($status !== 0) {
        throw new Exception("Errore esecuzione script: ".implode("\n",$output));
    }

    $msg = ($action === 'start') ? 'Server avviato' : 'Server fermato';
    echo json_encode(['success'=>true,'message'=>$msg]);

} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
