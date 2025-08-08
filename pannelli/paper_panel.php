<?php

include("config/config.php");
$_GET['server_id'] = $_GET['id'] ?? null; // Se usi un parametro diverso da server_id
include("auth_check.php");
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
if (!isset($serverType) || $serverType !== 'paper') {
    http_response_code(403);
    die("Accesso negato.");
}
