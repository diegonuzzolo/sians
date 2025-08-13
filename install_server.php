<?php
require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$serverId = intval($input['server_id'] ?? 0);

if ($serverId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Server ID non valido']);
    exit;
}

// Recupera dati server e container
$stmt = $pdo->prepare("SELECT s.*, c.proxmox_containerId AS vmid, c.private_ip
                       FROM servers s
                       JOIN containers c ON c.id = s.container_id
                       WHERE s.id = ?");
$stmt->execute([$serverId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    echo json_encode(['success' => false, 'error' => 'Server non trovato']);
    exit;
}

$vmid = (int)$server['vmid'];
$containerIp = $server['private_ip'];
$sshUser = 'root';
$sshPass = 'Lgu8330Serve6';

try {
    $auth = proxmoxLogin();
    $status = getContainerStatus($vmid, $auth);

    if ($status !== 'running') {
        startContainer($vmid, $auth);
        // Attendere che il container sia pronto
        sleep(5);
    }

    // Comando shell da eseguire via SSH
    $cmd = <<<CMD
apt update && apt install -y wget unzip screen && \
wget -O /tmp/bedrock-server.zip https://minecraft.azureedge.net/bin-linux/bedrock-server-1.20.30.23.zip && \
unzip -o /tmp/bedrock-server.zip -d /opt/bedrock && \
rm /tmp/bedrock-server.zip
CMD;

    // Usa sshpass per passare la password al client ssh
    $escapedCmd = escapeshellarg($cmd);
    $sshCmd = "sshpass -p " . escapeshellarg($sshPass) . 
              " ssh -o StrictHostKeyChecking=no $sshUser@$containerIp $escapedCmd";

    exec($sshCmd, $outputLines, $returnVar);

    if ($returnVar !== 0) {
        throw new Exception("Errore eseguendo comandi sul container: " . implode("\n", $outputLines));
    }

    // Aggiorna stato server
    $stmt = $pdo->prepare("UPDATE servers SET status = 'installed', updated_at = NOW() WHERE id = ?");
    $stmt->execute([$serverId]);

    echo json_encode(['success' => true, 'output' => implode("\n", $outputLines)]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
