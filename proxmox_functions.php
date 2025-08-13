<?php
require 'config/config.php';
$PROXMOX_HOST = "https://192.168.1.251:8006";
$PROXMOX_API_TOKEN = "root@pam!sians=0b4b070d-90bc-40a5-b0d1-9db4df95aa79";


/**
 * Login a Proxmox via root@pam + password
 */
function proxmoxLogin()
{
    $host = "https://192.168.1.251:8006";
    $user = "root@pam";
    $pass = "Lgu8330Serve6";

    $url = "$host/api2/json/access/ticket";
    $data = [
        'username' => $user,
        'password' => $pass
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    curl_close($ch);

    $json = json_decode($response, true);
    if (isset($json['data'])) {
        return [
            'host' => $host,
            'ticket' => $json['data']['ticket'],
            'csrf' => $json['data']['CSRFPreventionToken']
        ];
    }
    return false;
}

/**
 * Recupera il nome del nodo
 */
function getNodeName($login)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "{$login['host']}/api2/json/nodes");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Cookie: PVEAuthCookie={$login['ticket']}"
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $json = json_decode($res, true);
    return $json['data'][0]['node'] ?? 'pve';
}

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
 * Funzione generica per chiamare Proxmox API con API Token
 */
function callProxmox(string $method, string $endpoint, array $params = []): array
{
    global $PROXMOX_HOST, $PROXMOX_API_TOKEN;

    $url = rtrim($PROXMOX_HOST, "/") . "/api2/json" . $endpoint;

    $ch = curl_init();

    if (strtoupper($method) === "GET") {
        if (!empty($params)) {
            $url .= "?" . http_build_query($params);
        }
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    } elseif (strtoupper($method) === "POST") {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: PVEAPIToken=$PROXMOX_API_TOKEN"
    ]);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['success' => false, 'message' => $err];
    }

    $data = json_decode($response, true);
    if (isset($data['data'])) {
        return ['success' => true, 'data' => $data['data']];
    } else {
        return ['success' => false, 'message' => $response];
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