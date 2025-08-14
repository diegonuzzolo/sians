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
        SELECT s.id AS server_id, s.container_id, c.proxmox_containerId
        FROM servers s
        LEFT JOIN containers c ON c.id = s.container_id
        WHERE s.user_id = ? AND c.proxmox_containerId = ?
    ");
    $stmt->execute([$userId, $vmid]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$server) {
        throw new Exception("Server non trovato o non appartiene all'utente. VMID: $vmid");
    }

    // --- 3. Elimina container su Proxmox ---
    $url = "https://192.168.1.251:8006/api2/json/nodes/pve/lxc/$vmid";
    $deleteResult = callProxmox($url, 'DELETE');

    if (!$deleteResult['success']) {
        throw new Exception("Errore eliminazione container su Proxmox: " . $deleteResult['message']);
    }

    // --- 4. Elimina record dal database (ON DELETE CASCADE rimuove container se impostato) ---
    $stmt = $pdo->prepare("DELETE FROM servers WHERE id = ?");
    $stmt->execute([$server['server_id']]);

    header("Location: dashboard.php?msg=Server eliminato correttamente");
    exit;

} catch (Exception $e) {
    $error = $e->getMessage();
    echo "<div style='color:red;'><strong>Errore:</strong> " . htmlspecialchars($error) . "</div>";
}
