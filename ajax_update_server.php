<?php
header('Content-Type: application/json');
ob_start();  // cattura output indesiderato
ini_set('display_errors', 0);
error_reporting(0);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Metodo non consentito");
    }

    $serverId = intval($_POST['server_id'] ?? 0);
    if (!$serverId) throw new Exception("ID server non valido");

    // Recupera i dati dal form
    $props = [
        'motd' => $_POST['motd'] ?? '',
        'gamemode' => $_POST['gamemode'] ?? 'survival',
        'difficulty' => $_POST['difficulty'] ?? 'easy',
        'max_players' => max(1, min(100, intval($_POST['max_players'] ?? 10))),
        'view_distance' => max(2, min(32, intval($_POST['view_distance'] ?? 6))),
        'enable_command_blocks' => ($_POST['enable_command_blocks'] ?? 'false') === 'true' ? 'true' : 'false',
        'level_name' => $_POST['level_name'] ?? 'world',
        'seed' => $_POST['seed'] ?? '',
        'spawn_protection' => max(0, intval($_POST['spawn_protection'] ?? 16)),
        'white_list' => ($_POST['white_list'] ?? 'false') === 'true' ? 'true' : 'false',
        'whitelist' => $_POST['whitelist'] ?? '',
        'blacklist' => $_POST['blacklist'] ?? ''
    ];

    // Percorso dentro il container
    $containerPath = "/root/minecraft_bedrock/server_$serverId";
    if (!is_dir($containerPath)) {
        mkdir($containerPath, 0755, true);
    }

    $serverProperties = "";
    foreach ($props as $key => $value) {
        // whitelist e blacklist li gestiamo separatamente
        if (in_array($key, ['whitelist', 'blacklist'])) continue;
        $serverProperties .= "$key=$value\n";
    }

    $propertiesFile = $containerPath . "/server.properties";
    if (file_put_contents($propertiesFile, $serverProperties) === false) {
        throw new Exception("Errore scrittura server.properties in $propertiesFile");
    }

    // Scrive whitelist e blacklist
    file_put_contents($containerPath . "/whitelist.txt", $props['whitelist']);
    file_put_contents($containerPath . "/blacklist.txt", $props['blacklist']);

    echo json_encode(['success' => true, 'message' => "Server aggiornato con successo"]);

} catch (Throwable $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;
