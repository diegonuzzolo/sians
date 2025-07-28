<?php
header('Content-Type: application/json');

if (!isset($_GET['server_id'])) {
    echo json_encode(['error' => 'Missing server_id']);
    exit;
}

$serverId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['server_id']);
$logFile = "/home/diego/install_{$serverId}.log";

if (!file_exists($logFile)) {
    echo json_encode(['exists' => false, 'content' => '']);
    exit;
}

$content = file_get_contents($logFile);

echo json_encode([
    'exists' => true,
    'content' => $content
]);
