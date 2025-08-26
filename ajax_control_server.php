<?php
include("auth_check.php");
require 'config/config.php';
require_once 'includes/csrf.php';

csrf_check_or_die_json();
header('Content-Type: application/json');

try {
    $action = $_POST['action'] ?? '';
    $serverId = intval($_POST['server_id'] ?? 0);
    $userId = $_SESSION['user_id'] ?? 0;

    if (!$serverId || !$userId) {
        throw new Exception('Parametri mancanti');
    }

    // Recupera VMID e nodo
    $stmt = $pdo->prepare("
        SELECT s.name, c.proxmox_containerId, c.node_ip
        FROM servers s
        LEFT JOIN containers c ON c.id = s.container_id
        WHERE s.id=? AND s.user_id=?
    ");
    $stmt->execute([$serverId, $userId]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$server) {
        throw new Exception('Server non trovato');
    }

    $vmid = intval($server['proxmox_containerId']);
    $nodeIp = $server['node_ip'];
    $sshKey = '/var/www/.ssh/id_proxmox';
    $sessionName = "mc-" . $server['name'];

    // Comando base
    if ($action === 'start') {
        $cmd = sprintf(
            'ssh -i %s -o StrictHostKeyChecking=no root@%s "pct start %d"',
            escapeshellarg($sshKey),
            escapeshellarg($nodeIp),
            $vmid
        );
        $newStatus = 'running';
        $successMessage = 'Server avviato!';
    } elseif ($action === 'stop') {
        $cmd = sprintf(
            'ssh -i %s -o StrictHostKeyChecking=no root@%s "pct stop %d"',
            escapeshellarg($sshKey),
            escapeshellarg($nodeIp),
            $vmid
        );
        $newStatus = 'stopped';
        $successMessage = 'Server fermato!';
    } else {
        throw new Exception('Azione non valida');
    }

    // Esegui comando e cattura log
    exec($cmd . ' 2>&1', $output, $returnVar);
    $logOutput = implode("\n", $output);

    if ($returnVar !== 0) {
        throw new Exception("Errore durante l'azione '{$action}':\n" . $logOutput);
    }

    // Aggiorna status DB
    $stmt = $pdo->prepare("UPDATE servers SET status=? WHERE id=?");
    $stmt->execute([$newStatus, $serverId]);

    // Invia JSON con log
    echo json_encode([
        'success' => true,
        'message' => $successMessage,
        'status' => $newStatus,
        'log' => $logOutput
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'log' => $logOutput ?? ''
    ]);
}
