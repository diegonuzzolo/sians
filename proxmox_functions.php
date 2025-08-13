<?php
require 'config/config.php';

/**
 * Login su Proxmox API
 */
function proxmoxLogin() {
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
    if (!$response) {
        throw new Exception("Errore login Proxmox: " . curl_error($ch));
    }
    curl_close($ch);

    $json = json_decode($response, true);
    if (!isset($json['data']['ticket']) || !isset($json['data']['CSRFPreventionToken'])) {
        throw new Exception("Login Proxmox fallito: " . $response);
    }

    return [
        'ticket' => $json['data']['ticket'],
        'csrf' => $json['data']['CSRFPreventionToken']
    ];
}

/**
 * Crea un container LXC
 */
function createLxcContainer($vmid, $cpu, $ramMb, $storageGb, $privateIp, $auth) {
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
        'onboot' => 1
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
    if (!$response) throw new Exception("Errore creazione container: " . curl_error($ch));
    curl_close($ch);

    $json = json_decode($response, true);
    if (!isset($json['data'])) throw new Exception("Creazione container fallita: " . json_encode($json));

    return $json['data'];
}

/**
 * Avvia un container
 */
function startContainer($vmid, $auth) {
    $url = PROXMOX_API_URL . "/nodes/" . PROXMOX_NODE . "/lxc/$vmid/status/start";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "CSRFPreventionToken: {$auth['csrf']}",
        "Cookie: PVEAuthCookie={$auth['ticket']}"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    if (!$response) throw new Exception("Errore avvio container: " . curl_error($ch));
    curl_close($ch);

    $json = json_decode($response, true);
    if (!isset($json['data'])) throw new Exception("Errore avvio container: " . json_encode($json));

    return $json['data']; // Contiene upid per waitForTask
}

/**
 * Attende il completamento di un task Proxmox
 */
function waitForTask($upid, $auth, $timeout = 60) {
    $start = time();
    do {
        $url = PROXMOX_API_URL . "/nodes/" . PROXMOX_NODE . "/tasks/$upid/status";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "CSRFPreventionToken: {$auth['csrf']}",
            "Cookie: PVEAuthCookie={$auth['ticket']}"
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        curl_close($ch);

        $json = json_decode($response, true);
        $status = $json['data']['status'] ?? 'unknown';
        if ($status === 'stopped' || $status === 'OK') return true;

        sleep(2);
    } while ((time() - $start) < $timeout);

    throw new Exception("Timeout attesa task Proxmox $upid");
}

/**
 * Attende che il container sia in stato running
 */
function waitForContainer($vmid, $auth, $timeout = 60, $interval = 2) {
    $start = time();
    do {
        $url = PROXMOX_API_URL . "/nodes/" . PROXMOX_NODE . "/lxc/$vmid/status/current";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "CSRFPreventionToken: {$auth['csrf']}",
            "Cookie: PVEAuthCookie={$auth['ticket']}"
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        curl_close($ch);

        $json = json_decode($response, true);
        $status = $json['data']['status'] ?? 'unknown';
        if ($status === 'running') return true;

        sleep($interval);
    } while ((time() - $start) < $timeout);

    throw new Exception("Timeout: container $vmid non è running");
}

/**
 * Installa il server Bedrock all'interno del container
 */
function installBedrockServer($privateIp, $ramMb, $pdo, $serverId) {
    // Comandi da eseguire via SSH dentro il container
    $sshUser = 'root';
    $sshPass = 'Lgu8330Serve6';

    $connection = ssh2_connect($privateIp, 22);
    if (!$connection) throw new Exception("Impossibile connettersi al container $privateIp");

    if (!ssh2_auth_password($connection, $sshUser, $sshPass)) {
        throw new Exception("Autenticazione SSH fallita per $privateIp");
    }

    // Comandi Bedrock server
    $commands = [
        "apt update && apt install -y wget unzip screen",
        "mkdir -p /bedrock-server && cd /bedrock-server",
        "wget -O bedrock.zip https://www.minecraft.net/en-us/download/server/bedrock/",
        "unzip -o bedrock.zip && rm bedrock.zip",
        "echo 'serverName=BedrockServer' > server.properties",
        "screen -dmS bedrock java -Xmx{$ramMb}M -Xms{$ramMb}M -jar bedrock_server.jar"
    ];

    foreach ($commands as $cmd) {
        $stream = ssh2_exec($connection, $cmd);
        stream_set_blocking($stream, true);
        stream_get_contents($stream);
        fclose($stream);
    }

    // Aggiorna lo stato nel DB
    $stmt = $pdo->prepare("UPDATE servers SET status = ? WHERE id = ?");
    $stmt->execute(['ready', $serverId]);
}

function calculateCpuCores(int $ramGb): int {
    if ($ramGb <= 2) return 2;
    if ($ramGb <= 4) return 2;
    if ($ramGb <= 6) return 3;
    if ($ramGb <= 8) return 4;
    if ($ramGb <= 16) return 6;
    return 8;
}

// Calcola il prezzo del server in base alla RAM
function calculatePrice(int $ramGb): float {
    $multiplier = 0.85; // sconto fisso
    if ($ramGb <= 2) return 6.5 * $multiplier;
    if ($ramGb <= 4) return 10 * $multiplier;
    if ($ramGb <= 6) return 15 * $multiplier;
    if ($ramGb <= 8) return 21.5 * $multiplier;
    if ($ramGb <= 16) return 35 * $multiplier;
    return 57.5 * $multiplier;
}

// Restituisce il prossimo IP privato libero (supponendo subnet 192.168.1.0/24)
function getNextFreePrivateIp(PDO $pdo): string {
    $usedIps = $pdo->query("SELECT private_ip FROM containers")->fetchAll(PDO::FETCH_COLUMN);

    for ($i = 100; $i < 254; $i++) {
        $ip = "192.168.1.".$i;
        if (!in_array($ip, $usedIps)) return $ip;
    }

    throw new Exception("Nessun IP disponibile nella subnet privata.");
}