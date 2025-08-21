<?php
// FILE: ajax_update_server.php (Versione Corretta)

ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/proxmox_functions.php';

// Funzione per inviare risposte JSON standardizzate
function json_response(bool $success, string $message, array $data = []): void {
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
    exit;
}

// Funzione helper per eseguire comandi con controllo degli errori
function exec_throw(string $command, string $errorMessage) {
    $output = [];
    $return_var = 0;
    exec($command . " 2>&1", $output, $return_var);
    if ($return_var !== 0) {
        throw new Exception("{$errorMessage}. Dettagli: " . implode("\n", $output));
    }
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

    // Verifica la proprietà del server
    $stmt = $pdo->prepare("SELECT proxmox_containerId, cpu_cores FROM servers WHERE id=? AND user_id=?");
    $stmt->execute([$serverId, $userId]);
    $serverData = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$serverData) {
        throw new Exception('Server non trovato o non ti appartiene.');
    }
    $vmid = intval($serverData['proxmox_containerId']);
    
    // --- Validazione e preparazione dei dati ---
    $motd = trim($_POST['motd'] ?? 'Un Server Minecraft');
    $gamemode = in_array($_POST['gamemode'], ['survival', 'creative', 'adventure']) ? $_POST['gamemode'] : 'survival';
    $difficulty = in_array($_POST['difficulty'], ['peaceful', 'easy', 'normal', 'hard']) ? $_POST['difficulty'] : 'normal';
    $maxPlayers = max(1, min(100, intval($_POST['max_players'] ?? 10)));
    $levelName = trim($_POST['level_name'] ?? 'world');
    $seed = trim($_POST['seed'] ?? '');
    
    // **CORREZIONE CHIAVE: Converti le stringhe 'true'/'false' in booleani PHP**
    $whiteListEnabled = ($_POST['white_list_enabled'] ?? 'false') === 'true';
    $enableCommandBlocks = ($_POST['enable_command_blocks'] ?? 'false') === 'true';
    
    $whitelistText = trim($_POST['whitelist'] ?? '');

    // --- Generazione file di configurazione ---
    $properties = [
        'server-name' => $motd,
        'gamemode' => $gamemode,
        'difficulty' => $difficulty,
        'max-players' => $maxPlayers,
        'white-list' => $whiteListEnabled ? 'true' : 'false',
        'enable-command-blocks' => $enableCommandBlocks ? 'true' : 'false',
        'level-name' => $levelName,
        'level-seed' => $seed,
        'server-port' => 19132,
        'online-mode' => 'true',
    ];

    $propertiesContent = "";
    foreach ($properties as $key => $value) {
        $propertiesContent .= "{$key}={$value}\n";
    }

    $whitelistContent = trim(str_replace(["\r\n", "\r"], "\n", $whitelistText));

    // --- Scrittura file e invio al container ---
    $tmpPropFile = tempnam(sys_get_temp_dir(), 'prop');
    $tmpWhiteFile = tempnam(sys_get_temp_dir(), 'white');
    file_put_contents($tmpPropFile, $propertiesContent);
    file_put_contents($tmpWhiteFile, $whitelistContent);

    $sshKey = '/var/www/.ssh/id_proxmox';
    $nodeIp = '192.168.1.251';
    
    exec_throw("scp -i {$sshKey} -o StrictHostKeyChecking=no {$tmpPropFile} root@{$nodeIp}:/tmp/server.properties", "Copia properties");
    exec_throw("scp -i {$sshKey} -o StrictHostKeyChecking=no {$tmpWhiteFile} root@{$nodeIp}:/tmp/whitelist.json", "Copia whitelist");
    
    exec_throw("ssh -i {$sshKey} -o StrictHostKeyChecking=no root@{$nodeIp} \"pct push {$vmid} /tmp/server.properties /root/minecraft_bedrock/server.properties\"", "Push properties");
    exec_throw("ssh -i {$sshKey} -o StrictHostKeyChecking=no root@{$nodeIp} \"pct push {$vmid} /tmp/whitelist.json /root/minecraft_bedrock/whitelist.json\"", "Push whitelist");

    exec_throw("ssh -i {$sshKey} -o StrictHostKeyChecking=no root@{$nodeIp} \"rm /tmp/server.properties /tmp/whitelist.json\"", "Pulizia remota");
    
    @unlink($tmpPropFile);
    @unlink($tmpWhiteFile);

    // --- Aggiornamento Database ---
    $stmtUpdate = $pdo->prepare("
        UPDATE servers SET
            motd = ?, gamemode = ?, difficulty = ?, max_players = ?,
            white_list = ?, whitelist = ?, enable_command_blocks = ?,
            level_name = ?, seed = ?, updated_at = NOW()
        WHERE id = ? AND user_id = ?
    ");
    
    $stmtUpdate->execute([
        $motd, $gamemode, $difficulty, $maxPlayers,
        $whiteListEnabled, // Ora inviamo un booleano (che PDO converte in 1 o 0)
        $whitelistText,
        $enableCommandBlocks, // Anche qui inviamo un booleano
        $levelName, $seed,
        $serverId, $userId
    ]);

    json_response(true, 'Impostazioni del server aggiornate con successo!');

} catch (Throwable $e) {
    http_response_code(500);
    json_response(false, "Errore: " . $e->getMessage());
}