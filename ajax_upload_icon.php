<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'auth_check.php';
require 'config/config.php';

header('Content-Type: application/json');

try {
    $serverId = intval($_POST['server_id'] ?? 0);
    if ($serverId <= 0) throw new Exception("ID server non valido");

    // Controllo file upload
    if (empty($_FILES['server-icon']['tmp_name']) || $_FILES['server-icon']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Upload icona non valido o errore PHP: " . ($_FILES['server-icon']['error'] ?? 'nessun file'));
    }

    $tmpLocal = $_FILES['server-icon']['tmp_name'];

    // Controllo PNG 64x64
    $info = getimagesize($tmpLocal);
    if (!$info || $info[0] !== 64 || $info[1] !== 64 || $info['mime'] !== 'image/png') {
        throw new Exception("File non valido: deve essere PNG 64x64 px");
    }

    // Recupera VMID dal DB
    $stmt = $pdo->prepare("SELECT proxmox_containerId FROM servers WHERE id=?");
    $stmt->execute([$serverId]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$server) throw new Exception("Server non trovato");

    $vmid = intval($server['proxmox_containerId']);
    if ($vmid <= 0) throw new Exception("VMID non valido");

    // Parametri nodo Proxmox
    $nodeHost   = '192.168.1.251';
    $sshUser    = 'root';
    $sshKeyPath = '/var/www/.ssh/id_proxmox';

    if (!file_exists($sshKeyPath) || !is_readable($sshKeyPath)) {
        throw new Exception("Chiave SSH non trovata o non leggibile: {$sshKeyPath}");
    }

    $escKey  = escapeshellarg($sshKeyPath);
    $escNode = escapeshellarg("{$sshUser}@{$nodeHost}");
    $remoteTmp = "/tmp/server-icon.png";
    $remoteFinal = "/root/minecraft_bedrock/server-icon.png";

    // Funzione helper per eseguire comandi
    $run = function($cmd, $msg) {
        exec($cmd . " 2>&1", $out, $ret);
        if ($ret !== 0) {
            throw new Exception("{$msg} | Exit {$ret} | Output: " . implode("\n",$out));
        }
        return $out;
    };

    // 1️⃣ Copia sul nodo via SCP
    $run("scp -i {$escKey} -o StrictHostKeyChecking=no {$tmpLocal} {$escNode}:{$remoteTmp}",
         "Errore SCP verso nodo Proxmox");

    // 2️⃣ Push nel container con pct
    $run("ssh -i {$escKey} -o StrictHostKeyChecking=no {$escNode} 'pct push {$vmid} {$remoteTmp} {$remoteFinal}'",
         "Errore pct push nel container");

    // 3️⃣ Rimuovo file temporaneo sul nodo
    $run("ssh -i {$escKey} -o StrictHostKeyChecking=no {$escNode} 'rm -f {$remoteTmp}'",
         "Errore pulizia file temporaneo sul nodo");

    echo json_encode(['success' => true, 'message' => 'Icona aggiornata con successo']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
