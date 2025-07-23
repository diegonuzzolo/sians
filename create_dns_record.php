<?php
require 'config/config.php'; // Qui dentro dovrai avere le costanti CLOUDFLARE_API_TOKEN e CLOUDFLARE_ZONE_ID

$subdomain = $_POST['subdomain'] ?? null; // es. mc123
$target = $_POST['target'] ?? null;       // es. 1.tcp.ngrok.io
$port = $_POST['port'] ?? null;           // es. 12345

if (!$subdomain || !$target || !$port) {
    http_response_code(400);
    echo json_encode(['error' => 'Dati mancanti']);
    exit;
}

$service = "_minecraft._tcp";
$name = "$service.$subdomain"; // _minecraft._tcp.mc123

$payload = [
    'type' => 'SRV',
    'data' => [
        'service' => '_minecraft',
        'proto' => '_tcp',
        'name' => $subdomain,
        'priority' => 10,
        'weight' => 5,
        'port' => (int)$port,
        'target' => $target
    ],
    'name' => $name,
    'ttl' => 120,
    'proxied' => false
];

$ch = curl_init("https://api.cloudflare.com/client/v4/zones/" . CLOUDFLARE_ZONE_ID . "/dns_records");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer " . CLOUDFLARE_API_TOKEN,
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200 || $httpCode === 201) {
    echo json_encode(['success' => true, 'message' => 'Record SRV creato']);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Errore nella creazione del record']);
}
