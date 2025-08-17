<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require __DIR__ . '/config/config.php';

header('Content-Type: application/json');

$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) {
    echo json_encode(['error' => 'Utente non autenticato']);
    exit;
}

// Recupera le subscription attive
$stmt = $pdo->prepare("
    SELECT sub.*, p.code AS plan_code, p.name AS plan_name, p.ram_gb, p.cpu_cores
    FROM subscriptions sub
    JOIN plans p ON p.id = sub.stripe_price_id
    WHERE sub.user_id = ? AND sub.status = 'active'
");
$stmt->execute([$userId]);
$subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recupera server slots dell'utente
$stmt = $pdo->prepare("
    SELECT ss.*, p.code AS plan_code, p.name AS plan_name, p.ram_gb, p.cpu_cores
    FROM server_slots ss
    JOIN plans p ON p.id = ss.plan_id
    WHERE ss.user_id = ?
");
$stmt->execute([$userId]);
$slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'subscriptions' => $subscriptions,
    'slots' => $slots
]);
