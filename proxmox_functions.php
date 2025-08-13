<?php
require_once 'config/config.php';

function proxmoxLogin(): array {
    $url = PROXMOX_API_URL . '/access/ticket';
    $data = ['username'=>PROXMOX_USERNAME,'password'=>PROXMOX_PASSWORD];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $resp = curl_exec($ch);
    curl_close($ch);

    $json = json_decode($resp,true);
    if(!isset($json['data']['ticket'],$json['data']['CSRFPreventionToken'])){
        throw new Exception('Login Proxmox fallito: '.$resp);
    }
    return ['ticket'=>$json['data']['ticket'], 'csrf'=>$json['data']['CSRFPreventionToken']];
}

function createLxcContainer($vmid, $cpu, $ramMb, $storageGb, $privateIp, $auth): array {
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
        'onboot' => 1,
    ];

    $url = PROXMOX_API_URL . "/nodes/" . PROXMOX_NODE . "/lxc";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "CSRFPreventionToken: {$auth['csrf']}",
        "Cookie: PVEAuthCookie={$auth['ticket']}",
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception('Errore CURL createLxc: ' . curl_error($ch));
    }
    curl_close($ch);

    $json = json_decode($response, true);
    if (!isset($json['data'])) {
        throw new Exception('Errore creazione container: ' . $response);
    }

    return $json['data']; // ORA restituisce un array come dichiarato
}


function waitForTask(?string $upid, array $auth, int $timeout=120, int $sleep=2): void {
    if(!$upid) return;
    $start=time();
    while(true){
        $url=PROXMOX_API_URL."/nodes/".PROXMOX_NODE."/tasks/$upid/status";
        $ch=curl_init($url);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
        curl_setopt($ch,CURLOPT_HTTPHEADER,[
            "CSRFPreventionToken: {$auth['csrf']}",
            "Cookie: PVEAuthCookie={$auth['ticket']}"
        ]);
        curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);
        $resp=curl_exec($ch);
        curl_close($ch);

        $json=json_decode($resp,true);
        $status=$json['data']['status']??'';
        if($status==='stopped') return;
        if(time()-$start>$timeout) throw new Exception("Timeout attesa task container");
        sleep($sleep);
    }
}

function getContainerStatus(int $vmid, array $auth): string {
    $url=PROXMOX_API_URL."/nodes/".PROXMOX_NODE."/lxc/$vmid/status/current";
    $ch=curl_init($url);
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
    curl_setopt($ch,CURLOPT_HTTPHEADER,[
        "CSRFPreventionToken: {$auth['csrf']}",
        "Cookie: PVEAuthCookie={$auth['ticket']}"
    ]);
    curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);
    $resp=curl_exec($ch);
    curl_close($ch);

    $json=json_decode($resp,true);
    return $json['data']['status']??'';
}

function waitForContainer(int $vmid,array $auth,int $timeout=60,int $sleep=2): void {
    $start=time();
    while(true){
        $status=getContainerStatus($vmid,$auth);
        if(in_array($status,['stopped','running'])) return;
        if(time()-$start>$timeout) throw new Exception("Timeout attesa container pronto");
        sleep($sleep);
    }
}

function startContainer(int $vmid,array $auth): void {
    $url=PROXMOX_API_URL."/nodes/".PROXMOX_NODE."/lxc/$vmid/status/start";
    $ch=curl_init($url);
    curl_setopt($ch,CURLOPT_POST,true);
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
    curl_setopt($ch,CURLOPT_HTTPHEADER,[
        "CSRFPreventionToken: {$auth['csrf']}",
        "Cookie: PVEAuthCookie={$auth['ticket']}"
    ]);
    curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);
    $resp=curl_exec($ch);
    curl_close($ch);
    $json=json_decode($resp,true);
    if(!isset($json['data'])) throw new Exception("Errore avvio container: ".($resp??'null'));
}

function installBedrockServer(string $privateIp,int $ramMb, PDO $pdo,int $serverId): void {
    // Qui va la logica di installazione Bedrock
    // esempio: ssh -> wget server ufficiale -> unzip -> genera start.sh
    $pdo->prepare("UPDATE servers SET status='installing' WHERE id=?")->execute([$serverId]);

    // Puoi aggiungere qui la chiamata SSH per installare Bedrock
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