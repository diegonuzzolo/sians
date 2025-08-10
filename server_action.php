<?php
include("auth_check.php");
require 'config/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metodo non consentito']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$serverId = $data['id'] ?? null;
$action = $data['action'] ?? null;

if (!$serverId || !in_array($action, ['start', 'stop', 'status'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Parametri mancanti o non validi']);
    exit;
}

// Prendi info server e container
$stmt = $pdo->prepare("SELECT s.*, c.ip FROM servers s JOIN containers c ON s.container_id = c.id WHERE s.id = ?");
$stmt->execute([$serverId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Server non trovato']);
    exit;
}

$ip = $server['ip'];
$sshUser = 'root'; // utente nel container
$sshKeyPath = '/var/www/.ssh/id_ed25519_mc'; // path alla chiave privata SSH

// Comando da eseguire dentro il container
$remoteCommand = "/usr/local/bin/minecraft_server_control.sh $action";

// Costruisci comando ssh
$cmd = "ssh -i " . escapeshellarg($sshKeyPath) . " -o StrictHostKeyChecking=no {$sshUser}@{$ip} " . escapeshellarg($remoteCommand);

// Esegui comando
exec($cmd, $output, $return_var);

if ($return_var !== 0) {
    echo json_encode(['success' => false, 'error' => "Comando SSH fallito: " . implode("\n", $output)]);
    exit;
}

// Aggiorna stato nel DB a seconda dell'azione
$newStatus = null;
if ($action === 'start') {
    $newStatus = 'running';
} elseif ($action === 'stop') {
    $newStatus = 'stopped';
} elseif ($action === 'status') {
    $statusOutput = implode("\n", $output);
    $newStatus = trim($statusOutput) === 'running' ? 'running' : 'stopped';
}

if ($newStatus !== null) {
    $update = $pdo->prepare("UPDATE servers SET status = ?, updated_at = NOW() WHERE id = ?");
    $update->execute([$newStatus, $serverId]);
}

echo json_encode(['success' => true, 'status' => $newStatus]);
