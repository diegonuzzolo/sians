<?php
include("auth_check.php");
require 'config/config.php';

header('Content-Type: application/json');

try {
    $userId = $_SESSION['user_id'] ?? 0;
    $serverId = intval($_POST['server_id'] ?? 0);
    $command = trim($_POST['command'] ?? '');
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!$userId || !$serverId || !$command) throw new Exception("Parametri mancanti");
    if ($csrfToken !== ($_SESSION['csrf_token'] ?? '')) throw new Exception("Token CSRF non valido");

    // Recupera container + nodo
    $stmt = $pdo->prepare("SELECT c.proxmox_containerId, c.node_ip
                           FROM servers s
                           LEFT JOIN containers c ON c.id = s.container_id
                           WHERE s.id=? AND s.user_id=?");
    $stmt->execute([$serverId, $userId]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$server) throw new Exception("Server non trovato");

    $vmid = (int)$server['proxmox_containerId'];
    $nodeIp = $server['node_ip'];
    $sshKey = '/var/www/.ssh/id_proxmox';
    $screenName = "mc-$serverId";

    // Invia comando alla sessione screen
    $cmd = sprintf(
        'ssh -i %s -o StrictHostKeyChecking=no root@%s "pct exec %d -- screen -S %s -p 0 -X stuff %s"',
        escapeshellarg($sshKey),
        escapeshellarg($nodeIp),
        $vmid,
        escapeshellarg($screenName),
        escapeshellarg($command . "\n")
    );

    exec($cmd . " 2>&1", $output, $status);
    if ($status !== 0) throw new Exception("Errore invio comando: " . implode("\n", $output));

    echo json_encode(['success' => true, 'message' => 'Comando inviato con successo']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
