<?php
include 'auth_check.php';
require 'config/config.php';

$userId = $_SESSION['user_id'] ?? 0;

if (!$userId) {
    echo json_encode([]);
    exit;
}

// Recupera tutte le subscription attive dell'utente
$stmt = $pdo->prepare("
    SELECT sub.*, p.code AS plan_code, p.name AS plan_name, p.ram_gb, p.cpu_cores
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
    WHERE user_id = ? AND status != 'deleted'
    GROUP BY plan_id
");
$stmt->execute([$userId]);
$serversPerPlan = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Calcola slot disponibili per ogni piano
$slotsAvailable = [];
foreach ($subscriptions as $sub) {
    $planId = $sub['plan_id'];
    $planCode = $sub['plan_code'];

    // Imposta max slots per piano (qui 1 per subscription, puoi personalizzare)
    $maxSlots = 1;
    $usedSlots = $serversPerPlan[$planId] ?? 0;

    $slotsAvailable[$planCode] = max(0, $maxSlots - $usedSlots);
}

header('Content-Type: application/json');
echo json_encode($slotsAvailable);
