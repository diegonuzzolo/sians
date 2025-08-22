<?php
require 'auth_check.php';
require 'config/config.php';
require 'includes/csrf.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Metodo non consentito.']);
    exit;
}

csrf_check_or_die();

$serverId = intval($_POST['server_id'] ?? 0);
$userId = $_SESSION['user_id'] ?? 0;
$command = trim($_POST['command'] ?? '');

// Aggiungi 'proxmox_node' al recupero dati
$stmt = $pdo->prepare("SELECT proxmox_containerId, proxmox_node FROM servers WHERE id=? AND user_id=?");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server || empty($command) || empty($server['proxmox_node'])) {
    echo json_encode(['success' => false, 'message' => 'Dati non validi, accesso negato o nodo Proxmox non configurato.']);
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

try {
    // --- Passo 1: Autenticazione per ottenere ticket e token CSRF ---
    $ch_auth = curl_init();
    curl_setopt($ch_auth, CURLOPT_URL, "https://{$proxmox_host}:{$proxmox_port}/api2/json/access/ticket");
    curl_setopt($ch_auth, CURLOPT_POST, 1);
    // L'API di Proxmox usa un header speciale per l'autenticazione con token
    curl_setopt($ch_auth, CURLOPT_HTTPHEADER, ["Authorization: PVEAPIToken={$proxmox_user}!{$proxmox_token_id}={$proxmox_token_secret}"]);
    curl_setopt($ch_auth, CURLOPT_RETURNTRANSFER, 1);
    // Disabilita la verifica SSL (OK per sviluppo locale, ma sconsigliato in produzione)
    curl_setopt($ch_auth, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch_auth, CURLOPT_SSL_VERIFYHOST, false);

    $auth_response = curl_exec($ch_auth);
    if (curl_errno($ch_auth)) {
        throw new Exception('Errore cURL (auth): ' . curl_error($ch_auth));
    }
    curl_close($ch_auth);

    $auth_data = json_decode($auth_response, true);
    if ($auth_data === null || !isset($auth_data['data'])) {
        throw new Exception('Autenticazione a Proxmox fallita. Controlla le credenziali.');
    }

    $ticket = $auth_data['data']['ticket'];
    $csrf_token_proxmox = $auth_data['data']['CSRFPreventionToken'];

    // --- Passo 2: Esecuzione del comando ---
    $screenCommand = "screen -S minecraft_server -p 0 -X stuff \"{$command}\\n\"";
    $apiCommand = ['/bin/sh', '-c', $screenCommand];
    
    // Prepara i dati per la chiamata POST
    $post_data = http_build_query([
        'command' => json_encode($apiCommand) // L'API si aspetta un array JSON
    ]);

    $ch_exec = curl_init();
    curl_setopt($ch_exec, CURLOPT_URL, "https://{$proxmox_host}:{$proxmox_port}/api2/json/nodes/{$node}/lxc/{$containerId}/exec");
    curl_setopt($ch_exec, CURLOPT_POST, 1);
    curl_setopt($ch_exec, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch_exec, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch_exec, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch_exec, CURLOPT_SSL_VERIFYHOST, false);
    
    // Usa il ticket e il token CSRF ottenuti prima
    curl_setopt($ch_exec, CURLOPT_HTTPHEADER, [
        "CSRFPreventionToken: {$csrf_token_proxmox}",
        "Cookie: PVEAuthCookie={$ticket}"
    ]);

    $exec_response = curl_exec($ch_exec);
    if (curl_errno($ch_exec)) {
        throw new Exception('Errore cURL (exec): ' . curl_error($ch_exec));
    }
    curl_close($ch_exec);

    $exec_data = json_decode($exec_response, true);
    
    if ($exec_data === null || !isset($exec_data['data'])) {
        // A volte Proxmox restituisce un errore nel campo 'errors'
        $error_message = $exec_data['errors'][0] ?? 'Risposta API non valida.';
        throw new Exception($error_message);
    }
    
    // Se tutto va bene, l'API restituisce il PID del processo
    echo json_encode(['success' => true]);

} catch (\Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Errore API Proxmox: ' . $e->getMessage()]);
}