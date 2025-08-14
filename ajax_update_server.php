<?php
header('Content-Type: application/json');
require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php';

$userId = $_SESSION['user_id'] ?? 0;
$serverId = intval($_POST['server_id'] ?? 0);

// Controllo proprietà
$motd = $_POST['motd'] ?? '';
$gamemode = $_POST['gamemode'] ?? 'survival';
$difficulty = $_POST['difficulty'] ?? 'easy';
$maxPlayers = intval($_POST['max_players'] ?? 10);
$viewDistance = intval($_POST['view_distance'] ?? 6);
$enableCommandBlocks = $_POST['enable_command_blocks'] ?? 'false';
$levelName = $_POST['level_name'] ?? 'world';
$seed = $_POST['seed'] ?? '';
$spawnProtection = intval($_POST['spawn_protection'] ?? 16);
$whiteList = $_POST['white_list'] ?? 'false';
$whitelist = $_POST['whitelist'] ?? '';
$blacklist = $_POST['blacklist'] ?? '';

// Recupero VMID del server dal DB
$stmt = $pdo->prepare("SELECT vmid FROM servers WHERE id = ? AND user_id = ?");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$server) {
    echo json_encode(['success'=>false,'message'=>'Server non trovato']);
    exit;
}
$vmid = intval($server['vmid']);

// Genero contenuto server.properties
$props = <<<EOT
motd=$motd
gamemode=$gamemode
difficulty=$difficulty
max-players=$maxPlayers
view-distance=$viewDistance
enable-command-block=$enableCommandBlocks
level-name=$levelName
level-seed=$seed
spawn-protection=$spawnProtection
white-list=$whiteList
EOT;

if(!empty($whitelist)) {
    $props .= "\nwhitelist=".str_replace("\n", ",", $whitelist);
}
if(!empty($blacklist)) {
    $props .= "\nblacklist=".str_replace("\n", ",", $blacklist);
}

// Scrivo temporaneamente su host
$tmpFile = "/tmp/server_{$serverId}.properties";
file_put_contents($tmpFile, $props);

// Copio nel container
exec("pct push $vmid $tmpFile /root/minecraft_bedrock/server.properties", $output, $ret);
if($ret !== 0){
    echo json_encode(['success'=>false,'message'=>'Errore copia server.properties: '.implode("\n",$output)]);
    exit;
}

// Aggiorno DB (solo esempio, puoi aggiornare altri campi se vuoi)
$stmt = $pdo->prepare("UPDATE servers SET motd=?, gamemode=?, difficulty=?, max_players=?, view_distance=?, enable_command_blocks=?, level_name=?, seed=?, spawn_protection=?, white_list=?, whitelist=?, blacklist=? WHERE id=? AND user_id=?");
$stmt->execute([
    $motd, $gamemode, $difficulty, $maxPlayers, $viewDistance, $enableCommandBlocks,
    $levelName, $seed, $spawnProtection, $whiteList, $whitelist, $blacklist, $serverId, $userId
]);

echo json_encode(['success'=>true,'message'=>'Server aggiornato con successo']);
