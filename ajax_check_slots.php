<?php
include 'auth_check.php';
require 'config/config.php';

$userId = $_SESSION['user_id'] ?? 0;

// Recupera tutte le subscription attive dell'utente
$stmt = $pdo->prepare("
    SELECT sub.*, p.code AS plan_code, sub.stripe_price_id AS plan_id
    FROM subscriptions sub
    JOIN plans p ON p.id = sub.stripe_price_id
    WHERE sub.stripe_customer_id = ? AND sub.status='active'
");
$stmt->execute([$stripeCustomerId]);
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

    $maxSlots = 1; // almeno 1 slot per ogni subscription attiva
    $usedSlots = $serversPerPlan[$planId] ?? 0;

    $slotsAvailable[$planCode] = max(0, $maxSlots - $usedSlots);
}

header('Content-Type: application/json');
echo json_encode($slotsAvailable);
