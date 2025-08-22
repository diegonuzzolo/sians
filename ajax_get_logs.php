<?php
require 'auth_check.php';
require 'config/config.php';

header('Content-Type: application/json');

$serverId = intval($_GET['server_id'] ?? 0);
$userId = $_SESSION['user_id'] ?? 0;
$offset = intval($_GET['offset'] ?? 0);

// Aggiungi 'proxmox_node' al recupero dati
$stmt = $pdo->prepare("SELECT proxmox_containerId, proxmox_node FROM servers WHERE id=? AND user_id=?");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server || empty($server['proxmox_node'])) {
    echo json_encode(['success' => false, 'message' => 'Accesso negato o nodo Proxmox non configurato.']);
    exit;
}

// --- Connessione all'API di Proxmox con cURL ---

// Aggiungi queste credenziali al tuo file config.php
$proxmox_host = '192.168.1.251'; // Es. 192.168.1.10
$proxmox_port = 8006;
$proxmox_user = 'root@pam';
$proxmox_token_id = 'sians'; // L'ID del token che hai creato
$proxmox_token_secret = '0b4b070d-90bc-40a5-b0d1-9db4df95aa79';

$containerId = $server['proxmox_containerId'];
$node = $server['proxmox_node'];

// !!! ADATTA QUESTO PERCORSO !!!
// Questo è il percorso del file di log *DENTRO* il container Proxmox.
$logFilePathInContainer = "/root/minecraft_bedrock/debug_output.log";
// Potrebbe essere /root/server/logs/latest.log o simile, a seconda di dove hai installato il server.

try {
    // --- Passo 1: Autenticazione per ottenere ticket e token CSRF ---
    $ch_auth = curl_init();
    curl_setopt($ch_auth, CURLOPT_URL, "https://{$proxmox_host}:{$proxmox_port}/api2/json/access/ticket");
    curl_setopt($ch_auth, CURLOPT_POST, 1);
    curl_setopt($ch_auth, CURLOPT_HTTPHEADER, ["Authorization: PVEAPIToken={$proxmox_user}!{$proxmox_token_id}={$proxmox_token_secret}"]);
    curl_setopt($ch_auth, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch_auth, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch_auth, CURLOPT_SSL_VERIFYHOST, false);

    $auth_response = curl_exec($ch_auth);
    if (curl_errno($ch_auth)) {
        throw new Exception('Errore cURL (auth): ' . curl_error($ch_auth));
    }
    curl_close($ch_auth);

    $auth_data = json_decode($auth_response, true);
    if ($auth_data === null || !isset($auth_data['data'])) {
        throw new Exception('Autenticazione a Proxmox fallita.');
    }

    $ticket = $auth_data['data']['ticket'];
    $csrf_token_proxmox = $auth_data['data']['CSRFPreventionToken'];
    
    // --- Passo 2: Ottenere la dimensione attuale del file (stat) ---
    $stat_url = "https://{$proxmox_host}:{$proxmox_port}/api2/json/nodes/{$node}/lxc/{$containerId}/files/stat?filepath=" . urlencode($logFilePathInContainer);
    
    $ch_stat = curl_init();
    curl_setopt($ch_stat, CURLOPT_URL, $stat_url);
    curl_setopt($ch_stat, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch_stat, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch_stat, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch_stat, CURLOPT_HTTPHEADER, [
        "CSRFPreventionToken: {$csrf_token_proxmox}",
        "Cookie: PVEAuthCookie={$ticket}"
    ]);

    $stat_response = curl_exec($ch_stat);
    if(curl_errno($ch_stat)){
        throw new Exception('Errore cURL (stat): '.curl_error($ch_stat));
    }
    curl_close($ch_stat);

    $stat_data = json_decode($stat_response, true);
    if($stat_data === null || !isset($stat_data['data']['size'])) {
        // Se il file non esiste, Proxmox potrebbe restituire un errore
        echo json_encode(['success' => true, 'log' => '', 'new_size' => 0]);
        exit;
    }
    $fileSize = $stat_data['data']['size'];
    $logContent = '';

    // --- Passo 3: Leggere le nuove righe del file se è cresciuto ---
    if ($fileSize > $offset) {
        $read_url = "https://{$proxmox_host}:{$proxmox_port}/api2/json/nodes/{$node}/lxc/{$containerId}/files/read?filepath=" . urlencode($logFilePathInContainer) . "&start={$offset}";
        
        $ch_read = curl_init();
        curl_setopt($ch_read, CURLOPT_URL, $read_url);
        curl_setopt($ch_read, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch_read, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch_read, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch_read, CURLOPT_HTTPHEADER, [
            "CSRFPreventionToken: {$csrf_token_proxmox}",
            "Cookie: PVEAuthCookie={$ticket}"
        ]);

        $read_response = curl_exec($ch_read);
        if(curl_errno($ch_read)){
            throw new Exception('Errore cURL (read): '.curl_error($ch_read));
        }
        curl_close($ch_read);

        $read_data = json_decode($read_response, true);
        if(isset($read_data['data']['content'])) {
            $logContent = $read_data['data']['content'];
        }
    }
    
    // Formatta l'output come prima
    $logContent = htmlspecialchars($logContent);

    echo json_encode([
        'success' => true,
        'log' => nl2br($logContent),
        'new_size' => $fileSize
    ]);

} catch (\Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Errore API Proxmox: ' . $e->getMessage()]);
}