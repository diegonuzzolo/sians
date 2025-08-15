<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include("auth_check.php");
require 'config/config.php';
require_once 'proxmox_functions.php'; // contiene callProxmox()

$userId = $_SESSION['user_id'] ?? 0;

// --- 1. Recupera VMID da GET e valida ---
$vmid = intval($_GET['vmid'] ?? 0);
if ($vmid <= 0) {
    die("VMID non valido.");
}

try {
    // --- 2. Recupera il server e il container corrispondente ---
    $stmt = $pdo->prepare("
        SELECT s.id AS server_id, s.container_id, c.proxmox_containerId,
               c.ram_mb, c.cores, c.storage_gb
        FROM servers s
        LEFT JOIN containers c ON c.id = s.container_id
        WHERE s.user_id = ? AND c.proxmox_containerId = ?
    ");
    $stmt->execute([$userId, $vmid]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$server) {
        throw new Exception("Server non trovato o non appartiene all'utente. VMID: $vmid");
    }

    // --- 3. Restituisci risorse nella tabella resources ---
    $updateRes = $pdo->prepare("
        UPDATE resources SET
            used_cpu_cores    = GREATEST(used_cpu_cores - ?, 0),
            used_ram_mb       = GREATEST(used_ram_mb - ?, 0),
            used_storage_gb   = GREATEST(used_storage_gb - ?, 0)
        WHERE id = 1
    ");
    $updateRes->execute([$server['cores'], $server['ram_mb'], $server['storage_gb']]);

    // --- 4a. Stop container su Proxmox ---
    $stopUrl = "https://192.168.1.251:8006/api2/json/nodes/pve/lxc/$vmid/status/stop";
    $stopResult = callProxmox($stopUrl, 'POST');

    if (!$stopResult['success']) {
        throw new Exception("Errore stop container: " . $stopResult['message']);
    }
    sleep(4);

    // --- 4b. Distruggi container ---
    $destroyUrl = "https://192.168.1.251:8006/api2/json/nodes/pve/lxc/$vmid";
    $destroyResult = callProxmox($destroyUrl, 'DELETE');

    if (!$destroyResult['success']) {
        throw new Exception("Errore distruzione container: " . $destroyResult['message']);
    }

    // --- 5. Elimina record dal database ---
    $stmt = $pdo->prepare("DELETE FROM servers WHERE id = ?");
    $stmt->execute([$server['server_id']]);

    header("Location: dashboard.php?msg=Server eliminato correttamente");
    exit;

} catch (Exception $e) {
    $error = $e->getMessage();
    echo "<div style='color:red;'><strong>Errore:</strong> " . htmlspecialchars($error) . "</div>";
}
