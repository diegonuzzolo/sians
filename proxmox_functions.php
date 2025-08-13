<?php
require 'config/config.php';

/**
 * Login a Proxmox e ritorna ticket e CSRF token
 */
function proxmoxLogin(): array
{
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
    $url = PROXMOX_API_URL . "/nodes/" . PROXMOX_NODE . "/lxc/$vmid/status/start?async=1";

    $postData = [
        'vmid' => $vmid,
        'hostname' => "mcserver-$vmid",
        'storage' => PROXMOX_STORAGE,
        'ostemplate' => PROXMOX_TEMPLATE,
        'memory' => $ramMb,
        'swap' => 0,
        'cores' => $cpu,
        'rootfs' => PROXMOX_STORAGE . ":$storageGb",
        'net0' => "name=eth0,bridge=vmbr0,ip=$privateIp/24,gw=" . CONTAINER_GATEWAY,
        'password' => PROXMOX_PASSWORD,
        'onboot' => 1,
        'start' => 1 // avvia subito dopo creazione
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => [
            "CSRFPreventionToken: {$auth['csrf']}",
            "Cookie: PVEAuthCookie={$auth['ticket']}"
        ],
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        return ['success' => false, 'message' => "Errore cURL: " . curl_error($ch)];
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($response, true);
    if (!is_array($json)) {
        return ['success' => false, 'message' => "Risposta Proxmox non valida: $response"];
    }

    if ($httpCode >= 400) {
        return ['success' => false, 'message' => $json['errors'] ?? $response];
    }

    // Successo
    return [
        'success' => true,
        'vmid' => $vmid,
        'message' => "Container $vmid creato e avviato correttamente"
    ];
}
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



/**
 * Avvia un container LXC
 */
function startContainer(int $vmid): array
{
    $url = PROXMOX_API_URL . "/nodes/" . PROXMOX_NODE . "/lxc/$vmid/status/start";
    $auth = proxmoxLogin();

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            "CSRFPreventionToken: {$auth['csrf']}",
            "Cookie: PVEAuthCookie={$auth['ticket']}",
            "Accept-Encoding: identity"
        ],
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception("Errore cURL avvio container: " . curl_error($ch));
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($response, true);
    if (!is_array($json)) {
        throw new Exception("Errore avvio container, risposta Proxmox non valida: " . $response);
    }
    if ($httpCode >= 400) {
        throw new Exception("Errore API Proxmox ($httpCode): " . ($json['errors'][0] ?? $response));
    }

    return $json['data'] ?? [];
}

/**
 * Recupera lo stato corrente di un container
 */
function getContainerStatus(int $vmid): string
{
    $url = PROXMOX_API_URL . "/nodes/" . PROXMOX_NODE . "/lxc/$vmid/status/current";
    $auth = proxmoxLogin();

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
 * Attende fino a che il container sia 'running' oppure scada il timeout
 */
function waitForContainer(int $vmid, int $timeoutSec = 10, int $intervalSec = 1)
{
    $elapsed = 0;
    while ($elapsed < $timeoutSec) {
        $status = getContainerStatus($vmid);
        if ($status === 'running') {
            return true;
        }
        sleep($intervalSec);
        $elapsed += $intervalSec;
    }
    throw new Exception("Timeout attesa container $vmid");
}

/**
 * Installa Bedrock server su container via SSH
 */
function installBedrockServer(string $privateIp, int $ramMb, PDO $pdo, int $serverId)
{
    $cmds = [
        "apt update -y",
        "apt install -y wget unzip screen",
        "mkdir -p /opt/minecraft-bedrock",
        "cd /opt/minecraft-bedrock",
        "wget -O bedrock-server.zip https://www.minecraft.net/en-us/download/server/bedrock",
        "unzip -o bedrock-server.zip",
        "rm bedrock-server.zip"
    ];

    $sshCommand = "sshpass -p '" . PROXMOX_PASSWORD . "' ssh -o StrictHostKeyChecking=no root@$privateIp '" . implode(' && ', $cmds) . "'";

    exec($sshCommand, $output, $ret);
    if ($ret !== 0) {
        throw new Exception("Errore installazione Bedrock server sul container $serverId: " . implode("\n", $output));
    }

    // Aggiorna stato server a 'ready'
    $stmt = $pdo->prepare("UPDATE servers SET status = ? WHERE id = ?");
    $stmt->execute(['ready', $serverId]);
}

/**
 * Utility: calcola CPU in base alla RAM
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