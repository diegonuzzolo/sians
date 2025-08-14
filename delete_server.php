<?php
include("auth_check.php");
require 'config/config.php';
require 'proxmox_functions.php'; // qui metti le funzioni per chiamare Proxmox API

$userId = $_SESSION['user_id'] ?? 0;
$vmid = intval($_GET['vmid'] ?? 0);

if ($vmid <= 0) {
    die("VMID non valido.");
}

// Recupera il server corrispondente e il container associato
$stmt = $pdo->prepare("
    SELECT s.id, s.name, s.status, s.type, s.progress,
           c.ip AS private_ip, c.ram_mb, c.proxmox_containerId
    FROM servers s
    LEFT JOIN containers c ON c.id = s.container_id
    WHERE s.user_id = ?
");

$stmt->execute([$userId, $vmid]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    die("Server non trovato o non appartieni all'utente.");
}

// 1️⃣ Elimina container su Proxmox
$deleteResult = deleteProxmoxContainer($vmid); // funzione che chiama API Proxmox
if (!$deleteResult['success']) {
    die("Errore eliminazione container su Proxmox: " . $deleteResult['message']);
}

// 2️⃣ Elimina server e container dal DB (trigger/ON DELETE CASCADE si occupa di containers)
$stmtDel = $pdo->prepare("DELETE FROM servers WHERE id = ?");
$stmtDel->execute([$server['server_id']]);

header("Location: dashboard.php?msg=server_eliminato");
exit;
?>
