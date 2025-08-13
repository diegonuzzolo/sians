<?php
require 'auth_check.php';
require 'config/config.php';

header('Content-Type: application/json');

$serverId = intval($_GET['server_id'] ?? 0);

if ($serverId <= 0) {
    echo json_encode(['status' => 'invalid']);
    exit;
}

// Recupera lo stato attuale del server
$stmt = $pdo->prepare("SELECT status FROM servers WHERE id = ?");
$stmt->execute([$serverId]);
$status = $stmt->fetchColumn();

if (!$status) {
    echo json_encode(['status' => 'not_found']);
    exit;
}

echo json_encode(['status' => $status]);
