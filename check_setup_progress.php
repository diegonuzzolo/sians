<?php
require 'config/config.php';
require 'includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Non autorizzato']);
    exit;
}

$serverId = $_GET['server_id'] ?? '';
if (!$serverId || !ctype_digit($serverId)) {
    http_response_code(400);
    echo json_encode(['error' => 'ID server non valido']);
    exit;
}

$logFile = "/home/diego/setup_log_{$serverId}.log";
if (!file_exists($logFile)) {
    echo json_encode(['progress' => 0, 'message' => 'Nessun log trovato']);
    exit;
}

// Leggi ultime 20 righe (evita letture troppo pesanti)
$lines = [];
$fp = fopen($logFile, 'r');
if ($fp) {
    fseek($fp, -4096, SEEK_END); // cerca negli ultimi 4k byte
    while (($line = fgets($fp)) !== false) {
        $lines[] = trim($line);
    }
    fclose($fp);
}

// Cerca stringhe di progresso specifiche
$progress = 0;
$message = 'In attesa...';
foreach ($lines as $line) {
    if (strpos($line, 'Scarico Vanilla') !== false) $progress = 20;
    if (strpos($line, 'Installazione modpack') !== false) $progress = 40;
    if (strpos($line, 'Installazione Forge') !== false || strpos($line, 'Installazione NeoForge') !== false) $progress = 60;
    if (strpos($line, 'Scaricamento mod') !== false) $progress = 80;
    if (strpos($line, 'Setup completato') !== false) {
        $progress = 100;
        $message = 'Installazione completata';
        break;
    }
    if ($progress > 0) $message = $line;
}

echo json_encode(['progress' => $progress, 'message' => $message]);
