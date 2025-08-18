<?php
session_start();
header('Content-Type: application/json');
include('auth_check.php');
require 'config/config.php';

$userId = $_SESSION['user_id'] ?? 0;
if ($userId == 0) {
    echo json_encode(['success' => false, 'message' => 'Utente non autenticato']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT ss.id, ss.status, p.name AS plan_name, p.price_eur, p.code AS plan_code
        FROM server_slots ss
        LEFT JOIN plans p ON p.stripe_price_id = ss.plan_id
        WHERE ss.user_id = ? AND ss.server_id IS NULL AND ss.status = 'pending'
    ");
    $stmt->execute([$userId]);
    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'slots' => $slots]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Errore nel recupero degli slot: ' . $e->getMessage()]);
}
