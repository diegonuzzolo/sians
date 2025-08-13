<?php
require 'config/config.php';







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




function createLxcContainer(
    int $vmid,
    int $ramGb,
    int $storageGb,
    string $hostname,
    string $vmIp,
    int $cores = 2,
    string $template = 'local:vztmpl/ubuntu-22.04-standard_22.04-1_amd64.tar.zst'
): array {
    $login = proxmoxLogin();
    if (!$login) {
        return ['success' => false, 'message' => 'Login Proxmox fallito'];
    }

    $url = "{$login['host']}/api2/json/nodes/pve/lxc";

    $params = [
        'vmid'      => $vmid,
        'hostname'  => $hostname,
        'memory'    => $ramGb * 1024, // RAM in MB
        'rootfs'    => $storageGb . 'G',
        'cores'     => $cores,
        'net0'      => "name=eth0,bridge=vmbr0,ip={$vmIp}/24,gw=192.168.1.254",
        'ostemplate'=> $template,
        'password'  => 'Lgu8330Serve6', // password root container
        'start'     => 1
    ];

    return callProxmox($url, $params);
}


function callProxmox(string $url, array $params = []): array
{
    $login = proxmoxLogin();
    if (!$login) {
        return ['success' => false, 'message' => 'Login Proxmox fallito'];
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "CSRFPreventionToken: {$login['csrf']}",
        "Cookie: PVEAuthCookie={$login['ticket']}"
    ]);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['success' => false, 'message' => $err];
    }

    $data = json_decode($response, true);
    if (isset($data['data'])) {
        return ['success' => true, 'message' => json_encode($data['data'])];
    } else {
        $msg = $data['errors'] ?? 'Errore sconosciuto';
        return ['success' => false, 'message' => json_encode($msg)];
    }
}

function startContainer(int $vmid): array
{
    $login = proxmoxLogin();
    if (!$login) {
        return ['success' => false, 'message' => 'Login Proxmox fallito'];
    }

    $url = "{$login['host']}/api2/json/nodes/pve/lxc/$vmid/status/start";
    return callProxmox($url);
}

function stopContainer(int $vmid): array
{
    $login = proxmoxLogin();
    if (!$login) {
        return ['success' => false, 'message' => 'Login Proxmox fallito'];
    }

    $url = "{$login['host']}/api2/json/nodes/pve/lxc/$vmid/status/stop";
    return callProxmox($url);
}









function getNextFreeVmid(PDO $pdo, int $start = 101, int $end = 9999): int
{
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





/**
 * Calcola CPU consigliate in base alla RAM
 */
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
 * Calcola prezzo server in base alla RAM
 */
function calculatePrice(int $ramGb): float
{
    if ($ramGb <= 2)
        return 6.5 * 0.85;
    if ($ramGb <= 4)
        return 10 * 0.85;
    if ($ramGb <= 6)
        return 15 * 0.85;
    if ($ramGb <= 8)
        return 21.5 * 0.85;
    if ($ramGb <= 16)
        return 35 * 0.85;
    return 57.5 * 0.85;
}

/**
 * Recupera prossimo IP privato disponibile (192.168.1.101 - 192.168.1.254)
 */
function getNextFreePrivateIp(PDO $pdo): string
{
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