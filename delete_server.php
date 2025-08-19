<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include("auth_check.php");
require 'config/config.php';
require_once 'includes/csrf.php';
require_once 'proxmox_functions.php'; // Assicurati che questo file sia incluso

csrf_check_or_die();

$userId = $_SESSION['user_id'] ?? 0;
// Pulisci sempre l'input dell'utente
$vmid = filter_input(INPUT_GET, 'vmid', FILTER_VALIDATE_INT);

if (!$vmid) {
    header('Location: dashboard.php?error=' . urlencode('VMID non valido.'));
    exit;
}

try {
    // --- 1. Recupera i dati del server dal DB per la verifica e per le risorse ---
    $stmt = $pdo->prepare("
        SELECT s.id AS server_id, s.slot_id, s.cpu_cores, c.ram_mb, c.storage_gb
        FROM servers s
        JOIN containers c ON c.id = s.container_id
        WHERE s.user_id = ? AND c.proxmox_containerId = ?
    ");
    $stmt->execute([$userId, $vmid]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$server) {
        throw new Exception("Server non trovato o non ti appartiene (VMID: $vmid).");
    }

    $serverId = $server['server_id'];
    $slotId = $server['slot_id'];
    $usedCpu = (int)$server['cpu_cores'];
    $usedRam = (int)$server['ram_mb'];
    $usedStorage = (int)$server['storage_gb'];

    // --- 2. Esegui le operazioni su Proxmox (fuori dalla transazione DB) ---
    // Stop del container (necessario prima della distruzione)
    $stopUrl = "https://192.168.1.251:8006/api2/json/nodes/pve/lxc/$vmid/status/stop";
    callProxmox($stopUrl, 'POST'); // Ignoriamo l'errore se è già fermo
    sleep(5); // Diamo tempo al container di fermarsi

    // Distruzione del container
    $destroyUrl = "https://192.168.1.251:8006/api2/json/nodes/pve/lxc/$vmid";
    $destroyResult = callProxmox($destroyUrl, 'DELETE');
    if (!$destroyResult['success']) {
        // Se la distruzione fallisce, ci fermiamo prima di modificare il DB
        throw new Exception("Errore durante la distruzione del container su Proxmox: " . $destroyResult['message']);
    }

    // --- 3. Esegui le operazioni sul database in una transazione ---
    $pdo->beginTransaction();

    // Restituisci le risorse al sistema
    $stmtRes = $pdo->prepare("
        UPDATE resources
        SET 
            used_cpu_cores = GREATEST(0, used_cpu_cores - ?), 
            used_ram_mb = GREATEST(0, used_ram_mb - ?), 
            used_storage_gb = GREATEST(0, used_storage_gb - ?)
        WHERE id = 1
    ");
    $stmtRes->execute([$usedCpu, $usedRam, $usedStorage]);
    
    // Libera lo slot rendendolo di nuovo 'pending'
    $stmtSlot = $pdo->prepare("UPDATE server_slots SET server_id = NULL, status = 'pending' WHERE id = ?");
    $stmtSlot->execute([$slotId]);

    // Elimina il record del server (e a cascata il container)
    $stmtDel = $pdo->prepare("DELETE FROM servers WHERE id = ?");
    $stmtDel->execute([$serverId]);
    
    // Se tutto è ok, conferma le modifiche
    $pdo->commit();

    header("Location: dashboard.php?msg=" . urlencode("Server (VMID: $vmid) eliminato correttamente."));
    exit;

} catch (Exception $e) {
    // Se si verifica un errore, annulla la transazione se è attiva
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Reindirizza alla dashboard con un messaggio di errore
    header('Location: dashboard.php?error=' . urlencode($e->getMessage()));
    exit;
}