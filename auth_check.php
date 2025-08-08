<?php
// auth_check.php
include(__DIR__ . "/config/config.php");
session_start();

// 1️⃣ Controllo login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// 2️⃣ Controllo proprietà server (solo se serve)
//    Lo usi passando l'ID del server nella query string o in POST
if (isset($_GET['server_id']) || isset($_POST['server_id'])) {
    $serverId = intval($_GET['server_id'] ?? $_POST['server_id']);
    $userId   = $_SESSION['user_id'];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM servers WHERE id = ? AND user_id = ?");
    $stmt->execute([$serverId, $userId]);

    if ($stmt->fetchColumn() == 0) {
        http_response_code(403);
        exit("Accesso negato: questo server non ti appartiene.");
    }
}
