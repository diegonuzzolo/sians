<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include 'auth_check.php';
require 'config/config.php';

header('Content-Type: application/json');

$userId = $_SESSION['user_id'] ?? 0;

if (!$userId) {
    echo json_encode([]);
    exit;
}

// --- Recupera tutte le subscription attive dell'utente ---
$stmt = $pdo->prepare("
    SELECT sub.*, p.code AS plan_code
    FROM subscriptions sub
    JOIN plans p ON p.id = sub.plan_id
    WHERE sub.user_id = ? AND sub.status='active'
");
$stmt->execute([$userId]);
$subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Recupera numero di server attivi per piano ---
$stmt = $pdo->prepare("
    SELECT plan_id, COUNT(*) AS server_count
    FROM servers
    WHERE user_id = ? AND status != 'deleted'
    GROUP BY plan_id
");
$stmt->execute([$userId]);
$serversPerPlan = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// --- Calcola slot disponibili per ogni piano ---
$slotsAvailable = [];
foreach ($subscriptions as $sub) {
    $planId = $sub['plan_id'];
    $planCode = $sub['plan_code'];

    $maxSlots = 1; // Default 1 slot per subscription attiva
    $usedSlots = $serversPerPlan[$planId] ?? 0;

    $slotsAvailable[$planCode] = max(0, $maxSlots - $usedSlots);
}

// Restituisce JSON
echo json_encode($slotsAvailable);
