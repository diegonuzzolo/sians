<?php
require_once 'config/config.php';

/**
 * Login su Proxmox
 */
function proxmoxLogin(): array {
    $url = PROXMOX_API_URL . '/access/ticket';
    $data = [
        'username' => PROXMOX_USERNAME,
        'password' => PROXMOX_PASSWORD
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception("Errore login Proxmox: " . curl_error($ch));
    }
    curl_close($ch);

    $json = json_decode($response, true);
    if (!isset($json['data']['ticket']) || !isset($json['data']['CSRFPreventionToken'])) {
        throw new Exception("Login Proxmox fallito: $response");
    }

    return [
        'ticket' => $json['data']['ticket'],
        'csrf'   => $json['data']['CSRFPreventionToken']
    ];
}

/**
 * Crea un container LXC
 */
function createLxcContainer(int $vmid, int $cpu, int $ramMb, int $storageGb, string $privateIp, array $auth): array {
    $swapMb = intval($ramMb * 1.5);

    $postData = [
        'vmid'     => $vmid,
        'hostname' => "mcserver-$vmid",
        'storage'  => PROXMOX_STORAGE,
        'ostemplate' => 'local:vztmpl/ubuntu-25.04-standard_25.04-1.1_amd64.tar.zst',
        'memory'   => $ramMb,
        'swap'     => $swapMb,
        'cores'    => $cpu,
        'rootfs'   => PROXMOX_STORAGE . ':' . $storageGb,
        'net0'     => "name=eth0,bridge=vmbr0,ip=$privateIp/24,gw=192.168.1.254",
        'password' => 'Lgu8330Serve6',
        'onboot'   => 1
    ];

    $url = PROXMOX_API_URL . "/nodes/" . PROXMOX_NODE . "/lxc";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "CSRFPreventionToken: {$auth['csrf']}",
        "Cookie: PVEAuthCookie={$auth['ticket']}",
        "Accept-Encoding: identity"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception("Errore creazione container: " . curl_error($ch));
    }
    curl_close($ch);

    $json = json_decode($response, true);
    if (!$json || isset($json['errors'])) {
        throw new Exception("Errore creazione container: " . json_encode($json));
    }

    return $json['data'] ?? [];
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
 * Restituisce lo stato attuale del container
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
 * Attendi che il container sia in stato "running"
 */
function waitForContainer(int $vmid, array $auth, int $timeoutSeconds = 10, int $interval = 1): void {
    $elapsed = 0;
    while ($elapsed < $timeoutSeconds) {
        $status = getContainerStatus($vmid, $auth);
        if ($status === 'running') {
            return;
        }
        sleep($interval);
        $elapsed += $interval;
    }
    throw new Exception("Timeout attesa container $vmid");
}

/**
 * Installa Minecraft Bedrock server via SSH
 */
function installBedrockServer(string $ip, int $ramMb, PDO $pdo, int $serverId): void {
    $command = "apt update && apt install -y wget unzip screen && ".
               "wget -O bedrock.zip https://minecraft.azureedge.net/bin-linux/bedrock-server.zip && ".
               "unzip -o bedrock.zip -d bedrock && rm bedrock.zip && ".
               "screen -dmS mc_server ./bedrock/bedrock_server";

    $connection = ssh2_connect($ip, 22);
    if (!$connection || !ssh2_auth_password($connection, 'root', 'Lgu8330Serve6')) {
        throw new Exception("Errore connessione SSH al container $ip");
    }

    $stream = ssh2_exec($connection, $command);
    if (!$stream) {
        throw new Exception("Errore eseguendo installazione Bedrock via SSH");
    }

    stream_set_blocking($stream, true);
    stream_get_contents($stream);
    fclose($stream);

    // Aggiorna stato server
    $stmt = $pdo->prepare("UPDATE servers SET status = ? WHERE id = ?");
    $stmt->execute(['ready', $serverId]);
}

/**
 * Calcola CPU cores in base alla RAM
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
 * Calcola prezzo server
 */
function calculatePrice(int $ramGb): float {
    $priceTable = [
        2  => 6.5 * 0.85,
        4  => 10 * 0.85,
        6  => 15 * 0.85,
        8  => 21.5 * 0.85,
        16 => 35 * 0.85,
        32 => 57.5 * 0.85
    ];
    foreach ($priceTable as $r => $p) {
        if ($ramGb <= $r) return $p;
    }
    return 57.5 * 0.85;
}

/**
 * Ottieni prossimo IP privato disponibile
 */
function getNextFreePrivateIp(PDO $pdo): string {
    $baseIp = '192.168.1.';
    $stmt = $pdo->query("SELECT private_ip FROM containers WHERE private_ip LIKE '192.168.1.%'");
    $usedIps = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $usedOctets = [];
    foreach ($usedIps as $ip) {
        $parts = explode('.', $ip);
        if (count($parts) === 4) $usedOctets[] = (int)$parts[3];
    }

    for ($i=101; $i<=254; $i++) {
        if (!in_array($i, $usedOctets)) return $baseIp.$i;
    }

    return $baseIp.'101';
}
?>
