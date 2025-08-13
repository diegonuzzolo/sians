<?php
require 'auth_check.php';
require 'config/config.php';

$serverId = intval($_GET['server_id'] ?? 0);
if ($serverId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Server ID non valido']);
    exit;
}

$stmt = $pdo->prepare("SELECT status FROM servers WHERE id = ?");
$stmt->execute([$serverId]);
$status = $stmt->fetchColumn();

if ($status === false) {
    echo json_encode(['status' => 'error', 'message' => 'Server non trovato']);
    exit;
}

// Restituisce lo stato corrente
header('Content-Type: application/json');
echo json_encode(['status' => $status]);
