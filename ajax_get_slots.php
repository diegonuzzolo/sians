<?php
require 'config/config.php';
session_start();
header('Content-Type: application/json');

$userId = $_SESSION['user_id'] ?? 0;

try {
    $stmtSlots = $pdo->prepare("
        SELECT ss.id, ss.status, p.name, p.price_eur, p.code as plan_code
        FROM server_slots ss
        JOIN plans p ON p.stripe_price_id = ss.plan_id
        WHERE ss.user_id = ? AND ss.server_id IS NULL AND ss.status = 'active'
    ");
    $stmtSlots->execute([$userId]);
    $serverSlots = $stmtSlots->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($serverSlots);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>