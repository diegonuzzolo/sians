<?php
include("auth_check.php");
require 'config/config.php';
require_once 'includes/csrf.php';

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

    // Recupera IP del container
    $stmt = $pdo->prepare("SELECT c.ip 
                           FROM servers s 
                           JOIN containers c ON s.container_id = c.id 
                           WHERE s.id=? AND s.user_id=?");
    $stmt->execute([$serverId, $userId]);
    $container = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$container || empty($container['ip'])) {
        throw new Exception("Server non trovato o IP container mancante");
    }

    $containerIp   = $container['ip'];
    $sshKey        = '/var/www/.ssh/id_rsa_container'; // chiave statica www-data
    $remoteScript  = ($action === 'start') ? '/root/minecraft_bedrock/start.sh' : '/root/minecraft_bedrock/stop.sh';

    // Comando SSH diretto nel container
    $cmd = sprintf(
        'ssh -i %s -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@%s "screen -dmS mcserver %s"',
        escapeshellarg($sshKey),
        escapeshellarg($containerIp),
        escapeshellarg($remoteScript)
    );

    exec($cmd . " 2>&1", $output, $ret);

    // Log debug
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
