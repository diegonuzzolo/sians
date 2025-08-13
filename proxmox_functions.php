<?php
require 'config/config.php';

/**
 * Login su Proxmox e ritorna ticket e CSRF token
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
        throw new Exception('Errore CURL login: ' . curl_error($ch));
    }
    curl_close($ch);

    $json = json_decode($response, true);
    if (!isset($json['data']['ticket']) || !isset($json['data']['CSRFPreventionToken'])) {
        throw new Exception('Login Proxmox fallito: ' . $response);
    }

    return [
        'ticket' => $json['data']['ticket'],
        'csrf' => $json['data']['CSRFPreventionToken']
    ];
}

/**
 * Recupera lo stato corrente di un container LXC
 */
function getContainerStatus(int $vmid, array $auth): string
{
    $node = PROXMOX_NODE;
    $url = PROXMOX_API_URL . "/nodes/$node/lxc/$vmid/status/current";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "CSRFPreventionToken: {$auth['csrf']}",
        "Cookie: PVEAuthCookie={$auth['ticket']}"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception('Errore CURL: ' . curl_error($ch));
    }
    curl_close($ch);

    $json = json_decode($response, true);
    if (!isset($json['data']['status'])) {
        throw new Exception('Risposta API non valida: ' . $response);
    }

    return $json['data']['status'];
}

/**
 * Avvia un container LXC tramite API Proxmox
 */
function startContainer(int $vmid, array $auth): bool
{
    $node = PROXMOX_NODE;
    $url = PROXMOX_API_URL . "/nodes/$node/lxc/$vmid/status/start";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "CSRFPreventionToken: {$auth['csrf']}",
        "Cookie: PVEAuthCookie={$auth['ticket']}"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception('Errore CURL startContainer: ' . curl_error($ch));
    }
    curl_close($ch);

    $json = json_decode($response, true);
    return isset($json['data']);
}

/**
 * Ferma un container LXC tramite API Proxmox
 */
function stopContainer(int $vmid, array $auth): bool
{
    $node = PROXMOX_NODE;
    $url = PROXMOX_API_URL . "/nodes/$node/lxc/$vmid/status/stop";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "CSRFPreventionToken: {$auth['csrf']}",
        "Cookie: PVEAuthCookie={$auth['ticket']}"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception('Errore CURL stopContainer: ' . curl_error($ch));
    }
    curl_close($ch);

    $json = json_decode($response, true);
    return isset($json['data']);
}

/**
 * Crea un container LXC tramite API Proxmox
 */
function createLxcContainer($vmid, $cpu, $ramMb, $storageGb, $privateIp, $auth)
{
    $swapMb = intval($ramMb * 1.5);
    $postData = [
    'vmid' => $vmid,
    'hostname' => "mcserver-$vmid",
    'storage' => PROXMOX_STORAGE,
    'ostemplate' => 'local:vztmpl/ubuntu-22.04-standard_22.04-1_amd64.tar.zst',
    'memory' => $ramMb,
    'swap' => $swapMb,
    'cores' => $cpu,
    'rootfs' => PROXMOX_STORAGE . ':' . $storageGb,
    'net0' => "name=eth0,bridge=vmbr0,ip=$privateIp/24,gw=192.168.1.254",
    'password' => 'Lgu8330Serve6',
    'onboot' => 1,  // semplice intero, non array
];




    $url = PROXMOX_API_URL . "/nodes/" . PROXMOX_NODE . "/lxc";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "CSRFPreventionToken: {$auth['csrf']}",
        "Cookie: PVEAuthCookie={$auth['ticket']}"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);

    $json = json_decode($response, true);
    if (isset($json['errors']))
        throw new Exception("Errore creazione container: " . json_encode($json['errors']));
    return $json['data'] ?? true;
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

// Funzione helper per prezzo
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

// Funzione helper per IP libero
function getNextFreePrivateIp(PDO $pdo): string
{
    $baseIp = '192.168.1.';
    $stmt = $pdo->query("SELECT private_ip FROM containers WHERE private_ip LIKE '192.168.1.%'");
    $usedIps = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $usedLast = [];
    foreach ($usedIps as $ip) {
        $parts = explode('.', $ip);
        if (count($parts) === 4)
            $usedLast[] = (int) $parts[3];
    }
    for ($i = 101; $i <= 254; $i++) {
        if (!in_array($i, $usedLast))
            return $baseIp . $i;
    }
    return $baseIp . '101';
}

function waitForTask(?string $upid, array $auth, int $timeout = 60, int $interval = 3): void {
    if (!$upid) return;

    $start = time();
    while (true) {
        $url = PROXMOX_API_URL."/nodes/".PROXMOX_NODE."/tasks/{$upid}/status";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "CSRFPreventionToken: {$auth['csrf']}",
            "Cookie: PVEAuthCookie={$auth['ticket']}"
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        curl_close($ch);

        if ($response) {
            $json = json_decode($response, true);
            if (isset($json['data']['status'])) {
                $status = $json['data']['status'];
                if ($status === 'stopped' || $status === 'OK') {
                    return;
                }
            }
        }

        if (time() - $start > $timeout) {
            throw new Exception("Timeout waiting for Proxmox task $upid");
        }
        sleep($interval);
    }
}

/**
 * installBedrockServer
 * Installa un server Bedrock su un container dato, aggiornando lo stato in DB.
 *
 * @param string $containerIp IP privato del container
 * @param int $ramMb RAM in MB
 * @param PDO $pdo Connessione PDO al DB
 * @param int $serverId ID del server nel DB
 * @throws Exception
 */
function installBedrockServer(string $containerIp, int $ramMb, PDO $pdo, int $serverId) {
    $username = 'root';
    $password = 'Lgu8330Serve6';
    
    // Connessione SSH al container
    $retry = 0;
    do {
        $connection = @ssh2_connect($containerIp, 22);
        if ($connection) break;
        sleep(2);
        $retry++;
    } while ($retry < 10);

    if (!$connection) throw new Exception("Impossibile connettersi al container $containerIp via SSH");

    if (!@ssh2_auth_password($connection, $username, $password)) {
        throw new Exception("Autenticazione SSH fallita per $containerIp");
    }

    // Funzione interna per aggiornare lo stato
    $updateStatus = function($status) use ($pdo, $serverId) {
        $stmt = $pdo->prepare("UPDATE servers SET status = ? WHERE id = ?");
        $stmt->execute([$status, $serverId]);
    };

    $updateStatus('downloading_bedrock');

    // Comandi da eseguire sul container
    $commands = [
        "apt update && apt install -y wget unzip screen",
        "mkdir -p /opt/minecraft && cd /opt/minecraft",
        "wget -O bedrock-server.zip https://www.minecraft.net/content/dam/minecraft/edition/bedrock/latest/bedrock-server.zip",
        "unzip -o bedrock-server.zip && rm bedrock-server.zip",
        "echo \"eula=true\" > eula.txt",
        "screen -dmS mcbedrock java -Xmx{$ramMb}M -Xms{$ramMb}M -jar bedrock_server.jar nogui"
    ];

    foreach ($commands as $cmd) {
        $stream = ssh2_exec($connection, $cmd);
        if (!$stream) throw new Exception("Errore eseguendo: $cmd");
        stream_set_blocking($stream, true);
        $output = stream_get_contents($stream);
        fclose($stream);
    }

    $updateStatus('configuring_server');
    sleep(3); // Piccolo delay per assicurare avvio server

    $updateStatus('ready');
}