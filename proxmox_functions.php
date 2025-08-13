<?php
require 'config/config.php';

/**
 * Login a Proxmox
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
    if ($response === false) throw new Exception('CURL login error: '.curl_error($ch));
    curl_close($ch);

    $json = json_decode($response, true);
    if (!isset($json['data']['ticket'], $json['data']['CSRFPreventionToken'])) {
        throw new Exception('Login Proxmox fallito: ' . $response);
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
    $url = PROXMOX_API_URL . "/nodes/" . PROXMOX_NODE . "/lxc";

    $postData = http_build_query([
        'vmid'       => $vmid,
        'hostname'   => "mcserver-$vmid",
        'storage'    => PROXMOX_STORAGE,
        'ostemplate' => PROXMOX_TEMPLATE,
        'memory'     => $ramMb,
        'cores'      => $cpu,
        'rootfs'     => PROXMOX_STORAGE . ":$storageGb",
        'net0'       => "name=eth0,bridge=vmbr0,ip=$privateIp/24,gw=" . CONTAINER_GATEWAY,
        'onboot'     => 1,
        'password'   => PROXMOX_PASSWORD
    ]);

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "CSRFPreventionToken: {$auth['csrf']}\r\n" .
                        "Cookie: PVEAuthCookie={$auth['ticket']}\r\n" .
                        "Content-type: application/x-www-form-urlencoded\r\n",
            'content' => $postData,
            'ignore_errors' => true
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
    ]);

    $response = file_get_contents($url, false, $context);
    $json = json_decode($response, true);

    if (!is_array($json) || isset($json['errors'])) {
        $msg = is_array($json['errors'] ?? null) ? implode("; ", $json['errors']) : ($response ?? "Risposta non valida");
        return ['success' => false, 'message' => $msg];
    }

    return ['success' => true, 'vmid' => $vmid, 'message' => "Container $vmid creato"];
}

/**
 * Avvia container
 */
function startLxcContainer(int $vmid, array $auth): array {
    $url = PROXMOX_API_URL . "/nodes/" . PROXMOX_NODE . "/lxc/$vmid/status/start";

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "CSRFPreventionToken: {$auth['csrf']}\r\n" .
                        "Cookie: PVEAuthCookie={$auth['ticket']}\r\n",
            'ignore_errors' => true
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
    ]);

    $response = file_get_contents($url, false, $context);
    $json = json_decode($response, true);

    if (!is_array($json) || !array_key_exists('data', $json)) {
        return ['success' => false, 'message' => "Errore avvio container: $response"];
    }

    return ['success' => true, 'message' => "Container $vmid avviato"];
}

/**
 * Attendi container in running
 */
function waitForContainerRunning(int $vmid, array $auth, int $timeout = 60): array {
    $url = PROXMOX_API_URL . "/nodes/" . PROXMOX_NODE . "/lxc/$vmid/status/current";

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "CSRFPreventionToken: {$auth['csrf']}\r\n" .
                        "Cookie: PVEAuthCookie={$auth['ticket']}\r\n",
            'ignore_errors' => true
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
    ]);

    $startTime = time();
    while (true) {
        $resp = file_get_contents($url, false, $context);
        $json = json_decode($resp, true);

        if (isset($json['data']['status']) && $json['data']['status'] === 'running') {
            return ['success' => true, 'message' => "Container $vmid in esecuzione"];
        }

        if (time() - $startTime > $timeout) {
            return ['success' => false, 'message' => "Timeout attesa container"];
        }

        sleep(2);
    }
}

/**
 * Avvia script Bedrock sul container
 */
function prepareBedrockServer(int $vmid, PDO $pdo, int $serverId): array {
    $logFile = "/tmp/bedrock_install_$vmid.log";
    file_put_contents($logFile, "");

    $scriptPath = "/root/install_bedrock.sh";
    $pid = shell_exec("pct exec $vmid -- bash $scriptPath > $logFile 2>&1 & echo $!");
    file_put_contents("/tmp/bedrock_install_$vmid.pid", trim($pid));

    $stmt = $pdo->prepare("UPDATE servers SET status = ? WHERE id = ?");
    $stmt->execute(['installing', $serverId]);

    return ['success' => true, 'message' => "Installazione Bedrock avviata", 'pid' => trim($pid)];
}

/**
 * Recupera il prossimo VMID disponibile
 */
function getNextAvailableVmid(array $auth): int {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => PROXMOX_API_URL . "/cluster/nextid",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            "CSRFPreventionToken: {$auth['csrf']}",
            "Cookie: PVEAuthCookie={$auth['ticket']}"
        ]
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $json = json_decode($response, true);
    return (int)($json['data'] ?? 101);
}

function calculateCpuCores(int $ramGb): int
{
    if ($ramGb <= 2)
        return 2;
    if ($ramGb <= 4)
        return 2;
    if ($ramGb <= 6)
        return 3;
    if ($ramGb <= 8)
        return 4;
    if ($ramGb <= 16)
        return 6;
    return 8;
}

/**
 * Utility: calcola prezzo
 */
function calculatePrice(int $ramGb): float
{
    $priceTable = [2 => 6.5 * 0.85, 4 => 10 * 0.85, 6 => 15 * 0.85, 8 => 21.5 * 0.85, 16 => 35 * 0.85, 32 => 57.5 * 0.85];
    if ($ramGb <= 2)
        return $priceTable[2];
    if ($ramGb <= 4)
        return $priceTable[4];
    if ($ramGb <= 6)
        return $priceTable[6];
    if ($ramGb <= 8)
        return $priceTable[8];
    if ($ramGb <= 16)
        return $priceTable[16];
    return $priceTable[32];
}

/**
 * Trova prossimo IP privato libero
 */
function getNextFreePrivateIp(PDO $pdo): string
{
    $baseIp = '192.168.1.';
    $start = 101;
    $end = 254;

    $stmt = $pdo->query("SELECT private_ip FROM containers WHERE private_ip LIKE '192.168.1.%'");
    $usedIps = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $usedLastOctets = [];
    foreach ($usedIps as $ip) {
        $parts = explode('.', $ip);
        if (count($parts) === 4)
            $usedLastOctets[] = (int) $parts[3];
    }

    for ($i = $start; $i <= $end; $i++) {
        if (!in_array($i, $usedLastOctets))
            return $baseIp . $i;
    }

    return $baseIp . $start;
}
?>