<?php
// ajax_send_command.php

ini_set('display_errors', 1);
error_reporting(E_ALL);

include("auth_check.php");
require 'config/config.php';

// Imposta l'header per la risposta JSON
header('Content-Type: application/json');

// Avvolgiamo tutto in un blocco try...catch per una gestione pulita degli errori
try {
    // 1. Sicurezza: Controlla che la richiesta sia di tipo POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Metodo non autorizzato.");
    }

    // 2. Sicurezza: Genera un nuovo token CSRF dopo l'uso per prevenire replay attacks
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        throw new Exception("Token CSRF non valido. Ricarica la pagina.");
    }
    // Invalida il token usato. Il frontend ha ancora il vecchio, ma al prossimo caricamento
    // della pagina o form ne verrà generato uno nuovo. Per maggiore sicurezza, si può rigenerare
    // e reinviare tramite JSON se necessario.
    unset($_SESSION['csrf_token']);


    // 3. Validazione Input
    $userId   = $_SESSION['user_id'] ?? 0;
    $serverId = intval($_POST['server_id'] ?? 0);
    $command  = trim($_POST['command'] ?? '');

    if (!$userId || !$serverId || empty($command)) {
        throw new Exception("Dati mancanti: impossibile inviare il comando.");
    }

    // 4. Recupera le informazioni del container dal database
    // Questo passaggio verifica anche che l'utente sia il proprietario del server
    $stmt = $pdo->prepare("
        SELECT s.proxmox_containerId
        FROM servers s
        WHERE s.id = ? AND s.user_id = ?
    ");
    $stmt->execute([$serverId, $userId]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$server || empty($server['proxmox_containerId'])) {
        throw new Exception("Server non trovato o non autorizzato.");
    }

    // 5. Costruzione ed esecuzione del comando remoto
    $vmid          = intval($server['proxmox_containerId']);
    $nodeIp        = '192.168.1.251'; // IP del tuo nodo Proxmox
    $sshKey        = '/var/www/.ssh/id_proxmox';
    
    // **Assunzione importante**: il server Minecraft è in esecuzione in una sessione 'screen'.
    // Creiamo un nome di sessione prevedibile, ad es. "mc-server-101" per il container 101.
    $screenSessionName = "mc-server-" . $vmid;
    
    // Per evitare problemi con caratteri speciali (es. "say 'Ciao mondo'"),
    // facciamo un escape delle virgolette singole prima di passarlo a `stuff`.
    $escapedCommand = addslashes($command);

    // Il comando `screen -X stuff` invia una stringa alla sessione screen specificata.
    // Il `\n` alla fine simula la pressione del tasto Invio.
    $remoteCmd = sprintf(
        'screen -S %s -p 0 -X stuff "%s\n"',
        escapeshellarg($screenSessionName),
        $escapedCommand // Non usiamo escapeshellarg qui perché la stringa va tra virgolette dentro 'stuff'
    );

    // Il comando completo da eseguire sul nodo Proxmox
    $fullSshCmd = sprintf(
        'sudo ssh -i %s -o StrictHostKeyChecking=no -o ConnectTimeout=10 root@%s "pct exec %d -- %s"',
        escapeshellarg($sshKey),
        escapeshellarg($nodeIp),
        $vmid,
        $remoteCmd // Il comando da eseguire DENTRO il container
    );

    // Esegui il comando
    $output = shell_exec($fullSshCmd);

    // shell_exec restituisce null in caso di errore
    if ($output === null) {
        // Potrebbe essere necessario un debug più approfondito qui (es. loggare $fullSshCmd)
        // throw new Exception("Errore nell'esecuzione del comando sul server remoto.");
    }

    // 6. Invia una risposta di successo
    echo json_encode(['success' => true, 'message' => 'Comando inviato.']);

} catch (Exception $e) {
    // In caso di qualsiasi errore, invia una risposta JSON con il messaggio di errore
    http_response_code(400); // Bad Request o 500 Internal Server Error sono appropriati
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}