<?php
// auth_check.php
include(__DIR__ . "/config/config.php");
include 'includes/csrf.php';

session_start();
csrf_check_or_die();
// 1️⃣ Controllo login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$stmt = $pdo->prepare("SELECT last_password_change FROM users WHERE id=?");
$stmt->execute([$_SESSION['user_id']]);
$lastChange = $stmt->fetchColumn();

if ($lastChange && (new DateTime())->diff(new DateTime($lastChange))->days > 90) {
    header("Location: change_password.php?expired=1");
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
