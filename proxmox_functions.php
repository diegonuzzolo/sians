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

    $params = [
        'vmid' => $vmid,
        'hostname' => $hostname,
        'memory' => $ramGb * 1024,
        'rootfs' => "$storage:$storageGb", // esempio "local-lvm:8"
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





/**
 * Attende che un container LXC esista e sia pronto
 *
 * @param int $vmid
 * @param int $timeout Timeout massimo in secondi
 * @param int $interval Intervallo di controllo in secondi
 * @return bool true se pronto, false se timeout
 */
function waitForContainer(int $vmid, int $timeout = 10, int $interval = 2): bool {
    $elapsed = 0;
    while ($elapsed < $timeout) {
        $status = callProxmox("https://192.168.1.251:8006/api2/json/nodes/pve/lxc/$vmid/status/current");
        if ($status['success'] && isset($status['message'])) {
            $data = json_decode($status['message'], true);
            if (isset($data['status']) && in_array($data['status'], ['running', 'stopped'])) {
                return true;
            }
        }
        sleep($interval);
        $elapsed += $interval;
    }
    return false; // timeout
}
