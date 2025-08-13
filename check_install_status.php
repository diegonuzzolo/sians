<?php
require 'auth_check.php';
require 'config/config.php';

header('Content-Type: application/json');

$serverId = intval($_GET['server_id'] ?? 0);
if ($serverId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Server ID non valido']);
    exit;
}

// Recupera lo stato attuale dal database
$stmt = $pdo->prepare("SELECT status FROM servers WHERE id = ?");
$stmt->execute([$serverId]);
$status = $stmt->fetchColumn();

if (!$status) {
    echo json_encode(['status' => 'error', 'message' => 'Server non trovato']);
    exit;
}

// Mappa lo stato database allo stato lato frontend
switch ($status) {
    case 'new':
        $frontendStatus = 'preparing';
        break;
    case 'ready_to_install':
        $frontendStatus = 'ready_to_install';
        break;
    case 'installing':
        $frontendStatus = 'installing';
        break;
    case 'ready':
        $frontendStatus = 'ready';
        break;
    default:
        $frontendStatus = 'unknown';
}

echo json_encode(['status' => $frontendStatus]);
