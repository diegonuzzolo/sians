<?php
require 'config/config.php'; // deve contenere CLOUDFLARE_API_TOKEN e ZONE_ID

function createOrUpdateSRV($subdomain, $target, $port) {
    $apiToken = CLOUDFLARE_API_TOKEN;
    $zoneId = CLOUDFLARE_ZONE_ID;
    $name = $subdomain . '.sians.it';

    // Cerchiamo un record esistente SRV
    $ch = curl_init("https://api.cloudflare.com/client/v4/zones/$zoneId/dns_records?type=SRV&name=$name");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $apiToken",
        "Content-Type: application/json"
    ]);
    $result = curl_exec($ch);
    curl_close($ch);

    $response = json_decode($result, true);
    $recordId = null;
    if (!empty($response['result'])) {
        $recordId = $response['result'][0]['id'];
    }

    // SRV record per Minecraft
    $srvData = [
        "type" => "SRV",
        "name" => "_minecraft._tcp.$name",
        "data" => [
            "service" => "_minecraft",
            "proto" => "_tcp",
            "name" => $name,
            "priority" => 0,
            "weight" => 5,
            "port" => intval($port),
            "target" => $target
        ],
        "ttl" => 120,
        "proxied" => false
    ];

    $url = "https://api.cloudflare.com/client/v4/zones/$zoneId/dns_records";
    $method = 'POST';

    if ($recordId) {
        $url .= "/$recordId";
        $method = 'PUT';
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($srvData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $apiToken",
        "Content-Type: application/json"
    ]);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        echo "✅ Record SRV per $name aggiornato con successo.\n";
    } else {
        echo "❌ Errore aggiornamento SRV ($httpCode): $result\n";
    }
}

// Esempio dinamico: dopo la creazione tunnel
$subdomain = $_POST['subdomain'] ?? null; // es: mc123
$target = $_POST['target'] ?? null;       // es: 1.tcp.ngrok.io
$port = $_POST['port'] ?? null;           // es: 12345

if (!$subdomain || !$target || !$port) {
    http_response_code(400);
    echo "Parametri mancanti.";
    exit;
}

createOrUpdateSRV($subdomain, $target, $port);
