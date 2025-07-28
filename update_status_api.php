<?php
require 'config/config.php';

$serverId = $_GET['id'] ?? null;
$newStatus = $_GET['status'] ?? null;
$token = $_GET['token'] ?? '';

$expectedToken = 'ILgaTToiDellaLorenza87rhIUNonSaVolare'; // 🔐 Inserisci qui un token forte

if (!$serverId || !$newStatus || $token !== $expectedToken) {
    http_response_code(403);
    exit("❌ Accesso negato o parametri mancanti.");
}

$stmt = $pdo->prepare("UPDATE servers SET status = ?, updated_at = NOW() WHERE id = ?");
$stmt->execute([$newStatus, $serverId]);

echo "✅ Stato aggiornato a $newStatus per server $serverId";
