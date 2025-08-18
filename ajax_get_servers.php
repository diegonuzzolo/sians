<?php
require 'config/config.php';
session_start();
header('Content-Type: application/json');

$userId = $_SESSION['user_id'] ?? 0;

try {
    $stmt = $pdo->prepare("
        SELECT s.id, s.name, s.status, s.type, c.ip AS ip, c.ram_mb, c.proxmox_containerId, s.plan_id
        FROM servers s
        LEFT JOIN containers c ON c.id = s.container_id
        WHERE s.user_id=? AND s.status != 'deleted'
    ");
    $stmt->execute([$userId]);
    $servers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($servers);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>