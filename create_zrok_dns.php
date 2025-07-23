<?php
require 'config/config.php';

$server_id = $_GET['server_id'] ?? null;
if (!$server_id) {
    http_response_code(400);
    echo "ID server mancante.";
    exit;
}

// Recupera il server
$stmt = $pdo->prepare("SELECT * FROM servers WHERE id = ?");
$stmt->execute([$server_id]);
$server = $stmt->fetch();

if (!$server) {
    echo "Server non trovato.";
    exit;
}

$subdomain = $server['subdomain'];
$full_domain = $subdomain . ".sians.it";

// 1. Crea il tunnel Zrok
$tunnel_output = shell_exec("zrok share tcp 25565 --public --name {$subdomain} 2>&1");
if (!preg_match('/tcp:\/\/[^\s]+/', $tunnel_output, $matches)) {
    echo "Errore nella creazione tunnel zrok: " . htmlentities($tunnel_output);
    exit;
}
$tcp_endpoint = $matches[0];

// Estrai host e porta
$parsed = parse_url($tcp_endpoint);
$zrok_host = $parsed['host'];  // es: zrok.io
$zrok_port = $parsed['port'];  // es: 12345

// 2. Crea record DNS via Route64 API (sostituisci con API DNS tuo provider)
$apiToken = 'INSERISCI_TOKEN_ROUTE64';
$domain = 'sians.it';

// A record
$dns_payload = [
    'name' => $subdomain,
    'type' => 'A', // oppure CNAME se supportato
    'content' => gethostbyname($zrok_host),
    'ttl' => 300
];
$responseA = invia_dns_record($domain, $dns_payload, $apiToken);

// SRV record
$dns_payload_srv = [
    'name' => "_minecraft._tcp.{$subdomain}",
    'type' => 'SRV',
    'priority' => 0,
    'weight' => 5,
    'port' => $zrok_port,
    'target' => $zrok_host,
    'ttl' => 300
];
$responseSRV = invia_dns_record($domain, $dns_payload_srv, $apiToken);

// 3. Aggiorna database
$stmt = $pdo->prepare("UPDATE servers SET zrok_tcp_endpoint = ?, dns_created = 1 WHERE id = ?");
$stmt->execute([$tcp_endpoint, $server_id]);

echo "Tunnel e DNS configurati correttamente.";

function invia_dns_record($domain, $record, $token) {
    $url = "https://api.route64.org/domains/{$domain}/records";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$token}",
            "Content-Type: application/json"
        ],
        CURLOPT_POSTFIELDS => json_encode($record)
    ]);
    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        die("Errore CURL: " . curl_error($ch));
    }
    return json_decode($result, true);
}
