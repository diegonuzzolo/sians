<?php
include 'auth_check.php';
require 'config/config.php';

header('Content-Type: application/json');

$userId = $_SESSION['user_id'] ?? 0;

// Recupera tutti gli slot dell'utente
$stmt = $pdo->prepare("
    SELECT plan_id, status, COUNT(*) AS total
    FROM server_slots
    WHERE user_id = ?
    GROUP BY plan_id, status
");
$stmt->execute([$userId]);
$slotsData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepara array finale slot disponibili
$slotsAvailable = [];

foreach ($slotsData as $row) {
    $planId = $row['plan_id'];
    $status = $row['status'];
    $count = intval($row['total']);

    if (!isset($slotsAvailable[$planId])) $slotsAvailable[$planId] = 0;

    if ($status === 'pending') {
        $slotsAvailable[$planId] += $count; // slot ancora disponibili
    }
}

// Ritorna JSON: plan_id => slot disponibili
echo json_encode($slotsAvailable);
