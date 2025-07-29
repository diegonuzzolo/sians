<?php
session_start();
require 'config/config.php';
require 'includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['server_id'], $_POST['proxmox_vmid'])) {
    http_response_code(400);
    exit('Richiesta non valida');
}

$userId = $_SESSION['user_id'];
$serverId = intval($_POST['server_id']);
$proxmoxVmid = intval($_POST['proxmox_vmid']);

// Recupera il server per verificarne la proprietà e ottenere info VM per aggiornamento
$stmt = $pdo->prepare("SELECT vm_id, subdomain FROM servers WHERE id = ? AND user_id = ?");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    http_response_code(403);
    exit('Server non trovato o accesso negato');
}

$vmId = $server['vm_id'];
$subdomain = $server['subdomain'] ?? null;

// Funzione opzionale per eliminare record DNS Cloudflare
// eliminaRecordDnsCloudflare($subdomain);

// Disassocia VM senza cancellare la VM fisica
$updateVmStmt = $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = NULL, assigned_server_id = NULL WHERE id = ?");
$updateVmStmt->execute([$vmId]);

// Elimina server dal DB
$delStmt = $pdo->prepare("DELETE FROM servers WHERE id = ? AND user_id = ?");
$delStmt->execute([$serverId, $userId]);

// Funzione per ottenere IP VM dal vm_id
function getVmIpFromVmId(int $vmId): ?string {
    global $pdo;
    $stmt = $pdo->prepare("SELECT ip FROM minecraft_vms WHERE id = ?");
    $stmt->execute([$vmId]);
    $vm = $stmt->fetch(PDO::FETCH_ASSOC);
    return $vm['ip'] ?? null;
}

$sshUser = 'diego';
$vmIp = getVmIpFromVmId($vmId);

if (!$vmIp) {
    error_log("IP VM non trovato per vm_id=$vmId");
    // Puoi gestire errore o procedere comunque
}

// Percorso server corretto e dinamico (cartella server specifica)
$serverDir = "/home/diego/minecraft_servers/$serverId";

$escapedServerDir = escapeshellarg($serverDir);
$escapedVmIp = escapeshellarg($vmIp);
$escapedSshUser = escapeshellarg($sshUser);

// Comando SSH per cancellare la cartella server
$cmd = "ssh {$escapedSshUser}@{$escapedVmIp} 'rm -rf {$escapedServerDir}'";

// Esegui comando e cattura output e codice di ritorno
exec($cmd . " 2>&1", $output, $return_var);

if ($return_var !== 0) {
    error_log("Errore eliminando cartella server (ID: $serverId) sulla VM $vmIp: " . implode("\n", $output));
    // Eventuale gestione errore utente (es: messaggio sessione)
}

header('Location: dashboard.php?msg=server_deleted');
exit;
