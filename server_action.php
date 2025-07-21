<?php
require 'config/config.php';
require 'includes/auth.php';

$serverId = $_POST['server_id'] ?? null;
$action = $_POST['action'] ?? null;

if (!$serverId || !$action) {
    http_response_code(400);
    echo "Richiesta non valida";
    exit;
}

// Verifica che il server appartenga all'utente
$stmt = $pdo->prepare("SELECT * FROM servers WHERE id = ? AND user_id = ?");
$stmt->execute([$serverId, $_SESSION['user_id']]);
$server = $stmt->fetch();

if (!$server) {
    http_response_code(403);
    echo "Accesso negato";
    exit;
}

// Logica fittizia per accensione/spegnimento
if ($action === 'start') {
    // Qui inserisci il comando per accendere la VM su Proxmox
    $status = 'online';
} elseif ($action === 'stop') {
    // Qui inserisci il comando per spegnere la VM su Proxmox
    $status = 'offline';
} else {
    http_response_code(400);
    echo "Azione non valida";
    exit;
}

$stmt = $pdo->prepare("UPDATE servers SET status = ? WHERE id = ?");
$stmt->execute([$status, $serverId]);

echo "success";
