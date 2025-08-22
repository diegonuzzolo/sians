<?php
// ajax_get_log.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include("auth_check.php");
require 'config/config.php';

header('Content-Type: application/json');

try {
    $userId   = $_SESSION['user_id'] ?? 0;
    $serverId = intval($_GET['id'] ?? 0);

    if (!$userId || !$serverId) {
        throw new Exception("Parametri non validi.");
    }

    // Recupera VMID e IP del nodo
    $stmt = $pdo->prepare("
        SELECT s.proxmox_containerId, n.ip AS node_ip
        FROM servers s
        JOIN containers c ON s.container_id = c.id
        JOIN nodes n ON c.node_id = n.id
        WHERE s.id = ? AND s.user_id = ?
    ");
    $stmt->execute([$serverId, $userId]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$server) {
        throw new Exception("Server non trovato.");
    }

    $vmid = intval($server['proxmox_containerId']);
    $nodeIp = $server['node_ip'];
    $sshKey = '/var/www/.ssh/id_proxmox';
    $remoteLogPath = '/root/minecraft_bedrock/server.log';

    // Controlla che la chiave SSH sia leggibile
    if (!file_exists($sshKey) || !is_readable($sshKey)) {
        throw new Exception("Chiave SSH non leggibile: $sshKey");
    }

    // Comando sicuro per leggere il log
    $cmd = sprintf(
        'sudo ssh -i %s -o StrictHostKeyChecking=no -o ConnectTimeout=10 root@%s "pct exec %d -- cat %s 2>&1"',
        escapeshellarg($sshKey),
        escapeshellarg($nodeIp),
        $vmid,
        escapeshellarg($remoteLogPath)
    );

    $logContent = shell_exec($cmd);

    if ($logContent === null) {
        throw new Exception("Errore durante l'esecuzione del comando SSH.");
    }

    echo json_encode(['success' => true, 'content' => $logContent]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
