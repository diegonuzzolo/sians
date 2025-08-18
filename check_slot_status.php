<?php
require 'auth_check.php';
require 'config/config.php';

header('Content-Type: application/json');

$userId = $_SESSION['user_id'] ?? 0;
$slotId = $_GET['slot_id'] ?? null;

if (!$userId || !$slotId) {
    echo json_encode(['success' => false, 'message' => 'Parametri non validi.']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT status FROM server_slots WHERE id = ? AND user_id = ?");
    $stmt->execute([$slotId, $userId]);
    $slot = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($slot) {
        echo json_encode(['success' => true, 'status' => $slot['status']]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Slot non trovato.']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

exit;
?>