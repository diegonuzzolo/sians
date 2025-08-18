<?php
session_start();
require 'auth_check.php';
require 'config/config.php';

header('Content-Type: application/json');

$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) {
    echo json_encode(['success' => false, 'message' => 'Accesso negato']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT ss.id, ss.status, ss.plan_id, p.name, p.price_eur, p.code AS plan_code
    FROM server_slots ss
    JOIN plans p ON p.stripe_price_id = ss.plan_id
    WHERE ss.user_id = ? AND ss.server_id IS NULL AND ss.status = 'pending'
");
$stmt->execute([$userId]);
$slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['success' => true, 'slots' => $slots]);
