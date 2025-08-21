<?php
// FILE: ajax_update_server.php (Versione Corretta)

// Abilita la visualizzazione degli errori per il debug.
// In un ambiente di produzione, dovresti loggare gli errori su file invece di mostrarli.
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Imposta l'header per indicare che la risposta sarà in formato JSON
header('Content-Type: application/json');

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/proxmox_functions.php';

// Funzione per inviare una risposta JSON e terminare lo script
function json_response(bool $success, string $message, array $data = []): void {
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Metodo non consentito');
    }
    csrf_check_or_die_json();

    $userId = $_SESSION['user_id'] ?? 0;
    $serverId = filter_input(INPUT_POST, 'server_id', FILTER_VALIDATE_INT);
    if (!$userId || !$serverId) {
        throw new Exception('Parametri non validi o sessione scaduta.');
    }

    // Recupera VMID e CPU dal DB per usarli dopo
    $stmt = $pdo->prepare("SELECT proxmox_containerId, cpu_cores FROM servers WHERE id=? AND user_id=?");
    $stmt->execute([$serverId, $userId]);
    $serverData = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$serverData) {
        throw new Exception('Server non trovato o non ti appartiene.');
    }
    $vmid = intval($serverData['proxmox_containerId']);
    
    // --- Validazione e preparazione dei dati dal form ---
    $motd = trim($_POST['motd'] ?? 'Un Server Minecraft');
    $gamemode = in_array($_POST['gamemode'], ['survival', 'creative', 'adventure', 'spectator']) ? $_POST['gamemode'] : 'survival';
    $difficulty = in_array($_POST['difficulty'], ['peaceful', 'easy', 'normal', 'hard']) ? $_POST['difficulty'] : 'normal';
    $maxPlayers = max(1, min(100, intval($_POST['max_players'] ?? 10)));
    $whiteListEnabled = ($_POST['white_list_enabled'] ?? 'false') === 'true'; // Convertito in booleano PHP
    $whitelistText = trim($_POST['whitelist'] ?? '');
    $enableCommandBlocks = ($_POST['enable_command_blocks'] ?? 'false') === 'true'; // Convertito in booleano PHP

    // Genera il contenuto del file server.properties
    $properties = [
        'server-name' => $motd,
        'gamemode' => $gamemode,
        'difficulty' => $difficulty,
        'max-players' => $maxPlayers,
        'white-list' => $whiteListEnabled ? 'true' : 'false',
        'enable-command-blocks' => $enableCommandBlocks ? 'true' : 'false',
        // Aggiungi qui altre proprietà che vuoi gestire dal form...
        // Proprietà fisse
        'server-port' => 19132,
        'online-mode' => 'true',
    ];

    $propertiesContent = "";
    foreach ($properties as $key => $value) {
        $propertiesContent .= "{$key}={$value}\n";
    }

    // Genera il file whitelist.txt
    $whitelistContent = trim(str_replace(["\r\n", "\r"], "\n", $whitelistText));

    // --- Scrittura file e invio al container ---
    // (Questa parte usa la tua eccellente funzione $run)
    $tmpPropFile = tempnam(sys_get_temp_dir(), 'prop');
    $tmpWhiteFile = tempnam(sys_get_temp_dir(), 'white');
    file_put_contents($tmpPropFile, $propertiesContent);
    file_put_contents($tmpWhiteFile, $whitelistContent);

    // Dettagli SSH e comandi remoti
    $sshKey = '/var/www/.ssh/id_proxmox';
    $nodeIp = '192.168.1.251';
    
    // Copia e sposta i file nel container
    exec_throw("scp -i {$sshKey} -o StrictHostKeyChecking=no {$tmpPropFile} root@{$nodeIp}:/tmp/server.properties", "Copia properties");
    exec_throw("scp -i {$sshKey} -o StrictHostKeyChecking=no {$tmpWhiteFile} root@{$nodeIp}:/tmp/whitelist.json", "Copia whitelist");
    
    exec_throw("ssh -i {$sshKey} -o StrictHostKeyChecking=no root@{$nodeIp} \"pct push {$vmid} /tmp/server.properties /root/minecraft_bedrock/server.properties\"", "Push properties");
    exec_throw("ssh -i {$sshKey} -o StrictHostKeyChecking=no root@{$nodeIp} \"pct push {$vmid} /tmp/whitelist.json /root/minecraft_bedrock/whitelist.json\"", "Push whitelist");

    // Pulizia file temporanei sul nodo Proxmox
    exec_throw("ssh -i {$sshKey} -o StrictHostKeyChecking=no root@{$nodeIp} \"rm /tmp/server.properties /tmp/whitelist.json\"", "Pulizia remota");
    
    // Pulizia file temporanei locali
    @unlink($tmpPropFile);
    @unlink($tmpWhiteFile);

    // --- Aggiornamento del Database con le colonne corrette ---
    $stmtUpdate = $pdo->prepare("
        UPDATE servers SET
            motd = ?,
            gamemode = ?,
            difficulty = ?,
            max_players = ?,
            white_list = ?,
            whitelist = ?,

            enable_command_blocks = ?,
            updated_at = NOW()
        WHERE id = ? AND user_id = ?
    ");
    
    $stmtUpdate->execute([
        $motd,
        $gamemode,
        $difficulty,
        $maxPlayers,
        $whiteListEnabled, // Invia booleano (verrà convertito in 1/0 dal PDO)
        $whitelistText,
        $enableCommandBlocks, // Invia booleano
        $serverId,
        $userId
    ]);

    json_response(true, 'Impostazioni del server aggiornate con successo!');

} catch (Throwable $e) {
    // In caso di qualsiasi errore, invia una risposta JSON chiara
    http_response_code(500);
    json_response(false, $e->getMessage());
}