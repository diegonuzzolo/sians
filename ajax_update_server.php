<?php
header('Content-Type: application/json');
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/config/config.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Metodo non consentito');

    $userId = $_SESSION['user_id'] ?? 0;
    $serverId = intval($_POST['server_id'] ?? 0);
    if (!$userId || !$serverId) throw new Exception('Parametri non validi');

    // Recupera VMID e core dal DB
    $stmt = $pdo->prepare("SELECT proxmox_containerId, cpu_cores FROM servers WHERE id=? AND user_id=?");
    $stmt->execute([$serverId,$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('Server non trovato');
    $vmid = intval($row['proxmox_containerId'] ?? 0);
    $cpuCores = max(1,intval($row['cpu_cores'] ?? 2));

    // Dati dal form
    $motd = trim($_POST['motd'] ?? '');
    $gamemode = $_POST['gamemode'] ?? 'survival';
    $difficulty = $_POST['difficulty'] ?? 'easy';
    $maxPlayers = max(1,min(100,intval($_POST['max_players'] ?? 10)));
    $viewDistance = max(2,min(32,intval($_POST['view_distance'] ?? 6)));
    $enableCommandBlocks = (($_POST['enable_command_blocks'] ?? 'false') === 'true') ? 'true' : 'false';
    $levelName = trim($_POST['level_name'] ?? 'world');
    $seed = trim($_POST['seed'] ?? '');
    $spawnProtection = max(0,intval($_POST['spawn_protection'] ?? 16));
    $whiteListEnabled = (($_POST['white_list_enabled'] ?? 'false') === 'true') ? 'true' : 'false';
    $whitelistText = trim($_POST['whitelist'] ?? '');
    $blacklistText = trim($_POST['blacklist'] ?? '');
    $tickDistance = max(4,min(12,intval($_POST['tick_distance'] ?? 4)));
    $playerIdleTimeout = max(0,intval($_POST['player_idle_timeout'] ?? 30));
    $maxThreads = $cpuCores; // Imposta max-threads al numero di core del container
    $enableLanVisibility = 'true'; // statico

    // Generazione server.properties
    $propertiesLines = [
        "server-name={$motd}",
        "motd={$motd}",
        "gamemode={$gamemode}",
        "force-gamemode=false",
        "difficulty={$difficulty}",
        "allow-cheats=false",
        "max-players={$maxPlayers}",
        "online-mode=true",
        "allow-list={$whiteListEnabled}",
        "server-port=19132",
        "server-portv6=19133",
        "enable-lan-visibility={$enableLanVisibility}",
        "view-distance={$viewDistance}",
        "tick-distance={$tickDistance}",
        "player-idle-timeout={$playerIdleTimeout}",
        "max-threads={$maxThreads}",
        "level-name={$levelName}",
        "level-seed={$seed}",
        "default-player-permission-level=member",
        "texturepack-required=false",
        "content-log-file-enabled=false",
        "compression-threshold=1",
        "compression-algorithm=zlib",
        "server-authoritative-movement-strict=false",
        "server-authoritative-dismount-strict=false",
        "server-authoritative-entity-interactions-strict=false",
        "player-position-acceptance-threshold=0.5",
        "player-movement-action-direction-threshold=0.85",
        "server-authoritative-block-breaking-pick-range-scalar=1.5",
        "chat-restriction=None",
        "disable-player-interaction=false",
        "client-side-chunk-generation-enabled=true",
        "block-network-ids-are-hashes=true",
        "disable-persona=false",
        "disable-custom-skins=false",
        "server-build-radius-ratio=Disabled",
        "allow-outbound-script-debugging=false",
        "allow-inbound-script-debugging=false",
        "script-debugger-auto-attach=disabled"
    ];

    $serverProperties = implode("\n",$propertiesLines)."\n";
    $whitelistNormalized = trim(str_replace(["\r\n","\r"],"\n",$whitelistText))."\n";
    $blacklistNormalized = trim(str_replace(["\r\n","\r"],"\n",$blacklistText))."\n";

    // File temporanei locali
    $tmpDir = sys_get_temp_dir();
    $uniq = bin2hex(random_bytes(4));
    $tmpProp = "{$tmpDir}/server_{$serverId}_{$uniq}.properties";
    $tmpWhite = "{$tmpDir}/server_{$serverId}_{$uniq}.whitelist";
    $tmpBlack = "{$tmpDir}/server_{$serverId}_{$uniq}.blacklist";

    file_put_contents($tmpProp, $serverProperties);
    file_put_contents($tmpWhite, $whitelistNormalized);
    file_put_contents($tmpBlack, $blacklistNormalized);

    // Config SSH
    $sshKey = '/var/www/.ssh/id_proxmox';
    $nodeIp = '192.168.1.251';
    $escKey = escapeshellarg($sshKey);
    $escNode = "root@{$nodeIp}";
    $remoteTmpDir = "/root/.tmp/server_{$serverId}";
    $remoteProp = "{$remoteTmpDir}/server.properties";
    $remoteWhite = "{$remoteTmpDir}/whitelist.txt";
    $remoteBlack = "{$remoteTmpDir}/blacklist.txt";

    $run = function(string $cmd, string $step){
        $out = []; $ret=0;
        exec($cmd . " 2>&1", $out, $ret);
        if($ret!==0) throw new Exception("$step fallito (exit $ret): ".implode("\n",$out));
        return $out;
    };

    // Creazione tmp sul node
    $run("ssh -i {$escKey} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null {$escNode} ".
         escapeshellarg("mkdir -p {$remoteTmpDir}"), "Creazione cartella temporanea sul node");

    // Copia file sul node
    foreach ([[$tmpProp,$remoteProp],[$tmpWhite,$remoteWhite],[$tmpBlack,$remoteBlack]] as [$local,$remote]){
        $run("scp -i {$escKey} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null ".
             escapeshellarg($local)." {$escNode}:".escapeshellarg($remote), "Copia {$remote}");
    }

    // pct push nel container
    foreach ([[$remoteProp,'/root/minecraft_bedrock/server.properties'],
              [$remoteWhite,'/root/minecraft_bedrock/whitelist.txt'],
              [$remoteBlack,'/root/minecraft_bedrock/blacklist.txt']] as [$src,$dest]){
        $run("ssh -i {$escKey} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null {$escNode} ".
             escapeshellarg("pct push {$vmid} {$src} {$dest}"), "pct push {$dest}");
    }

    // Pulizia file temporanei sul node
    $run("ssh -i {$escKey} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null {$escNode} ".
         escapeshellarg("rm -f {$remoteProp} {$remoteWhite} {$remoteBlack} && rmdir {$remoteTmpDir} 2>/dev/null || true"), "Pulizia temporanei");

    // Aggiornamento DB
    $stmt = $pdo->prepare("
        UPDATE servers SET
            motd=?, gamemode=?, difficulty=?,
            max_players=?, view_distance=?,
            enable_command_blocks=?, level_name=?,
            seed=?, spawn_protection=?, white_list=?,
            whitelist=?, blacklist=?, tick_distance=?,
            player_idle_timeout=?, max_threads=?, updated_at=NOW()
        WHERE id=? AND user_id=?
    ");
    $stmt->execute([
        $motd,$gamemode,$difficulty,
        $maxPlayers,$viewDistance,
        $enableCommandBlocks,$levelName,
        $seed,$spawnProtection,$whiteListEnabled,
        $whitelistText,$blacklistText,$tickDistance,
        $playerIdleTimeout,$maxThreads,$serverId,$userId
    ]);

    ob_clean();
    echo json_encode(['success'=>true,'message'=>'Server aggiornato con successo','vmid'=>$vmid]);
    exit;

} catch(Throwable $e){
    ob_clean();
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    exit;
}
