<?php
require 'auth_check.php';
require 'config/config.php';

header('Content-Type: application/json');

$serverId = intval($_GET['server_id'] ?? 0);
$userId = $_SESSION['user_id'] ?? 0;
$offset = intval($_GET['offset'] ?? 0);

// Verifica che il server appartenga all'utente
$stmt = $pdo->prepare("SELECT proxmox_containerId FROM servers WHERE id=? AND user_id=?");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    echo json_encode(['success' => false, 'message' => 'Accesso negato.']);
    exit;
}

// !!! ADATTA QUESTO PERCORSO !!!
// Questo è un esempio, il percorso reale dipende da come hai montato i dischi del container
$logFilePath = "/server_data/{$server['proxmox_containerId']}/logs/latest.log"; 

if (!file_exists($logFilePath) || !is_readable($logFilePath)) {
    echo json_encode(['success' => false, 'message' => 'File di log non trovato o non leggibile.']);
    exit;
}

$fileSize = filesize($logFilePath);
$logContent = '';

if ($fileSize > $offset) {
    $handle = fopen($logFilePath, 'r');
    fseek($handle, $offset);
    $logContent = fread($handle, $fileSize - $offset);
    fclose($handle);
}

// Semplice conversione dei codici colore ANSI in HTML per un aspetto migliore
$logContent = htmlspecialchars($logContent);
// Puoi aggiungere altre conversioni qui se necessario

echo json_encode([
    'success' => true,
    'log' => nl2br($logContent), // nl2br per mantenere le nuove righe
    'new_size' => $fileSize
]);