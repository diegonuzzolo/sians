<?php
include 'auth_check.php';
require 'config/config.php';

$userId = $_SESSION['user_id'] ?? 0;

// Recupera tutti gli slot attivi dell'utente dalla tabella server_slots
$stmt = $pdo->prepare("
    SELECT ss.*, p.code AS plan_code
    FROM server_slots ss
    JOIN plans p ON p.id = ss.plan_id
    WHERE ss.user_id = ? AND ss.status='active'
");
$stmt->execute([$userId]);
$slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recupera i server attivi per ogni piano
$stmt = $pdo->prepare("
    SELECT plan_id, COUNT(*) AS server_count
    FROM servers
    WHERE user_id = ? AND status != 'deleted'
    GROUP BY plan_id
");
$stmt->execute([$userId]);
$serversPerPlan = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Calcola slot disponibili per ogni piano
$slotsAvailable = [];
foreach ($slots as $slot) {
    $planId = $slot['plan_id'];
    $planCode = $slot['plan_code'];

    $maxSlots = $slot['slots'] ?? 1; // considera la colonna slots della tabella server_slots
    $usedSlots = $serversPerPlan[$planId] ?? 0;

    $slotsAvailable[$planCode] = max(0, $maxSlots - $usedSlots);
}

header('Content-Type: application/json');
echo json_encode($slotsAvailable);
