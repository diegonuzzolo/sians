<?php
include("auth_check.php");
require 'config/config.php';

header('Content-Type: application/json');

// Validazione CSRF e input
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Richiesta non valida.']);
    exit;
}

$serverId = intval($_POST['server_id'] ?? 0);
$command = trim($_POST['command'] ?? '');
$userId = $_SESSION['user_id'] ?? 0;

if (empty($command)) {
    echo json_encode(['success' => false, 'message' => 'Il comando non può essere vuoto.']);
    exit;
}

// Recupera l'ID del container dal DB
$stmt = $pdo->prepare("SELECT c.proxmox_containerId FROM servers s JOIN containers c ON s.container_id = c.id WHERE s.id = ? AND s.user_id = ?");
$stmt->execute([$serverId, $userId]);
$containerId = $stmt->fetchColumn();

if (!$containerId) {
    echo json_encode(['success' => false, 'message' => 'Server non trovato.']);
    exit;
}

// Nome della sessione screen (deve corrispondere a quello in start.sh)
$sessionName = "mc-" . $containerId;

// Comando da eseguire su Proxmox per inviare il comando a screen
// escapeshellarg è FONDAMENTALE per la sicurezza
$proxmoxCommand = sprintf(
    'pct exec %d -- screen -S %s -p 0 -X stuff %s',
    $containerId,
    escapeshellarg($sessionName),
    escapeshellarg($command . "\n") // Aggiungiamo "\n" per simulare l'Invio
);

// Esegui il comando (ATTENZIONE: l'utente del webserver, es. www-data, deve avere i permessi sudo per pct)
$output = shell_exec("sudo " . $proxmoxCommand);

echo json_encode(['success' => true, 'message' => 'Comando inviato.']);