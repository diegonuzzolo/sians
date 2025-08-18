<?php
// ajax_check_slots.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include 'auth_check.php';
require 'config/config.php';

header('Content-Type: application/json');

$userId = $_SESSION['user_id'] ?? 0;

// Recupera tutti gli slot attivi dell'utente
$stmt = $pdo->prepare("
    SELECT ss.*, p.code AS plan_code, p.name AS plan_name
    FROM server_slots ss
    JOIN plans p ON p.id = ss.plan_id
    WHERE ss.user_id = ? AND ss.status = 'active'
");
$stmt->execute([$userId]);
$slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recupera i server attivi dell'utente
$stmt = $pdo->prepare("
    SELECT plan_id, COUNT(*) AS server_count
    FROM servers
    WHERE user_id = ? AND status != 'deleted'
    GROUP BY plan_id
");
$stmt->execute([$userId]);
$serversPerPlan = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Costruisce array slot disponibili per piano
$slotsAvailable = [];
foreach ($slots as $slot) {
    $planId = $slot['plan_id'];
    $planCode = $slot['plan_code'];
    $planName = $slot['plan_name'];

    $maxSlots = $slot['slots'] ?? 1; // Numero totale di slot del piano
    $usedSlots = $serversPerPlan[$planId] ?? 0;

    $available = max(0, $maxSlots - $usedSlots);

    if ($available > 0) {
        $slotsAvailable[$planCode] = [
            'plan_name' => $planName,
            'available' => $available
        ];
    }
}

echo json_encode($slotsAvailable);
