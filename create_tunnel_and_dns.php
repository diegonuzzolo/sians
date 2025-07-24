<?php
require("config/config.php");
// CONFIGURAZIONE
$serverPort = 25565;
$subdomain = 'mc123'; // Cambialo dinamicamente se necessario
$domain = DOMAIN;

// Cloudflare
$cloudflareZoneId = CLOUDFLARE_ZONE_ID;
$cloudflareToken = CLOUDFLARE_API_TOKEN;

// 1. Avvia ngrok
shell_exec("nohup ngrok tcp {$serverPort} > /dev/null 2>&1 &");
sleep(3); // Attendi che ngrok parta

// 2. Recupera URL tunnel
$json = @file_get_contents('http://127.0.0.1:4040/api/tunnels');
if ($json === false) {
    exit("❌ Nessun tunnel ngrok TCP attivo trovato.\n");
}

$data = json_decode($json, true);
$tunnelUrl = null;

foreach ($data['tunnels'] as $tunnel) {
    if (str_starts_with($tunnel['public_url'], 'tcp://')) {
        $tunnelUrl = $tunnel['public_url'];
        break;
    }
}

if (!$tunnelUrl) {
    exit("❌ Tunnel TCP non trovato.\n");
}

// 3. Estrai host e porta
$tunnelParts = parse_url($tunnelUrl);
list($host, $port) = explode(':', $tunnelParts['host'] . ':' . $tunnelParts['port']);

// 4. Crea record DNS SRV via Cloudflare
$record = [
    'type' => 'SRV',
    'name' => "_minecraft._tcp.{$subdomain}",
    'data' => [
        'service' => '_minecraft',
        'proto' => '_tcp',
        'name' => $subdomain,
        'priority' => 0,
        'weight' => 5,
        'port' => (int)$port,
        'target' => $host
    ],
    'ttl' => 120
];

$ch = curl_init("https://api.cloudflare.com/client/v4/zones/{$cloudflareZoneId}/dns_records");
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer {$cloudflareToken}",
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($record));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

// 5. Mostra risultato
$responseData = json_decode($response, true);
if ($responseData['success']) {
    echo "✅ Tunnel creato su: {$tunnelUrl}\n";
    echo "✅ Record DNS SRV creato per: {$subdomain}.{$domain}\n";
} else {
    echo "❌ Errore nella creazione del record DNS:\n";
    print_r($responseData['errors']);
}
