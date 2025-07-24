<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'config/config.php';
require 'includes/auth.php';

// Verifica presenza dati POST
$serverName = $_POST['server_name'] ?? null;
$subdomain = $_POST['subdomain'] ?? null;

if (!$serverName || !$subdomain) {
    echo "Nome server o sottodominio mancante";
    exit;
}

// Continua con la logica per creare il server...

session_start();
require 'config/config.php';
require 'includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Metodo non consentito
    exit('Metodo non valido');
}

// Recupera e valida i parametri del form
$serverName = trim($_POST['server_name'] ?? '');
$subdomain = trim($_POST['subdomain'] ?? '');

if (empty($serverName) || empty($subdomain)) {
    header('Location: add_server.php?error=missing_fields');
    exit;
}

$userId = $_SESSION['user_id'];

// Verifica che il sottodominio non sia già in uso
$stmt = $pdo->prepare("SELECT COUNT(*) FROM servers WHERE subdomain = ?");
$stmt->execute([$subdomain]);
if ($stmt->fetchColumn() > 0) {
    header('Location: add_server.php?error=subdomain_in_use');
    exit;
}

// Trova una VM libera
$stmt = $pdo->prepare("SELECT * FROM minecraft_vms WHERE assigned_user_id IS NULL AND assigned_server_id IS NULL LIMIT 1");
$stmt->execute();
$vm = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$vm) {
    header('Location: add_server.php?error=no_vm_available');
    exit;
}

$vmId = $vm['id'];
$proxmoxVmid = $vm['proxmox_vmid'];

// Inserisci il nuovo server
$stmt = $pdo->prepare("
    INSERT INTO servers (name, user_id, vm_id, proxmox_vmid, subdomain, status)
    VALUES (?, ?, ?, ?, ?, 'creating')
");
$success = $stmt->execute([$serverName, $userId, $vmId, $proxmoxVmid, $subdomain]);

if (!$success) {
    error_log("Errore inserimento server: " . implode(', ', $stmt->errorInfo()));
    header('Location: add_server.php?error=db_error');
    exit;
}

$serverId = $pdo->lastInsertId();

// Assegna la VM
$stmt = $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = ?, assigned_server_id = ? WHERE id = ?");
$stmt->execute([$userId, $serverId, $vmId]);

// Reindirizza alla creazione tunnel e DNS
header("Location: create_tunnel_and_dns.php?server_id=$serverId");
exit;
