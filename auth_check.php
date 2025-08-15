<?php
// auth_check.php
include(__DIR__ . "/config/config.php");
// require_once 'includes/csrf.php';


session_start();

// ⚡ Pagine pubbliche (no redirect o CSRF)
$publicPages = ['login.php', 'register.php'];
$currentPage = basename($_SERVER['PHP_SELF']);

if (!in_array($currentPage, $publicPages)) {
    // CSRF solo sulle pagine protette
    csrf_check_or_die();

    // 1️⃣ Controllo login
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit;
    }

    // 2️⃣ Controllo scadenza password (90 giorni)
    $stmt = $pdo->prepare("SELECT last_password_change FROM users WHERE id=?");
    $stmt->execute([$_SESSION['user_id']]);
    $lastChange = $stmt->fetchColumn();

    if ($lastChange && (new DateTime())->diff(new DateTime($lastChange))->days > 90) {
        header("Location: change_password.php?expired=1");
        exit;
    }

    // 3️⃣ Controllo proprietà server (se server_id passato)
    $serverId = intval($_GET['server_id'] ?? $_POST['server_id'] ?? 0);
    if ($serverId > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM servers WHERE id = ? AND user_id = ?");
        $stmt->execute([$serverId, $_SESSION['user_id']]);
        if ($stmt->fetchColumn() == 0) {
            http_response_code(403);
            exit("Accesso negato: questo server non ti appartiene.");
        }
    }
}
