<?php
include 'auth_check.php';
require 'config/config.php';

$userId = $_SESSION['user_id'] ?? 0;

// Recupera tutte le subscription attive dell'utente
$stmt = $pdo->prepare("
    SELECT sub.*, p.code AS plan_code
    FROM subscriptions sub
    JOIN plans p ON p.id = sub.plan_id
    WHERE sub.user_id = ? AND sub.status='active'
");
$stmt->execute([$userId]);
$subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recupera i server attivi per ogni piano
$stmt = $pdo->prepare("
    SELECT plan_id, COUNT(*) AS server_count
    FROM servers
    WHERE user_id = ?
    GROUP BY plan_id
");
$stmt->execute([$userId]);
$serversPerPlan = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Calcola slot disponibili per ogni piano
$slotsAvailable = [];
foreach ($subscriptions as $sub) {
    $maxSlots = 1; // ogni piano permette 1 server, modifica se vuoi più slot
    $usedSlots = $serversPerPlan[$sub['plan_id']] ?? 0;
    $slotsAvailable[$sub['plan_code']] = max(0, $maxSlots - $usedSlots);
}

header('Content-Type: application/json');
echo json_encode($slotsAvailable);
