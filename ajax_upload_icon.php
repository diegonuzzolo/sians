<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'auth_check.php';
require 'config/config.php';

header('Content-Type: application/json');

$serverId = intval($_POST['server_id'] ?? 0);
if ($serverId <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID server non valido']);
    exit;
}

try {
    // Recupero info server dal DB
    $stmt = $pdo->prepare("SELECT node_host, ssh_user, ssh_key_path, vmid FROM servers WHERE id = ?");
    $stmt->execute([$serverId]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$server) {
        throw new Exception("Server non trovato nel database");
    }

    // Parametri connessione SSH
    $nodeHost   = $server['node_host'];
    $sshUser    = $server['ssh_user'] ?: 'root';
    $sshKeyPath = $server['ssh_key_path'] ?: '/var/www/.ssh/id_proxmox';
    $vmid       = intval($server['vmid']);

    if (!file_exists($sshKeyPath)) {
        throw new Exception("Chiave SSH non trovata: {$sshKeyPath}");
    }
    if (!is_readable($sshKeyPath)) {
        throw new Exception("Chiave SSH non leggibile da PHP: {$sshKeyPath}");
    }

    // Funzione helper per eseguire comandi e loggare errori
    $run = function ($cmd, $errMsg) {
        exec($cmd . ' 2>&1', $output, $status);
        if ($status !== 0) {
            throw new Exception($errMsg . " | Codice: {$status} | Output: " . implode("\n", $output));
        }
        return $output;
    };

    // Percorsi temporanei
    $uploadDir = '/tmp';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // Controllo file icona in upload
    if (empty($_FILES['icon']['tmp_name']) || $_FILES['icon']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Upload icona non valido");
    }

    $tmpIconPath = $uploadDir . "/server-icon.png";
    if (!move_uploaded_file($_FILES['icon']['tmp_name'], $tmpIconPath)) {
        throw new Exception("Impossibile salvare file temporaneo icona");
    }

    $escKey     = escapeshellarg($sshKeyPath);
    $escUserHost= escapeshellarg("{$sshUser}@{$nodeHost}");
    $remotePath = "/tmp/server-icon.png";

    // 1️⃣ Copia icona sul nodo Proxmox
    $run("scp -i {$escKey} -o StrictHostKeyChecking=no {$tmpIconPath} {$escUserHost}:{$remotePath}",
        "Errore durante la copia del file sul nodo");

    // 2️⃣ Copia icona nel container LXC
    $run("ssh -i {$escKey} -o StrictHostKeyChecking=no {$escUserHost} 'pct push {$vmid} {$remotePath} /var/lib/minecraft/server-icon.png'",
        "Errore durante il push del file nel container");

    echo json_encode(['success' => true, 'message' => 'Icona aggiornata con successo']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
