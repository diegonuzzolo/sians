<?php
include("auth_check.php");
require 'config/config.php';

function csrf_check_or_die_json() {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'CSRF token non valido']);
        exit;
    }
}


csrf_check_or_die_json();

try {
    $userId   = $_SESSION['user_id'] ?? 0;
    $serverId = intval($_POST['id'] ?? 0);
    $action   = $_POST['action'] ?? '';

    if (!$userId || !$serverId) {
        throw new Exception("Parametri non validi");
    }

    if (!in_array($action, ['start', 'stop'])) {
        throw new Exception("Azione non valida");
    }

    // Recupera VMID del server
    $stmt = $pdo->prepare("SELECT proxmox_containerId FROM servers WHERE id=? AND user_id=?");
    $stmt->execute([$serverId, $userId]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$server || empty($server['proxmox_containerId'])) {
        throw new Exception("Server non trovato o VMID mancante");
    }

    $vmid     = intval($server['proxmox_containerId']);
    $nodeIp   = '192.168.1.251';
    $sshKey   = '/var/www/.ssh/id_proxmox';
    $remoteScript = ($action === 'start') ? '/root/minecraft_bedrock/start.sh' : '/root/minecraft_bedrock/stop.sh';

    // Comando SSH con pct exec + screen
    $cmd = sprintf(
        'ssh -i %s -o StrictHostKeyChecking=no root@%s "pct exec %d -- screen -dmS mcserver %s"',
        escapeshellarg($sshKey),
        escapeshellarg($nodeIp),
        $vmid,
        escapeshellarg($remoteScript)
    );

    exec($cmd . " 2>&1", $output, $ret);

    // Log per debug
    file_put_contents('/tmp/debug_server_control.log', date('Y-m-d H:i:s') . " | CMD: $cmd\nRET: $ret\nOUTPUT:\n" . implode("\n", $output) . "\n\n", FILE_APPEND);

    if ($ret !== 0) {
        throw new Exception("Errore esecuzione {$action}. Controlla log: /tmp/debug_server_control.log");
    }

    // Aggiorna lo status nel DB
    $newStatus = ($action === 'start') ? 'running' : 'stopped';
    $stmt = $pdo->prepare("UPDATE servers SET status=? WHERE id=? AND user_id=?");
    $stmt->execute([$newStatus, $serverId, $userId]);

    echo json_encode([
        'success' => true,
        'message' => ucfirst($action) . " del server eseguita con successo",
        'status'  => $newStatus
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
