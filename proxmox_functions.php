<?php
require 'config/config.php';
$PROXMOX_HOST = "https://192.168.1.251:8006";
$PROXMOX_API_TOKEN = "root@pam!sians=0b4b070d-90bc-40a5-b0d1-9db4df95aa79";



/**
 * Crea un container LXC
 */
function createLxcContainer(
    int $vmid,
    int $ramGb,
    int $storageGb,
    string $hostname,
    string $vmIp,
    int $cores = 2,
    string $storage = 'local-lvm',
    string $template = 'local:vztmpl/ubuntu-22.04-standard_22.04-1_amd64.tar.zst'
) {
    $host = "https://192.168.1.251:8006";
    $apiToken = "root@pam!sians=0b4b070d-90bc-40a5-b0d1-9db4df95aa79";

    $url = "$host/api2/json/nodes/pve/lxc";
    $swapMb = calcolaSwapMb($ramGb * 1024);
    $params = [
        'vmid' => $vmid,
        'hostname' => $hostname,
        'memory' => $ramGb * 1024,
        'swap' => $swapMb,
        'rootfs' => "local-lvm:64", // esempio "local-lvm:8"
        'cores' => $cores,
        'net0' => "name=eth0,bridge=vmbr0,ip=$vmIp/24,gw=192.168.1.254",
        'ostemplate' => $template,
        'password' => 'Lgu8330Serve6',
        'start' => 1
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: PVEAPIToken=$apiToken"
    ]);

    $res = curl_exec($ch);
    if ($res === false) {
        return ['success' => false, 'message' => curl_error($ch)];
    }
    curl_close($ch);

    $data = json_decode($res, true);
    return isset($data['data'])
        ? ['success' => true, 'message' => 'Container creato']
        : ['success' => false, 'message' => $res];
}


/**
 * Trova il prossimo VMID disponibile
 */
function getNextFreeVmid(PDO $pdo, int $start = 101, int $end = 9999): int
{
    $stmt = $pdo->query("SELECT proxmox_containerId FROM containers ORDER BY proxmox_containerId ASC");
    $usedVmidsDb = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $usedVmidsDb = array_map('intval', $usedVmidsDb);

    for ($vmid = $start; $vmid <= $end; $vmid++) {
        if (!in_array($vmid, $usedVmidsDb)) {
            return $vmid;
        }
    }

    throw new Exception("Nessun VMID libero disponibile tra $start e $end");
}


function callProxmox(string $url, string $method = 'GET', array $postData = []): array {
    $apiToken = 'root@pam!sians=0b4b070d-90bc-40a5-b0d1-9db4df95aa79';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    // Metodo HTTP
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));

    if (!empty($postData) && in_array(strtoupper($method), ['POST', 'PUT'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: PVEAPIToken=$apiToken"
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['success' => false, 'message' => "Errore cURL: $err"];
    }

    $data = json_decode($response, true);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'message' => json_encode($data['data'] ?? [])];
    } elseif (isset($data['errors'])) {
        return ['success' => false, 'message' => json_encode($data['errors'])];
    } else {
        return ['success' => false, 'message' => "HTTP $httpCode: " . ($response ? htmlspecialchars($response) : 'No response body')];
    }
}


/**
 * Recupera prossimo IP privato disponibile
 */
function getNextFreePrivateIp(PDO $pdo): string
{
    $baseIp = '192.168.1.';
    $start = 101;
    $end = 254;

    $stmt = $pdo->query("SELECT ip FROM containers WHERE ip LIKE '192.168.1.%'");
    $usedIps = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $usedLastOctets = array_map(function ($ip) {
        $parts = explode('.', $ip);
        return (int) $parts[3];
    }, $usedIps);

    for ($i = $start; $i <= $end; $i++) {
        if (!in_array($i, $usedLastOctets)) {
            return $baseIp . $i;
        }
    }

    return $baseIp . $start;
}




/**
 * Calcola la swap in MB in base alla RAM assegnata
 *
 * @param int $ramMb  RAM in MB
 * @return int        Swap in MB
 */
function calcolaSwapMb($ramMb) {
    if ($ramMb <= 2048) {
        // Piccoli server: swap doppia
        return $ramMb * 2;
    } elseif ($ramMb <= 8192) {
        // Medi server: swap 1.5x
        return (int) round($ramMb * 1.5);
    } else {
        // Grandi server: swap = RAM
        return $ramMb;
    }
}



/**
 * Genera in sicurezza il contenuto di server.properties per Bedrock
 *
 * @param array $data Dati inviati dal form
 * @return string Contenuto pronto da scrivere in /root/minecraft_bedrock/server.properties
 */
function generateBedrockProperties(array $data): string {

    $props = [];

    // --- MOTD ---
    $motd = trim($data['motd'] ?? '');
    $props[] = "motd=$motd";

    // --- GAMEMODE ---
    $gamemode = in_array($data['gamemode'] ?? '', ['survival','creative']) ? $data['gamemode'] : 'survival';
    $props[] = "gamemode=$gamemode";

    // --- DIFFICULTY ---
    $difficulty = in_array($data['difficulty'] ?? '', ['easy','normal','hard']) ? $data['difficulty'] : 'easy';
    $props[] = "difficulty=$difficulty";

    // --- MAX PLAYERS ---
    $max_players = max(1, min(100, intval($data['max_players'] ?? 10)));
    $props[] = "max-players=$max_players";

    // --- VIEW DISTANCE ---
    $view_distance = max(2, min(32, intval($data['view_distance'] ?? 6)));
    $props[] = "view-distance=$view_distance";

    // --- WHITELIST & BLACKLIST ---
    $whitelist = trim($data['whitelist'] ?? '');
    $props[] = "whitelist=".str_replace(["\r\n","\r"],"\n",$whitelist);

    $blacklist = trim($data['blacklist'] ?? '');
    $props[] = "blacklist=".str_replace(["\r\n","\r"],"\n",$blacklist);

    // --- ENABLE COMMAND BLOCKS ---
    $enable_command_blocks = in_array($data['enable_command_blocks'] ?? 'false',['true','false']) ? $data['enable_command_blocks'] : 'false';
    $props[] = "enable-command-block=$enable_command_blocks";

    // --- LEVEL NAME ---
    $level_name = trim($data['level_name'] ?? 'world');
    $props[] = "level-name=$level_name";

    // --- SEED ---
    $seed = trim($data['seed'] ?? '');
    $props[] = "seed=$seed";

    // --- SPAWN PROTECTION ---
    $spawn_protection = max(0,intval($data['spawn_protection'] ?? 16));
    $props[] = "spawn-protection=$spawn_protection";

    // --- WHITE LIST ENABLED ---
    $white_list_enabled = in_array($data['white_list'] ?? 'false',['true','false']) ? $data['white_list'] : 'false';
    $props[] = "white-list=$white_list_enabled";

    return implode("\n", $props);
}
