<?php
require 'config/config.php';

/**
 * Login su Proxmox
 */
function proxmoxLogin(): array {
    $url = PROXMOX_API_URL . '/access/ticket';
    $data = [
        'username' => PROXMOX_USERNAME,
        'password' => PROXMOX_PASSWORD,
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception('Errore CURL login: ' . curl_error($ch));
    }
    curl_close($ch);

    $json = json_decode($response, true);
    if (!isset($json['data']['ticket'], $json['data']['CSRFPreventionToken'])) {
        throw new Exception('Login Proxmox fallito: ' . $response);
    }

    return [
        'ticket' => $json['data']['ticket'],
        'csrf' => $json['data']['CSRFPreventionToken'],
    ];
}

/**
 * Crea un container LXC
 */
function createLxcContainer(int $vmid, int $cpu, int $ramMb, int $storageGb, string $privateIp, array $auth): array {
    $swapMb = intval($ramMb * 1.5);

    $postData = [
        'vmid' => $vmid,
        'hostname' => "mcserver-$vmid",
        'storage' => PROXMOX_STORAGE,
        'ostemplate' => 'local:vztmpl/ubuntu-25.04-standard_25.04-1.1_amd64.tar.zst',
        'memory' => $ramMb,
        'swap' => $swapMb,
        'cores' => $cpu,
        'rootfs' => PROXMOX_STORAGE . ':' . $storageGb,
        'net0' => "name=eth0,bridge=vmbr0,ip=$privateIp/24,gw=192.168.1.254",
        'password' => 'Lgu8330Serve6',
        'onboot' => 1,
    ];

    $url = PROXMOX_API_URL . "/nodes/" . PROXMOX_NODE . "/lxc";
    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "CSRFPreventionToken: {$auth['csrf']}",
        "Cookie: PVEAuthCookie={$auth['ticket']}",
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception('Errore CURL createLxc: ' . curl_error($ch));
    }
    curl_close($ch);

    $json = json_decode($response, true);
    if (!is_array($json)) {
        throw new Exception('Risposta JSON non valida: ' . $response);
    }

    if (!isset($json['data'])) {
        throw new Exception('Errore creazione container: ' . $response);
    }

    return (array)$json['data'];
}

/**
 * Avvia container
 */
function startContainer(int $vmid, array $auth): void {
    $url = PROXMOX_API_URL . "/nodes/" . PROXMOX_NODE . "/lxc/$vmid/status/start";
    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "CSRFPreventionToken: {$auth['csrf']}",
        "Cookie: PVEAuthCookie={$auth['ticket']}",
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception('Errore avvio container: ' . curl_error($ch));
    }
    curl_close($ch);

    $json = json_decode($response, true);
    if (!isset($json['data'])) {
        throw new Exception('Errore avvio container: ' . $response);
    }
}


/**
 * Restituisce lo stato corrente di un container LXC su Proxmox.
 *
 * @param int $vmid ID del container
 * @param array $auth Array con 'ticket' e 'csrf' ottenuti da proxmoxLogin()
 * @return string Stato del container ('running', 'stopped', ecc.)
 * @throws Exception se la chiamata API fallisce
 */
function getContainerStatus(int $vmid, array $auth): string {
    $url = PROXMOX_API_URL . "/nodes/" . PROXMOX_NODE . "/lxc/$vmid/status/current";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "CSRFPreventionToken: {$auth['csrf']}",
        "Cookie: PVEAuthCookie={$auth['ticket']}"
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception("Errore CURL getContainerStatus: $err");
    }
    curl_close($ch);

    $json = json_decode($response, true);
    if (!isset($json['data']['status'])) {
        throw new Exception("Impossibile leggere lo stato del container: $response");
    }

    return $json['data']['status']; // es. 'running', 'stopped', 'paused', ecc.
}

function waitForContainer(int $vmid, array $auth, int $timeout = 10, int $interval = 1) {
    $start = time();
    while (time() - $start < $timeout) {
        $status = getContainerStatus($vmid, $auth); // funzione che chiama Proxmox API /status/current
        if ($status === 'running') {
            return true;
        }
        sleep($interval);
    }
    throw new Exception("Timeout attesa container $vmid");
}



/**
 * Installa Bedrock Server nel container tramite SSH
 */
function installBedrockServer(string $ip, int $ramMb, PDO $pdo, int $serverId): void {
    $sshUser = 'root';
    $sshPass = 'Lgu8330Serve6';

    if (!function_exists('ssh2_connect')) {
        throw new Exception("Estensione SSH2 mancante.");
    }

    $conn = ssh2_connect($ip, 22);
    if (!$conn || !ssh2_auth_password($conn, $sshUser, $sshPass)) {
        throw new Exception("Impossibile connettersi al container $ip via SSH.");
    }

    // Comandi per installare Bedrock
    $commands = [
        'apt update && apt install -y wget unzip screen',
        'mkdir -p /bedrock',
        'cd /bedrock',
        'wget -O bedrock-server.zip https://minecraft.azureedge.net/bin-linux/bedrock-server-1.20.10.23.zip',
        'unzip -o bedrock-server.zip',
        'rm bedrock-server.zip',
        'chmod +x bedrock_server'
    ];

    foreach ($commands as $cmd) {
        $stream = ssh2_exec($conn, $cmd);
        if (!$stream) throw new Exception("Errore eseguendo: $cmd");
        stream_set_blocking($stream, true);
        stream_get_contents($stream);
        fclose($stream);
    }

    // Aggiorna status DB
    $stmt = $pdo->prepare("UPDATE servers SET status = 'ready' WHERE id = ?");
    $stmt->execute([$serverId]);
}

/**
 * Calcolo CPU cores
 */
function calculateCpuCores(int $ramGb): int {
    if ($ramGb <= 2) return 2;
    if ($ramGb <= 4) return 2;
    if ($ramGb <= 6) return 3;
    if ($ramGb <= 8) return 4;
    if ($ramGb <= 16) return 6;
    return 8;
}

/**
 * Calcolo prezzo
 */
function calculatePrice(int $ramGb): float {
    $table = [
        2 => 6.5*0.85,
        4 => 10*0.85,
        6 => 15*0.85,
        8 => 21.5*0.85,
        16 => 35*0.85,
        32 => 57.5*0.85,
    ];

    if ($ramGb <= 2) return $table[2];
    if ($ramGb <= 4) return $table[4];
    if ($ramGb <= 6) return $table[6];
    if ($ramGb <= 8) return $table[8];
    if ($ramGb <= 16) return $table[16];
    return $table[32];
}

/**
 * Ottiene prossimo IP libero
 */
function getNextFreePrivateIp(PDO $pdo): string {
    $baseIp = '192.168.1.';
    $start = 101;
    $end = 254;

    $stmt = $pdo->query("SELECT private_ip FROM containers WHERE private_ip LIKE '192.168.1.%'");
    $usedIps = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $usedLastOctets = [];
    foreach ($usedIps as $ip) {
        $parts = explode('.', $ip);
        if (count($parts) === 4) $usedLastOctets[] = (int)$parts[3];
    }

    for ($i=$start; $i<=$end; $i++) {
        if (!in_array($i, $usedLastOctets)) return $baseIp.$i;
    }

    return $baseIp.$start;
}
