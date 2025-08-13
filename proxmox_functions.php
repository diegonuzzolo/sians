<?php
require 'config/config.php';

function getNextFreeVmid(PDO $pdo, int $start = 101, int $end = 9999): int {
    // Recupero VMID già usati nel DB
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



function createLxcContainer(
    int $vmid,
    int $ramGb,
    int $storageGb,
    string $hostname = '',
    string $vmIp = '',
    int $cores = 2,
    string $template = 'local:vztmpl/ubuntu-22.04-standard_22.04-1_amd64.tar.zst'
): array {
    $hostname = $hostname ?: "mcserver-$vmid";

    if (!$vmIp) {
        return ['success' => false, 'message' => 'IP container non fornito'];
    }

    $postData = [
        'vmid' => $vmid,
        'hostname' => $hostname,
        'memory' => $ramGb * 1024,
        'rootfs' => $storageGb . 'G',
        'cores' => $cores,
        'net0' => "name=eth0,bridge=vmbr0,ip=$vmIp/24,gw=192.168.1.254",
        'ostemplate' => $template,
        'password' => 'Lgu8330Serve6',
        'start' => 1
    ];

    $pveHost = 'https://192.168.1.251:8006/api2/json/nodes/pve/lxc';
    $apiToken = 'root@pam!sians=0b4b070d-90bc-40a5-b0d1-9db4df95aa79';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $pveHost);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: PVEAPIToken=$apiToken"]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    // Debug verbose curl
    $verbose = fopen('/var/www/html/debug_curl.log', 'a+');
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    curl_setopt($ch, CURLOPT_STDERR, $verbose);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($verbose);

    // Salvo sempre risposta grezza per debug
    file_put_contents('/var/www/html/debug_proxmox.log',
        "=== " . date('Y-m-d H:i:s') . " ===\nHTTP $httpCode\nPOST:\n" . print_r($postData, true) . "\nRESPONSE:\n$response\nERROR:\n$err\n\n",
        FILE_APPEND
    );

    if ($err) {
        return ['success' => false, 'message' => "Curl error: $err"];
    }

    $data = json_decode($response, true);

    if (isset($data['data']['upid'])) {
        return ['success' => true, 'message' => "Container $vmid creato con UPID " . $data['data']['upid']];
    }

    // Qui catturiamo ogni messaggio utile
    $msg = $data['message'] ?? $data['errors'] ?? $data ?? 'Errore sconosciuto';
    return ['success' => false, 'message' => is_array($msg) ? json_encode($msg) : $msg];
}


/**
 * Avvia un container LXC
 */
function startContainer(int $vmid): array {
    $url = "https://192.168.1.251:8006/api2/json/nodes/pve/lxc/$vmid/status/start";
    return callProxmox($url);
}

/**
 * Ferma un container LXC
 */
function stopContainer(int $vmid): array {
    $url = "https://192.168.1.251:8006/api2/json/nodes/pve/lxc/$vmid/status/stop";
    return callProxmox($url);
}

/**
 * Funzione interna per chiamare Proxmox API
 */
function callProxmox(string $url): array {
    $apiToken = 'root@pam!sians=0b4b070d-90bc-40a5-b0d1-9db4df95aa79';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: PVEAPIToken=$apiToken"]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) return ['success' => false, 'message' => $err];

    $data = json_decode($response, true);
    if (isset($data['data'])) {
        return ['success' => true, 'message' => json_encode($data['data'])];
    } else {
        $msg = $data['errors'] ?? 'Errore sconosciuto';
        return ['success' => false, 'message' => json_encode($msg)];
    }
}

/**
 * Calcola CPU consigliate in base alla RAM
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
 * Calcola prezzo server in base alla RAM
 */
function calculatePrice(int $ramGb): float {
    if ($ramGb <= 2) return 6.5*0.85;
    if ($ramGb <= 4) return 10*0.85;
    if ($ramGb <= 6) return 15*0.85;
    if ($ramGb <= 8) return 21.5*0.85;
    if ($ramGb <= 16) return 35*0.85;
    return 57.5*0.85;
}

/**
 * Recupera prossimo IP privato disponibile (192.168.1.101 - 192.168.1.254)
 */
function getNextFreePrivateIp(PDO $pdo): string {
    $baseIp = '192.168.1.';
    $start = 101;
    $end = 254;

    $stmt = $pdo->query("SELECT ip FROM containers WHERE ip LIKE '192.168.1.%'");
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

    return $baseIp . $start; // fallback
}
?>
