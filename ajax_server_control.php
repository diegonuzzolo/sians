<?php
include("auth_check.php");
require 'config/config.php';
require_once 'includes/csrf.php';

// Controlla il token CSRF o termina l'esecuzione
csrf_check_or_die_json();

try {
    $userId   = $_SESSION['user_id'] ?? 0;
    $serverId = intval($_POST['id'] ?? 0);
    $action   = $_POST['action'] ?? '';

    if (!$userId || !$serverId) {
        throw new Exception("Parametri della richiesta non validi.");
    }

    if (!in_array($action, ['start', 'stop'])) {
        throw new Exception("Azione non consentita.");
    }

    // --- MODIFICA 1: Migliorata la query per ottenere anche l'IP del nodo ---
    // Recupera VMID del server e l'IP del nodo Proxmox a cui appartiene
    $stmt = $pdo->prepare("
        SELECT 
            s.proxmox_containerId
        FROM servers s
        WHERE s.id = ? AND s.user_id = ?
    ");
    $stmt->execute([$serverId, $userId]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$server || empty($server['proxmox_containerId'])) {
        throw new Exception("Server non trovato o configurazione incompleta (VMID mancante).");
    }

    $vmid   = intval($server['proxmox_containerId']);
    $nodeIp = "192.168.1.251"; // Usiamo l'IP recuperato dal DB
    $sshKey = '/var/www/.ssh/id_proxmox'; // Percorso della chiave SSH sul server web

    // --- MODIFICA 2: Nome della sessione screen coerente con start.sh ---
    // Il nome della sessione DEVE essere lo stesso usato in start.sh (es. mc-101)
    $sessionName = "mc-" . $vmid;
    
    $cmd = '';

    // --- MODIFICA 3: Logica separata e corretta per 'start' e 'stop' ---
    if ($action === 'start') {
        // Per avviare, eseguiamo semplicemente lo script. Sarà lui a creare la sessione screen.
        $remoteScript = '/root/minecraft_bedrock/start.sh';
        $cmd = sprintf(
            'ssh -i %s -o StrictHostKeyChecking=no -o ConnectTimeout=10 root@%s "pct exec %d -- %s"',
            escapeshellarg($sshKey),
            escapeshellarg($nodeIp),
            $vmid,
            escapeshellarg($remoteScript)
        );
    } elseif ($action === 'stop') {
    // Esegue lo script stop.sh che gestisce l'intero processo di spegnimento sicuro.
    $remoteScript = '/root/minecraft_bedrock/stop.sh';
    
    $cmd = sprintf(
        'ssh -i %s -o StrictHostKeyChecking=no -o ConnectTimeout=10 root@%s "pct exec %d -- %s"',
        escapeshellarg($sshKey),
        escapeshellarg($nodeIp),
        $vmid,
        escapeshellarg($remoteScript)
    );
}
    
    exec($cmd . " 2>&1", $output, $ret);

    // Log per debug, molto utile
    file_put_contents('/tmp/debug_server_control.log', date('Y-m-d H:i:s') . " | ACTION: {$action} | CMD: {$cmd}\nRET: {$ret}\nOUTPUT:\n" . implode("\n", $output) . "\n\n", FILE_APPEND);

    if ($ret !== 0) {
        throw new Exception("Errore durante l'esecuzione dell'azione '{$action}'. L'output del comando è stato loggato per debug.");
    }

    // Aggiorna lo status nel DB
    $newStatus = ($action === 'start') ? 'running' : 'stopped';
    $stmt = $pdo->prepare("UPDATE servers SET status=? WHERE id=? AND user_id=?");
    $stmt->execute([$newStatus, $serverId, $userId]);

    echo json_encode([
        'success' => true,
        'message' => "Azione '{$action}' eseguita con successo.",
        'status'  => $newStatus
    ]);

} catch (Exception $e) {
    // Logga anche l'errore catturato
    file_put_contents('/tmp/debug_server_control.log', date('Y-m-d H:i:s') . " | EXCEPTION: " . $e->getMessage() . "\n\n", FILE_APPEND);
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}