<?php
session_start();
require 'config/config.php';

$userId = $_SESSION['user_id'] ?? 0;

// Recupera tutte le subscription attive
$stmt = $pdo->prepare("
    SELECT p.code AS plan_code, sub.total, sub.used
    FROM subscriptions sub
    JOIN plans p ON p.id = sub.plan_id
    WHERE sub.user_id = ? AND sub.status='active'
");
$stmt->execute([$userId]);
$subs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$slots = [];
foreach ($subs as $sub) {
    $slots[$sub['plan_code']] = max(0, $sub['total'] - $sub['used']);
}

header('Content-Type: application/json');
echo json_encode($slots);
