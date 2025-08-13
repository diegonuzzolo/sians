<?php
require 'config/config.php';
$PROXMOX_HOST = "https://192.168.1.251:8006";
$PROXMOX_API_TOKEN = "root@pam!sians=0b4b070d-90bc-40a5-b0d1-9db4df95aa79";



/**
 * Crea un container LXC
 */
function createLxcContainer(int $vmid, int $ramGb, int $storageGb, string $hostname, string $ip, int $cores): array {
    $hostname = $hostname ?: "mcserver-$vmid";

    $postData = [
        'vmid' => $vmid,
        'hostname' => $hostname,
        'memory' => $ramGb * 1024, // MB
        'rootfs' => $storageGb . 'G',
        'cores' => $cores,
        'net0' => "name=eth0,bridge=vmbr0,ip=$ip/24,gw=192.168.1.254",
        'ostemplate' => 'local:vztmpl/ubuntu-25-standard_25.0-1_amd64.tar.gz',
        'password' => 'Lgu8330Serve6',
        'start' => 1
    ];

    $url = 'https://192.168.1.251:8006/api2/json/nodes/pve/lxc';

    $result = callProxmox($url, $postData);

    if (!$result['success']) {
        return ['success' => false, 'message' => $result['message']];
    }

    // --- Attendi che il container sia “running” ---
    $maxWait = 10; // secondi totali di attesa
    $interval = 2; // ogni quanti secondi verificare
    $elapsed = 0;

    while ($elapsed < $maxWait) {
        $statusResult = callProxmox("https://192.168.1.251:8006/api2/json/nodes/pve/lxc/$vmid/status/current");
        if ($statusResult['success']) {
            $statusData = json_decode($statusResult['message'], true);
            if (isset($statusData['status']) && $statusData['status'] === 'running') {
                return ['success' => true, 'message' => "Container $vmid pronto e running"];
            }
        }
        sleep($interval);
        $elapsed += $interval;
    }

    return ['success' => false, 'message' => "Timeout: container $vmid non è diventato running entro $maxWait secondi"];
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


/**
 * Esegue una chiamata all'API Proxmox
 *
 * @param string $url URL completo dell'endpoint API (es. https://192.168.1.251:8006/api2/json/nodes/pve/lxc/100/status/start)
 * @param array $postData Dati POST opzionali
 * @return array ['success' => true|false, 'message' => string]
 */
function callProxmox(string $url, array $postData = []): array {
    $apiToken = 'root@pam!sians=0b4b070d-90bc-40a5-b0d1-9db4df95aa79';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    if (!empty($postData)) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: PVEAPIToken=$apiToken"
    ]);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['success' => false, 'message' => "Curl error: $err"];
    }

    $data = json_decode($response, true);
    if (isset($data['data'])) {
        return ['success' => true, 'message' => json_encode($data['data'])];
    } elseif (isset($data['errors'])) {
        return ['success' => false, 'message' => json_encode($data['errors'])];
    } else {
        return ['success' => false, 'message' => 'Errore sconosciuto'];
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


