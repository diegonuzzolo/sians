<?php
if (!isset($_GET['server_id'])) {
    echo json_encode(['error' => 'Missing server_id']);
    exit;
}

$serverId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['server_id']);
$lockFile = "/home/diego/installing_{$serverId}.lock";

echo json_encode([
    'installing' => file_exists($lockFile)
]);
