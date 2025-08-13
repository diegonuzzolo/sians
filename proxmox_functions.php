<?php
require 'config/config.php';

/**
 * Login a Proxmox e ritorna ticket e CSRF token
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
        throw new Exception('CURL login error: ' . curl_error($ch));
    }
    curl_close($ch);

    $json = json_decode($response, true);
    if (!isset($json['data']['ticket'], $json['data']['CSRFPreventionToken'])) {
        throw new Exception('Login Proxmox fallito: ' . $response);
    }

    return [
        'ticket' => $json['data']['ticket'],
        'csrf' => $json['data']['CSRFPreventionToken']
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
        "Accept-Encoding: identity" // evita chunked
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception('Errore CURL createLxc: ' . curl_error($ch));
    }
    curl_close($ch);

    $json = json_decode($response, true);

    // Se non è array o non contiene 'data', genera eccezione
    if (!is_array($json) || !isset($json['data'])) {
        throw new Exception('Errore creazione container: ' . $response);
    }

    return $json['data']; // sempre un array
}

/**
 * Avvia un container
 */
function startContainer(int $vmid, array $auth): array {
    $url = PROXMOX_API_URL . "/nodes/" . PROXMOX_NODE . "/lxc/$vmid/status/start";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "CSRFPreventionToken: {$auth['csrf']}",
        "Cookie: PVEAuthCookie={$auth['ticket']}",
        "Accept-Encoding: identity"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception("Errore avvio container: " . curl_error($ch));
    }
    curl_close($ch);

    $json = json_decode($response, true);
    if (!$json || isset($json['errors'])) {
        throw new Exception("Errore avvio container: " . json_encode($json));
    }

    return $json['data'] ?? [];
}

/**
 * Controlla lo stato del container
 */
function getContainerStatus(int $vmid, array $auth): string {
    $url = PROXMOX_API_URL . "/nodes/" . PROXMOX_NODE . "/lxc/$vmid/status/current";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "CSRFPreventionToken: {$auth['csrf']}",
        "Cookie: PVEAuthCookie={$auth['ticket']}",
        "Accept-Encoding: identity"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception("Errore getContainerStatus: " . curl_error($ch));
    }
    curl_close($ch);

    $json = json_decode($response, true);
    return $json['data']['status'] ?? 'unknown';
}

/**
 * Attende un numero di secondi (semplice)
 */
function waitForContainer(int $vmid, array $auth, int $timeoutSec = 10, int $intervalSec = 1) {
    $elapsed = 0;
    while ($elapsed < $timeoutSec) {
        $status = getContainerStatus($vmid, $auth);
        if ($status === 'running') {
            return true;
        }
        sleep($intervalSec);
        $elapsed += $intervalSec;
    }
    throw new Exception("Timeout attesa container $vmid");
}

/**
 * Installa Bedrock server (semplice esempio)
 */
function installBedrockServer(string $privateIp, int $ramMb, PDO $pdo, int $serverId) {
    // Qui va la logica reale di installazione, esempio con ssh
    $cmd = "sshpass -p 'Lgu8330Serve6' ssh -o StrictHostKeyChecking=no root@$privateIp 'apt update && apt install -y wget unzip screen'";
    exec($cmd, $output, $ret);
    if ($ret !== 0) {
        throw new Exception("Errore installazione Bedrock server: " . implode("\n", $output));
    }

    // Aggiorna stato
    $stmt = $pdo->prepare("UPDATE servers SET status = ? WHERE id = ?");
    $stmt->execute(['ready', $serverId]);
}

/**
 * Funzioni utili: calcolo CPU e prezzo
 */
function calculateCpuCores(int $ramGb): int {
    if ($ramGb <= 2) return 2;
    if ($ramGb <= 4) return 2;
    if ($ramGb <= 6) return 3;
    if ($ramGb <= 8) return 4;
    if ($ramGb <= 16) return 6;
    return 8;
}

function calculatePrice(int $ramGb): float {
    $priceTable = [2 => 6.5*0.85, 4 => 10*0.85, 6 => 15*0.85, 8 => 21.5*0.85, 16 => 35*0.85, 32 => 57.5*0.85];
    if ($ramGb <= 2) return $priceTable[2];
    if ($ramGb <= 4) return $priceTable[4];
    if ($ramGb <= 6) return $priceTable[6];
    if ($ramGb <= 8) return $priceTable[8];
    if ($ramGb <= 16) return $priceTable[16];
    return $priceTable[32];
}

/**
 * Trova prossimo IP privato libero
 */
function getNextFreePrivateIp(PDO $pdo): string {
    $baseIp = '192.168.1.';
    $start = 101; $end = 254;

    $stmt = $pdo->query("SELECT private_ip FROM containers WHERE private_ip LIKE '192.168.1.%'");
    $usedIps = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $usedLastOctets = [];
    foreach($usedIps as $ip) {
        $parts = explode('.', $ip);
        if(count($parts) === 4) $usedLastOctets[] = (int)$parts[3];
    }

    for($i=$start;$i<=$end;$i++) {
        if(!in_array($i,$usedLastOctets)) return $baseIp.$i;
    }

    return $baseIp.$start;
}
