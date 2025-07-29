<?php
require 'config/config.php';
require 'includes/auth.php';

header('Content-Type: application/json');

$userId = $_SESSION['user_id'] ?? 0;

$stmt = $pdo->prepare("SELECT id, name, progress, status FROM servers WHERE user_id = ?");
$stmt->execute([$userId]);
$servers = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($servers);
?>