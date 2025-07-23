<?php
require 'config/config.php';
require 'includes/auth.php';

$subdomain = $_POST['subdomain'] ?? null;
$vmid = $_POST['vmid'] ?? null;

if (!$subdomain || !$vmid) {
    http_response_code(400);
    echo json_encode(['error' => 'Subdomain o VMID mancante']);
    exit;
}

// Comando per eseguire il tunnel ngrok
$ngrokCommand = "sudo -u www-data ngrok  tcp 192.168.1.$vmid:25565 --log=stdout --log-format=json";

// Avvia il processo ngrok e cattura l'output
$descriptorspec = [
    0 => ["pipe", "r"],
    1 => ["pipe", "w"],
    2 => ["pipe", "w"]
];

$process = proc_open($ngrokCommand, $descriptorspec, $pipes);

if (!is_resource($process)) {
    http_response_code(500);
    echo json_encode(['error' => 'Impossibile eseguire ngrok']);
    exit;
}

$publicHost = null;
$publicPort = null;

stream_set_blocking($pipes[1], false);
$timeout = time() + 20;

while (time() < $timeout && (!$publicHost || !$publicPort)) {
    $line = fgets($pipes[1]);
    if ($line) {
        $data = json_decode($line, true);
        if (isset($data['msg']) && $data['msg'] === 'started tunnel' && $data['obj'] === 'tunnels') {
            $config = $data['config']['addr'] ?? '';
            $url = $data['url'] ?? '';
            if (preg_match('/tcp:\/\/(.+):(\d+)/', $url, $matches)) {
                $publicHost = $matches[1];
                $publicPort = (int)$matches[2];
                break;
            }
        }
    }
    usleep(100000); // 100ms
}

// Chiudi il processo ngrok
proc_terminate($process);

if (!$publicHost || !$publicPort) {
    http_response_code(500);
    echo json_encode(['error' => 'Impossibile ottenere host/porta da ngrok']);
    exit;
}

// Funzione per inviare richieste a Cloudflare
function cloudflareRequest($method, $endpoint, $data = null) {
    $url = CLOUDFLARE_API_BASE . $endpoint;

    $headers = [
        "Authorization: Bearer " . CLOUDFLARE_API_TOKEN,
        "Content-Type: application/json"
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    if ($data) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

// Crea il record SRV (es. _minecraft._tcp.mc123.sians.it)
$recordName = '_minecraft._tcp.' . $subdomain;
$recordData = [
    'type' => 'SRV',
    'name' => $recordName,
    'data' => [
        'service'  => '_minecraft',
        'proto'    => '_tcp',
        'name'     => $subdomain,
        'priority' => 0,
        'weight'   => 5,
        'port'     => $publicPort,
        'target'   => $publicHost
    ],
    'ttl' => 120
];

// Controlla se esiste già un record SRV per questo sottodominio
$list = cloudflareRequest('GET', "/zones/" . CLOUDFLARE_ZONE_ID . "/dns_records?type=SRV&name=$recordName." . DOMAIN);
$existing = $list['result'][0]['id'] ?? null;

if ($existing) {
    $response = cloudflareRequest('PUT', "/zones/" . CLOUDFLARE_ZONE_ID . "/dns_records/$existing", $recordData);
} else {
    $response = cloudflareRequest('POST', "/zones/" . CLOUDFLARE_ZONE_ID . "/dns_records", $recordData);
}

if (!empty($response['success'])) {
    echo json_encode([
        'success' => true,
        'host' => $publicHost,
        'port' => $publicPort,
        'domain' => "$subdomain." . DOMAIN,
        'full_srv' => "_minecraft._tcp.$subdomain." . DOMAIN
    ]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Errore nel creare record DNS', 'details' => $response]);
}
