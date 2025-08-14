<?php
require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php';

$userId = $_SESSION['user_id'] ?? 0;
$serverId = intval($_GET['id'] ?? 0);

if ($serverId <= 0) {
    die("ID server non valido.");
}

try {
    // --- 1. Recupera info server e container ---
    $stmt = $pdo->prepare("
        SELECT cpu_cores, ram_mb, storage_gb, proxmox_containerId, container_id 
        FROM servers 
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$serverId, $userId]);
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
    $vmid = $server['proxmox_containerId'];

    $delCmd = "sudo ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp "
        . "\"pct stop $vmid && pct destroy $vmid\"";
    exec($delCmd, $output, $status);
    if ($status !== 0) throw new Exception("Errore eliminazione container Proxmox: " . implode("\n",$output));

    // --- 4. Elimina record server e container dal DB ---
    $pdo->prepare("DELETE FROM servers WHERE id = ?")->execute([$serverId]);
    if ($server['container_id']) {
        $pdo->prepare("DELETE FROM containers WHERE id = ?")->execute([$server['container_id']]);
    }

    header("Location: dashboard.php");
    exit;

} catch (Exception $e) {
    die("Errore: " . $e->getMessage());
}
