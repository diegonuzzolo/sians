<?php
/**
 * ajax_update_server.php
 * Aggiorna server.properties (Bedrock) nel container Proxmox via ssh + pct push.
 */
header('Content-Type: application/json');

// Non inviare output non-JSON
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/config/config.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Metodo non consentito');
    }

    $userId   = $_SESSION['user_id'] ?? 0;
    $serverId = intval($_POST['server_id'] ?? 0);
    if (!$userId || !$serverId) {
        throw new Exception('Parametri non validi');
    }

    // === 1) Recupero VMID dal DB in modo sicuro (colonna: proxmox_containerId) ===
    $stmt = $pdo->prepare("SELECT proxmox_containerId FROM servers WHERE id = ? AND user_id = ?");
    $stmt->execute([$serverId, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Server non trovato o non appartenente all’utente');
    }
    $vmid = intval($row['proxmox_containerId'] ?? 0);
    if ($vmid <= 0) {
        throw new Exception('VMID non valido sul server');
    }

    // === 2) Dati dal form ===
    $motd                 = trim($_POST['motd'] ?? '');
    $gamemode             = $_POST['gamemode'] ?? 'survival';
    $difficulty           = $_POST['difficulty'] ?? 'easy';
    $maxPlayers           = max(1, min(100, intval($_POST['max_players'] ?? 10)));
    $viewDistance         = max(2, min(32, intval($_POST['view_distance'] ?? 6)));
    $enableCommandBlocks  = (($_POST['enable_command_blocks'] ?? 'false') === 'true') ? 'true' : 'false';
    $levelName            = trim($_POST['level_name'] ?? 'world');
    $seed                 = trim($_POST['seed'] ?? '');
    $spawnProtection      = max(0, intval($_POST['spawn_protection'] ?? 16));
    $whiteListEnabled     = (($_POST['white_list'] ?? 'false') === 'true') ? 'true' : 'false';
    $whitelistText        = trim($_POST['whitelist'] ?? '');
    $blacklistText        = trim($_POST['blacklist'] ?? '');

    // === 3) Generazione contenuti file ===
    // Nota: per Bedrock usiamo le chiavi con il trattino; manteniamo anche server-name/motd.
    $propertiesLines = [
        "server-name={$motd}",
        "motd={$motd}",
        "gamemode={$gamemode}",
        "difficulty={$difficulty}",
        "max-players={$maxPlayers}",
        "view-distance={$viewDistance}",
        "enable-command-block={$enableCommandBlocks}",
        "level-name={$levelName}",
        "level-seed={$seed}",
        "spawn-protection={$spawnProtection}",
        "white-list={$whiteListEnabled}"
    ];
    $serverProperties = implode("\n", $propertiesLines) . "\n";

    // Normalizziamo whitelist/blacklist: accettiamo input separato da newline; convertiamo in newline
    $whitelistNormalized = trim(str_replace(["\r\n", "\r"], "\n", $whitelistText)) . "\n";
    $blacklistNormalized = trim(str_replace(["\r\n", "\r"], "\n", $blacklistText)) . "\n";

    // === 4) File temporanei su host ===
    $tmpDir   = sys_get_temp_dir();
    $uniq     = bin2hex(random_bytes(4));
    $tmpProp  = $tmpDir . "/server_{$serverId}_{$uniq}.properties";
    $tmpWhite = $tmpDir . "/server_{$serverId}_{$uniq}.whitelist";
    $tmpBlack = $tmpDir . "/server_{$serverId}_{$uniq}.blacklist";

    if (file_put_contents($tmpProp,  $serverProperties) === false) throw new Exception("Impossibile creare file temporaneo properties");
    if (file_put_contents($tmpWhite, $whitelistNormalized) === false) throw new Exception("Impossibile creare file temporaneo whitelist");
    if (file_put_contents($tmpBlack, $blacklistNormalized) === false) throw new Exception("Impossibile creare file temporaneo blacklist");

    // === 5) Parametri SSH/Node Proxmox ===
    $sshKey = '/var/www/.ssh/id_proxmox';   // chiave privata usata altrove nei tuoi script
    $nodeIp = '192.168.1.251';              // IP del node Proxmox

    // Comandi helper
    $escKey   = escapeshellarg($sshKey);
    $escNode  = "root@{$nodeIp}";
    $remoteTmpDir = "/root/.tmp/server_{$serverId}";
    $remoteProp   = "{$remoteTmpDir}/server.properties";
    $remoteWhite  = "{$remoteTmpDir}/whitelist.txt";
    $remoteBlack  = "{$remoteTmpDir}/blacklist.txt";

    // Funzione helper per exec con errori dettagliati
    $run = function(string $cmd, string $step) {
        $out = [];
        $ret = 0;
        exec($cmd, $out, $ret);
        if ($ret !== 0) {
            throw new Exception("$step fallito (exit $ret): " . implode("\n", $out));
        }
        return $out;
    };

    // === 6) Crea dir temporanea sul node, copia file via scp ===
    $run(
        "sudo ssh -i {$escKey} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null {$escNode} " .
        escapeshellarg("mkdir -p {$remoteTmpDir}"),
        "Creazione cartella temporanea sul node"
    );

    $run(
        "sudo scp -i {$escKey} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null " .
        escapeshellarg($tmpProp) . " {$escNode}:" . escapeshellarg($remoteProp),
        "Copia server.properties al node"
    );

    $run(
        "sudo scp -i {$escKey} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null " .
        escapeshellarg($tmpWhite) . " {$escNode}:" . escapeshellarg($remoteWhite),
        "Copia whitelist.txt al node"
    );

    $run(
        "sudo scp -i {$escKey} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null " .
        escapeshellarg($tmpBlack) . " {$escNode}:" . escapeshellarg($remoteBlack),
        "Copia blacklist.txt al node"
    );

    // === 7) pct push dal node nel container ===
    $run(
        "sudo ssh -i {$escKey} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null {$escNode} " .
        escapeshellarg("pct push {$vmid} {$remoteProp} /root/minecraft_bedrock/server.properties"),
        "pct push server.properties"
    );

    $run(
        "sudo ssh -i {$escKey} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null {$escNode} " .
        escapeshellarg("pct push {$vmid} {$remoteWhite} /root/minecraft_bedrock/whitelist.txt"),
        "pct push whitelist.txt"
    );

    $run(
        "sudo ssh -i {$escKey} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null {$escNode} " .
        escapeshellarg("pct push {$vmid} {$remoteBlack} /root/minecraft_bedrock/blacklist.txt"),
        "pct push blacklist.txt"
    );

    // (Opzionale) Pulizia temp sul node
    $run(
        "sudo ssh -i {$escKey} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null {$escNode} " .
        escapeshellarg("rm -f {$remoteProp} {$remoteWhite} {$remoteBlack} && rmdir {$remoteTmpDir} 2>/dev/null || true"),
        "Pulizia temporanei sul node"
    );

    // === 8) (Opzionale) Aggiorna DB con i valori ===
    $stmt = $pdo->prepare("
        UPDATE servers SET
            motd = ?, gamemode = ?, difficulty = ?,
            max_players = ?, view_distance = ?,
            enable_command_blocks = ?, level_name = ?,
            seed = ?, spawn_protection = ?, white_list = ?,
            whitelist = ?, blacklist = ?, updated_at = NOW()
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([
        $motd, $gamemode, $difficulty,
        $maxPlayers, $viewDistance,
        $enableCommandBlocks, $levelName,
        $seed, $spawnProtection, $whiteListEnabled,
        $whitelistText, $blacklistText, $serverId, $userId
    ]);

    // === 9) Risposta JSON ===
    ob_clean();
    echo json_encode(['success' => true, 'message' => 'Server aggiornato con successo', 'vmid' => $vmid]);
    exit;

} catch (Throwable $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
