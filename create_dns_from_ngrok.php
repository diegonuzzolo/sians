<?php
require 'config/config.php';

$subdomain = $_POST['subdomain'] ?? null;
$vmid = $_POST['vmid'] ?? null;

if (!$subdomain || !$vmid) {
    http_response_code(400);
    echo json_encode(['error' => 'Subdomain o VMID mancante']);
    exit;
}

// 1. Avvia il tunnel sudo -u www-data ngrok 
$cmd = "sudo -u www-data ngrok  tcp 127.0.0.1:25565 --log=stdout";
$descriptorspec = [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$process = proc_open($cmd, $descriptorspec, $pipes);
if (!is_resource($process)) {
    http_response_code(500);
    echo json_encode(['error' => 'Impossibile avviare ngrok']);
    exit;
}

// 2. Attendi l'output del tunnel
$host = null;
$port = null;
$start = time();
while ((time() - $start) < 10 && !$host) {
    $line = fgets($pipes[1]);
    if (preg_match('/tcp:\/\/([\w\.\-]+):(\d+)/', $line, $match)) {
        $host = $match[1];
        $port = $match[2];
        break;
    }
}

if (!$host || !$port) {
    proc_terminate($process);
    echo json_encode(['error' => 'Tunnel ngrok non generato']);
    exit;
}

// 3. Crea record SRV su Cloudflare
$service = "_minecraft._tcp.$subdomain." . DOMAIN;
$dnsData = [
    'type' => 'SRV',
    'name' => "_minecraft._tcp.$subdomain",
    'data' => [
        'service' => '_minecraft',
        'proto' => '_tcp',
        'name' => $subdomain,
        'priority' => 0,
        'weight' => 0,
        'port' => (int)$port,
        'target' => $host,
    ],
    'ttl' => 120,
    'proxied' => false
];

$ch = curl_init(CLOUDFLARE_API_BASE . "/zones/" . CLOUDFLARE_ZONE_ID . "/dns_records");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dnsData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . CLOUDFLARE_API_TOKEN,
    'Content-Type: application/json'
]);

$response = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code >= 200 && $code < 300) {
    echo json_encode([
        'success' => true,
        'host' => $host,
        'port' => $port,
        'subdomain' => "$subdomain." . DOMAIN
    ]);
} else {
    echo json_encode(['error' => 'Errore durante la creazione DNS su Cloudflare']);
}

// Termina il tunnel (oppure mantienilo in background a tua scelta)
proc_terminate($process);
