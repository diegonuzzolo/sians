<?php
// Righe per il debug, le lasciamo per sicurezza
ini_set('display_errors', 1);
error_reporting(E_ALL);

include("auth_check.php");
require 'config/config.php';

header('Content-Type: application/json');

try {
    $userId   = $_SESSION['user_id'] ?? 0;
    $serverId = intval($_GET['id'] ?? 0);

    if (!$userId || !$serverId) {
        throw new Exception("Parametri non validi");
    }

    // --- MODIFICA: Utilizzo la tua query SQL corretta ---
    $stmt = $pdo->prepare("
        SELECT s.id AS server_id, s.name AS server_name, s.proxmox_containerId, c.hostname, c.ip
        FROM servers s
        LEFT JOIN containers c ON s.container_id = c.id
        WHERE s.id = ? AND s.user_id = ?
    ");
    $stmt->execute([$serverId, $userId]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$server || empty($server['proxmox_containerId'])) {
        throw new Exception("Server non trovato o proxmox_containerId mancante.");
    }

    // --- MODIFICA: Utilizzo le informazioni corrette ---
    $vmid     = intval($server['proxmox_containerId']);
    $nodeIp   = '192.168.1.251'; // L'IP del tuo nodo Proxmox, come hai specificato
    $sshKey   = '/var/www/.ssh/id_proxmox';
    $remoteLogPath = '/root/minecraft_bedrock/server.log';

    // Comando per leggere il contenuto del file DENTRO il container
    $cmd = sprintf(
        'sudo ssh -i %s -o StrictHostKeyChecking=no -o ConnectTimeout=10 root@%s "pct exec %d -- cat %s"',
        escapeshellarg($sshKey),
        escapeshellarg($nodeIp),
        $vmid,
        escapeshellarg($remoteLogPath)
    );

    $logContent = shell_exec($cmd);

    // shell_exec può restituire null in caso di errore
    if ($logContent === null) {
        throw new Exception("Errore durante l'esecuzione del comando per leggere il log. Controlla la connessione SSH e i log di debug.");
    }

    echo json_encode(['success' => true, 'content' => $logContent]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}