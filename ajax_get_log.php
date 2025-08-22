<?php
include("auth_check.php");
require 'config/config.php';

header('Content-Type: application/json');

$serverId = intval($_GET['id'] ?? 0);
$lastSize = intval($_GET['lastSize'] ?? 0);
$userId = $_SESSION['user_id'] ?? 0;

// Recupera l'ID del container
$stmt = $pdo->prepare("SELECT c.proxmox_containerId FROM servers s JOIN containers c ON s.container_id = c.id WHERE s.id = ? AND s.user_id = ?");
$stmt->execute([$serverId, $userId]);
$containerId = $stmt->fetchColumn();

if (!$containerId) {
    echo json_encode(['success' => false, 'content' => 'Server non trovato.', 'newSize' => 0]);
    exit;
}

// Il percorso del file di log *sul server che ospita il PHP*, non dentro il container
$logFile = "/var/lib/vz/private/{$containerId}/root/minecraft_bedrock/server.log";

if (!file_exists($logFile)) {
    echo json_encode(['success' => false, 'content' => "Log file non trovato: {$logFile}", 'newSize' => 0]);
    exit;
}

// Long polling: controlla per 20 secondi se il file cambia
set_time_limit(30);
$startTime = time();
while (time() - $startTime < 20) {
    clearstatcache(); // Pulisce la cache delle info sui file
    $currentSize = filesize($logFile);

    if ($currentSize > $lastSize) {
        $handle = fopen($logFile, 'r');
        fseek($handle, $lastSize);
        $newContent = fread($handle, $currentSize - $lastSize);
        fclose($handle);

        echo json_encode(['success' => true, 'content' => $newContent, 'newSize' => $currentSize]);
        exit;
    }

    usleep(250000); // Aspetta 0.25 secondi prima di ricontrollare
}

// Se non ci sono novità dopo 20 secondi, rispondi vuoto
echo json_encode(['success' => true, 'content' => '', 'newSize' => $lastSize]);