<?php
require 'auth_check.php';
require 'config/config.php';

$userId = $_SESSION['user_id'] ?? 0;
$result = [];

// Recupera tutte le subscription
$stmt = $pdo->prepare("
    SELECT sub.*, p.code AS plan_code
    FROM subscriptions sub
    JOIN plans p ON p.id = sub.plan_id
    WHERE sub.user_id = ?
");
$stmt->execute([$userId]);
$subs = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($subs as $sub) {
    $used = (int)$sub['used'];      // server attivi
    $total = (int)$sub['total'];    // slot totali
    $result[$sub['plan_code']] = $total - $used;
}

header('Content-Type: application/json');
echo json_encode($result);
