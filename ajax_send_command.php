<?php
require 'auth_check.php';
require 'config/config.php';
require 'includes/csrf.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Metodo non consentito.']);
    exit;
}

csrf_check_or_die();

$serverId = intval($_POST['server_id'] ?? 0);
$userId = $_SESSION['user_id'] ?? 0;
$command = trim($_POST['command'] ?? '');

// Verifica che il server appartenga all'utente
$stmt = $pdo->prepare("SELECT proxmox_containerId FROM servers WHERE id=? AND user_id=?");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server || empty($command)) {
    echo json_encode(['success' => false, 'message' => 'Dati non validi o accesso negato.']);
    exit;
}

$containerId = $server['proxmox_containerId'];

// !!! ADATTA QUESTO COMANDO !!!
// Questo è un esempio che presuppone che il server Minecraft giri in una sessione 'screen'
// chiamata 'minecraft_server' dentro al container.
// Avrai bisogno dei permessi sudo per eseguire comandi pct.
$fullCommand = "sudo pct exec {$containerId} -- screen -S minecraft_server -p 0 -X stuff \"{$command}\\n\"";

// Esegui il comando (assicurati che l'utente www-data abbia i permessi necessari in /etc/sudoers)
exec($fullCommand, $output, $return_var);

if ($return_var === 0) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Impossibile eseguire il comando sul server.']);
}