<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include("auth_check.php");
require 'config/config.php';
require_once 'proxmox_functions.php';

$userId = $_SESSION['user_id'] ?? 0;
$vmid = intval($_GET['vmid'] ?? 0);

if ($vmid <= 0) {
    die("VMID non valido.");
}

try {
    // --- Recupera server e container ---
    $stmt = $pdo->prepare("
        SELECT s.id AS server_id, s.container_id, s.cpu_cores, c.ram_mb, c.storage_gb, c.proxmox_containerId
        FROM servers s
        LEFT JOIN containers c ON c.id = s.container_id
        WHERE s.user_id = ? AND c.proxmox_containerId = ?
    ");
    $stmt->execute([$userId, $vmid]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$server) {
        throw new Exception("Server non trovato o non appartiene all'utente. VMID: $vmid");
    }

    $usedCpu = intval($server['cpu_cores']);
    $usedRam = intval($server['ram_mb']);
    $usedStorage = intval($server['storage_gb']);

    // --- Stop container su Proxmox ---
    $stopUrl = "https://192.168.1.251:8006/api2/json/nodes/pve/lxc/$vmid/status/stop";
    $stopResult = callProxmox($stopUrl, 'POST');
    sleep(4);

    // --- Distruggi container ---
    $destroyUrl = "https://192.168.1.251:8006/api2/json/nodes/pve/lxc/$vmid";
    $destroyResult = callProxmox($destroyUrl, 'DELETE');

    // --- Restituisci risorse ---
    $stmt = $pdo->prepare("
        UPDATE resources 
        SET used_ram_mb = used_ram_mb - ?, 
            used_storage_gb = used_storage_gb - ?, 
            used_cpu_cores = used_cpu_cores - ?
        WHERE id = 1
    ");
    $stmt->execute([$usedRam, $usedStorage, $usedCpu]);

    // --- Elimina record server dal DB ---
    $stmt = $pdo->prepare("DELETE FROM servers WHERE id = ?");
    $stmt->execute([$server['server_id']]);

    header("Location: dashboard.php?msg=Server eliminato correttamente");
    exit;

} catch (Exception $e) {
    $error = $e->getMessage();
    echo "<div style='color:red;'><strong>Errore:</strong> " . htmlspecialchars($error) . "</div>";
}
