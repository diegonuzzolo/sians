<?php
// ajax_console_logs.php
session_start();
header('Content-Type: application/json');

require 'config/config.php'; // PDO $pdo
require_once 'includes/auth_check.php';
require_once 'includes/csrf.php';

use phpseclib3\Net\SSH2;

require 'vendor/autoload.php'; // phpseclib

$userId = $_SESSION['user_id'] ?? 0;
$serverId = intval($_GET['id'] ?? 0);
$csrfToken = $_GET['csrf_token'] ?? '';

if (!verifyCsrfToken($csrfToken)) {
    echo json_encode(['success' => false, 'error' => 'Token CSRF non valido']);
    exit;
}

// Recupera il server dell'utente con container associato
$stmt = $pdo->prepare("
    SELECT s.id AS server_id, s.name AS server_name, s.proxmox_containerId, c.hostname, c.ip
    FROM servers s
    LEFT JOIN containers c ON s.container_id = c.id
    WHERE s.id = ? AND s.user_id = ?
");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    echo json_encode(['success' => false, 'error' => 'Server non trovato']);
    exit;
}

// Connessione SSH al nodo Proxmox
$nodeIp = '192.168.1.251';
$sshUser = 'root'; // o altro utente autorizzato
$sshKey = '/var/www/.ssh/id_proxmox'; // percorso chiave privata

$ssh = new SSH2($nodeIp);
$key = \phpseclib3\Crypt\PublicKeyLoader::load(file_get_contents($sshKey));

if (!$ssh->login($sshUser, $key)) {
    echo json_encode(['success' => false, 'error' => 'Login SSH fallito']);
    exit;
}

// Comando per leggere gli ultimi 200 log dal container LXC
$containerId = $server['proxmox_containerId'];
$command = "sudo pct exec $containerId -- tail -n 200 /var/log/minecraft/server.log";

$output = $ssh->exec($command);
$lines = explode("\n", trim($output));

echo json_encode(['success' => true, 'logs' => $lines]);
