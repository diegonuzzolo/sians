<?php
// auth_check.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include(__DIR__ . "/config/config.php");

// ⚡ Pagine pubbliche (no redirect o CSRF)
$publicPages = ['login.php', 'register.php'];
$currentPage = basename($_SERVER['PHP_SELF']);

// Rileva se è una chiamata AJAX
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if (!in_array($currentPage, $publicPages)) {
    // CSRF solo sulle pagine protette

    // 1️⃣ Controllo login
    if (!isset($_SESSION['user_id'])) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                "status" => "error",
                "message" => "Accesso negato. Effettua il login."
            ]);
            exit;
        } else {
            header("Location: login.php");
            exit;
        }
    }

    // 2️⃣ Controllo scadenza password (90 giorni)
    $stmt = $pdo->prepare("SELECT last_password_change FROM users WHERE id=?");
    $stmt->execute([$_SESSION['user_id']]);
    $lastChange = $stmt->fetchColumn();

    if ($lastChange && (new DateTime())->diff(new DateTime($lastChange))->days > 90) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                "status" => "error",
                "message" => "Password scaduta. Aggiornala per continuare."
            ]);
            exit;
        } else {
            header("Location: change_password.php?expired=1");
            exit;
        }
    }

    // 3️⃣ Controllo proprietà server (se server_id passato)
    $serverId = intval($_GET['server_id'] ?? $_POST['server_id'] ?? 0);
    if ($serverId > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM servers WHERE id = ? AND user_id = ?");
        $stmt->execute([$serverId, $_SESSION['user_id']]);
        if ($stmt->fetchColumn() == 0) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    "status" => "error",
                    "message" => "Accesso negato: questo server non ti appartiene."
                ]);
                exit;
            } else {
                http_response_code(403);
                exit("Accesso negato: questo server non ti appartiene.");
            }
        }
    }
}
