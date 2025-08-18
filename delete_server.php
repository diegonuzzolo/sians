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
    $pdo->beginTransaction();

    // --- Recupera server, slot e risorse ---
    $stmt = $pdo->prepare("
        SELECT s.id AS server_id, s.slot_id, s.cpu_cores, c.ram_mb, c.storage_gb, c.proxmox_containerId
        FROM servers s
        LEFT JOIN containers c ON c.id = s.container_id
        WHERE s.user_id = ? AND c.proxmox_containerId = ?
    ");
    $stmt->execute([$userId, $vmid]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$server) {
        throw new Exception("Server non trovato o non appartiene all'utente. VMID: $vmid");
    }

    $serverId = $server['server_id'];
    $slotId = $server['slot_id'];
    $usedCpu = intval($server['cpu_cores']);
    $usedRam = intval($server['ram_mb']);
    $usedStorage = intval($server['storage_gb']);

    // --- Stop container su Proxmox ---
    $stopUrl = "https://192.168.1.251:8006/api2/json/nodes/pve/lxc/$vmid/status/stop";
    $stopResult = callProxmox($stopUrl, 'POST');
    if (!$stopResult['success']) {
        throw new Exception("Errore durante lo stop del container: " . $stopResult['message']);
    }
    sleep(4);

    // --- Distruggi container su Proxmox ---
    $destroyUrl = "https://192.168.1.251:8006/api2/json/nodes/pve/lxc/$vmid";
    $destroyResult = callProxmox($destroyUrl, 'DELETE');
    if (!$destroyResult['success']) {
        throw new Exception("Errore durante la distruzione del container: " . $destroyResult['message']);
    }

    // --- Aggiorna tabella resources ---
    $stmt = $pdo->prepare("
        UPDATE resources
        SET 
            used_cpu_cores = used_cpu_cores - ?, 
            used_ram_mb = used_ram_mb - ?, 
            used_storage_gb = used_storage_gb - ?
        WHERE id = 1
    ");
    $stmt->execute([$usedCpu, $usedRam, $usedStorage]);
    
    // --- Libera lo slot nella tabella server_slots ---
    $stmt = $pdo->prepare("UPDATE server_slots SET server_id=NULL, status='active' WHERE id = ?");
    $stmt->execute([$slotId]);

    // --- Elimina record del server e del container dal DB ---
    $stmt = $pdo->prepare("DELETE FROM servers WHERE id = ?");
    $stmt->execute([$serverId]);
    
    $stmt = $pdo->prepare("DELETE FROM containers WHERE proxmox_containerId = ?");
    $stmt->execute([$vmid]);

    $pdo->commit();

    header("Location: dashboard.php?msg=Server eliminato correttamente");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $error = $e->getMessage();
    echo "<div style='color:red;'><strong>Errore:</strong> " . htmlspecialchars($error) . "</div>";
}
?>