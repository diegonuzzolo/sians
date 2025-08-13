<?php
require 'config/config.php';
require 'auth_check.php';

$data = json_decode(file_get_contents('php://input'), true);

$serverId = intval($data['server_id'] ?? 0);
$vmid = intval($data['container_id'] ?? 0);
$serverName = trim($data['server_name'] ?? 'BedrockServer');
$cpuCores = intval($data['cpu'] ?? 2);
$ramMb = intval($data['ram'] ?? 4096);
$ip = $data['ip'] ?? null;

header('Content-Type: application/json');

if ($serverId <= 0 || $vmid <= 0 || !$ip) {
    echo json_encode(['success'=>false,'error'=>'Parametri non validi']);
    exit;
}

// Funzione per eseguire comandi nel container via SSH
function execContainerCommand($ip, $cmd) {
    $sshUser = 'root';
    $fullCmd = "ssh -o StrictHostKeyChecking=no {$sshUser}@{$ip} \"$cmd\" 2>&1";
    exec($fullCmd, $output, $retCode);
    return ['output'=>implode("\n",$output), 'code'=>$retCode];
}

// Aggiorna status server nel DB
function updateServerStatus(PDO $pdo, $serverId, $status) {
    $stmt = $pdo->prepare("UPDATE servers SET status = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$status, $serverId]);
}

try {
    updateServerStatus($pdo, $serverId, 'installing');

    // 1. Aggiorna pacchetti e installa dipendenze
    $cmd = "apt update && apt install -y wget unzip screen";
    $res = execContainerCommand($ip, $cmd);
    if ($res['code'] !== 0) throw new Exception("Errore eseguendo: $cmd\nOutput: " . $res['output']);

    // 2. Scarica Bedrock server ufficiale
    $cmd = "mkdir -p /bedrock && cd /bedrock && wget -q https://www.minecraft.net/content/dam/minecraft/edition/bedrock/server/bedrock-server-1.20.23.02.zip -O bedrock.zip && unzip -o bedrock.zip && rm bedrock.zip";
    $res = execContainerCommand($ip, $cmd);
    if ($res['code'] !== 0) throw new Exception("Errore scaricando server Bedrock\nOutput: " . $res['output']);

    // 3. Genera start.sh
    $startScript = "#!/bin/bash\ncd /bedrock\nLD_LIBRARY_PATH=. ./bedrock_server";
    $cmd = "echo " . escapeshellarg($startScript) . " > /bedrock/start.sh && chmod +x /bedrock/start.sh";
    $res = execContainerCommand($ip, $cmd);

    // 4. Genera stop.sh
    $stopScript = "#!/bin/bash\npkill -f bedrock_server";
    $cmd = "echo " . escapeshellarg($stopScript) . " > /bedrock/stop.sh && chmod +x /bedrock/stop.sh";
    $res = execContainerCommand($ip, $cmd);

    updateServerStatus($pdo, $serverId, 'installed');

    echo json_encode(['success'=>true]);

} catch(Exception $e) {
    updateServerStatus($pdo, $serverId, 'error');
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
