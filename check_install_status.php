<?php
require 'auth_check.php';
require 'config/config.php';

header('Content-Type: application/json');

$serverId = intval($_GET['server_id'] ?? 0);
if ($serverId <= 0) {
    echo json_encode(['error' => 'Server ID non valido']);
    exit;
}

$stmt = $pdo->prepare("SELECT status FROM servers WHERE id = ?");
$stmt->execute([$serverId]);
$status = $stmt->fetchColumn();

if (!$status) {
    echo json_encode(['error' => 'Server non trovato']);
    exit;
}

// Considera "ready" lo stato quando il container è creato e l’installazione completata
// Per esempio se usi status = "running" o "installed" come segnale di completamento
$readyStates = ['running', 'installed', 'ready'];

echo json_encode([
    'status' => in_array($status, $readyStates) ? 'ready' : $status
]);
