<?php

$_GET['server_id'] = $_GET['id'] ?? null; // Se usi un parametro diverso da server_id
include("auth_check.php");


if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require 'includes/auth.php';

header('Content-Type: application/json');

$userId = $_SESSION['user_id'] ?? 0;

$stmt = $pdo->prepare("SELECT id, name, progress, status FROM servers WHERE user_id = ?");
$stmt->execute([$userId]);
$servers = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($servers);
?>