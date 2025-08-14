<?php
require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php';

$userId = $_SESSION['user_id'] ?? 0;
$serverId = intval($_GET['id'] ?? 0);

if ($serverId <= 0) {
    die("Server ID non valido.");
}

try {
    // --- Recupera dati server e container ---
    $stmt = $pdo->prepare("
        SELECT s.cpu_cores, s.ram_mb, s.proxmox_containerId, s.container_id
        FROM servers s
        WHERE s.id = ? AND s.user_id = ?
    ");
    $stmt->execute([$serverId, $userId]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$server) {
        throw new Exception("Server non trovato o non autorizzato.");
    }

    // --- Rilascia risorse ---
    $stmt = $pdo->prepare("
        UPDATE resources SET
            used_cpu_cores = used_cpu_cores - ?,
            used_ram_mb = used_ram_mb - ?,
            used_storage_gb = used_storage_gb - ?
        WHERE id = 1
    ");
    $stmt->execute([
        $server['cpu_cores'],
        $server['ram_mb'],
        0 // storage_gb non è salvato in servers
    ]);

    // --- Elimina container da Proxmox ---
    $vmid = $server['proxmox_containerId'];
    if ($vmid) {
        $sshKey = "/var/www/.ssh/id_proxmox";
        $nodeIp = "192.168.1.251";
        $delCmd = "sudo ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp "
                . "\"pct stop $vmid && pct destroy $vmid\"";
        exec($delCmd, $output, $status);
        if ($status !== 0) {
            throw new Exception("Errore eliminazione container Proxmox: " . implode("\n", $output));
        }
    }

    // --- Elimina il server dal DB ---
    $stmt = $pdo->prepare("DELETE FROM servers WHERE id = ?");
    $stmt->execute([$serverId]);

    // --- Il container collegato verrà eliminato automaticamente dal DB grazie a ON DELETE CASCADE ---

    header("Location: dashboard.php");
    exit;

} catch (Exception $e) {
    echo "<div class='alert alert-danger'>Errore: " . htmlspecialchars($e->getMessage()) . "</div>";
}
