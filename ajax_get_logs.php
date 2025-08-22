<?php
require 'auth_check.php';
require 'config/config.php';

header('Content-Type: application/json');

$serverId = intval($_GET['server_id'] ?? 0);
$userId = $_SESSION['user_id'] ?? 0;
$offset = intval($_GET['offset'] ?? 0);

$stmt = $pdo->prepare("SELECT proxmox_containerId FROM servers WHERE id=? AND user_id=?");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    echo json_encode(['success' => false, 'message' => 'Accesso negato.']);
    exit;
}

$containerId = $server['proxmox_containerId'];
$proxmox_host = 'IL_TUO_IP_PROXMOX'; // Es. 192.168.1.251
$ssh_key_path = '/var/www/.ssh/id_proxmox';
$logFilePathInContainer = "/root/minecraft_bedrock/logs/latest.log"; // O il tuo percorso di debug

// --- Esegui i comandi su Proxmox via SSH ---

try {
    // 1. Ottieni la dimensione del file di log
    $stat_command = "ssh -i {$ssh_key_path} -o StrictHostKeyChecking=no root@{$proxmox_host} "
                  . escapeshellarg("pct exec {$containerId} -- stat -c%s " . escapeshellarg($logFilePathInContainer));
    
    $fileSize_str = shell_exec($stat_command . " 2>/dev/null");
    $fileSize = intval(trim($fileSize_str));

    $logContent = '';

    // 2. Se il file è cresciuto, leggi le nuove righe
    if ($fileSize > $offset) {
        // tail -c +N parte dall'N-esimo byte. L'offset è la dimensione vecchia, quindi partiamo dal byte successivo.
        $start_byte = $offset + 1;
        $tail_command = "ssh -i {$ssh_key_path} -o StrictHostKeyChecking=no root@{$proxmox_host} "
                      . escapeshellarg("pct exec {$containerId} -- tail -c +{$start_byte} " . escapeshellarg($logFilePathInContainer));
        
        $logContent = shell_exec($tail_command . " 2>/dev/null");
    }

    $logContent = htmlspecialchars($logContent ?? '');

    echo json_encode([
        'success' => true,
        'log' => nl2br($logContent),
        'new_size' => $fileSize
    ]);

} catch (\Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Errore durante l esecuzione SSH: ' . $e->getMessage()]);
}