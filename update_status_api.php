<?php
// File: /var/www/html/update_status_api.php
require 'config/config.php';

$serverId = $_GET['id'] ?? null;
$newStatus = $_GET['status'] ?? null;

if (!$serverId || !$newStatus) {
    http_response_code(400);
    exit("❌ Parametri mancanti");
}

$stmt = $pdo->prepare("UPDATE servers SET status = ?, updated_at = NOW() WHERE id = ?");
$stmt->execute([$newStatus, $serverId]);

echo "✅ Stato aggiornato a $newStatus per server $serverId";
