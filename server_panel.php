<?php
session_start();
$_GET['server_id'] = $_GET['id'] ?? null; // Se usi un parametro diverso da server_id
include("auth_check.php");

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}


$serverId = $_GET['id']; // o come recuperi l'ID
$stmt = $pdo->prepare("SELECT type FROM servers WHERE id = ?");
$stmt->execute([$serverId]);
$server = $stmt->fetch();

if (!$server) {
    die("Server non trovato.");
}

$serverType = strtolower($server['type']); // normalizziamo


switch ($serverType) {
    case 'vanilla':
        include 'pannelli/vanilla_panel.php';
        break;

    case 'modpack':
        include 'pannelli/modpack_panel.php';
        break;

    case 'paper':
    case 'bukkit': // se vuoi gestire entrambi qui
        include 'pannelli/plugin_panel.php';
        break;

    default:
        echo "<p>Tipo di server non supportato: " . htmlspecialchars($serverType) . "</p>";
}

?>
