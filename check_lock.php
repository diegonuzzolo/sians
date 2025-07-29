<?php
require 'config/config.php';
require 'includes/auth.php';

if (!isset($_GET['server_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'ID mancante']);
    exit;
}

$serverId = intval($_GET['server_id']);

$stmt = $pdo->prepare("SELECT status, progress FROM servers WHERE id = ?");
$stmt->execute([$serverId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if ($server) {
    echo json_encode([
        'status' => $server['status'],
        'progress' => $server['progress']
    ]);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Server non trovato']);
}
