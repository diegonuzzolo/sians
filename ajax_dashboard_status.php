<?php
require 'config/config.php';
session_start();

$userId = $_SESSION['user_id'] ?? 0;

$response = [];

// Servers attivi
$stmt = $pdo->prepare("
    SELECT s.id, s.name, s.status, s.type, c.ip, c.ram_mb, c.proxmox_containerId
    FROM servers s
    LEFT JOIN containers c ON c.id = s.container_id
    WHERE s.user_id=? AND s.status != 'deleted'
");
$stmt->execute([$userId]);
$response['servers'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Slot acquistati ma non ancora usati
$stmtSlots = $pdo->prepare("
    SELECT ss.id, ss.status, p.name, p.price_eur, p.code as plan_code
    FROM server_slots ss
    JOIN plans p ON p.stripe_price_id = ss.plan_id
    WHERE ss.user_id = ? AND ss.status IN ('pending','active') AND ss.server_id IS NULL
");
$stmtSlots->execute([$userId]);
$response['slots'] = $stmtSlots->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($response);
