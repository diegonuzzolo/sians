<?php


session_start();
require 'config/config.php';
require 'includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['server_id'])) {
    http_response_code(400);
    exit('Richiesta non valida');
}

$userId = $_SESSION['user_id'];
$serverId = intval($_POST['server_id']);

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

// -- Opzionale: Elimina record DNS su Cloudflare se vuoi (funzione da implementare se vuoi)
// eliminaRecordDnsCloudflare($subdomain);

// Disassocia VM: rimuove assegnazioni senza cancellare VM
$updateVmStmt = $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = NULL, assigned_server_id = NULL WHERE id = ?");
$updateVmStmt->execute([$vmId]);

// Elimina server dal DB
$delStmt = $pdo->prepare("DELETE FROM servers WHERE id = ? AND user_id = ?");
$delStmt->execute([$serverId, $userId]);


function getVmIpFromVmId(int $vmId): ?string {
    global $pdo;

    $stmt = $pdo->prepare("SELECT ip FROM minecraft_vms WHERE id = ?");
    $stmt->execute([$vmId]);
    $vm = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($vm && !empty($vm['ip'])) {
        return $vm['ip'];
    }

    return null;  // IP non trovato
}



$sshUser = 'diego';
$vmIp = getVmIpFromVmId($vmId); // funzione da implementare o recuperare IP VM
$serverDir = "/home/diego/{$serverId}"; // esempio percorso cartella server

// Comando per eliminare la cartella server sulla VM (con -rf per forzare cancellazione)
$cmd = "ssh {$sshUser}@{$vmIp} 'rm -rf " . escapeshellarg($serverDir) . "'";

// Esegui comando e cattura output/errori
exec($cmd . " 2>&1", $output, $return_var);

if ($return_var !== 0) {
    // errore eliminazione cartella server
    error_log("Errore eliminando cartella server: " . implode("\n", $output));
    // eventualmente mostra messaggio utente o logga
}


header('Location: dashboard.php?msg=server_deleted');
exit;
