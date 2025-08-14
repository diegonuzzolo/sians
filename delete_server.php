<?php
require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php';

$userId = $_SESSION['user_id'] ?? 0;
$vmid = intval($_GET['vmid'] ?? 0);

if ($vmid <= 0) {
    die("VMID non valido.");
}

try {
    // --- 1. Recupera info server e container tramite VMID ---
    $stmt = $pdo->prepare("
        SELECT s.id AS server_id, s.cpu_cores, s.ram_mb, c.storage_gb, s.container_id
        FROM servers s
        JOIN containers c ON s.container_id = c.id
        WHERE s.proxmox_containerId = ? AND s.user_id = ?
    ");
    $stmt->execute([$vmid, $userId]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$server) throw new Exception("Server non trovato.");

    // --- 2. Aggiorna risorse disponibili ---
    $stmt = $pdo->prepare("
        UPDATE resources SET
        used_cpu_cores = used_cpu_cores - ?,
        used_ram_mb = used_ram_mb - ?,
        used_storage_gb = used_storage_gb - ?
        WHERE id = 1
    ");
    $stmt->execute([$server['cpu_cores'], $server['ram_mb'], $server['storage_gb']]);

    // --- 3. Elimina container da Proxmox ---
    $sshKey = "/var/www/.ssh/id_proxmox";
    $nodeIp = "192.168.1.251";

    $delCmd = "sudo ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp "
        . "\"pct stop $vmid && pct destroy $vmid\"";
    exec($delCmd, $output, $status);
    if ($status !== 0) throw new Exception("Errore eliminazione container Proxmox: " . implode("\n",$output));

    // --- 4. Elimina record server e container dal DB ---
    $pdo->prepare("DELETE FROM servers WHERE id = ?")->execute([$server['server_id']]);
    if ($server['container_id']) {
        $pdo->prepare("DELETE FROM containers WHERE id = ?")->execute([$server['container_id']]);
    }

    header("Location: dashboard.php");
    exit;

} catch (Exception $e) {
    die("Errore: " . $e->getMessage());
}
