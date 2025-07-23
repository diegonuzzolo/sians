<?php
require 'config/config.php'; // contiene $pdo
require 'includes/auth.php'; // verifica autenticazione

$serverId = $_GET['server_id'] ?? null;

if (!$serverId) {
    http_response_code(400);
    echo "ID server mancante";
    exit;
}

// 1. Recupera info del server e della VM
$stmt = $pdo->prepare("SELECT s.id, s.vm_id, v.internal_ip FROM servers s JOIN minecraft_vms v ON s.vm_id = v.id WHERE s.id = ? AND s.user_id = ?");
$stmt->execute([$serverId, $_SESSION['user_id']]);
$server = $stmt->fetch();

if (!$server) {
    http_response_code(404);
    echo "Server non trovato o non tuo";
    exit;
}

// 2. Crea tunnel con zrok
$tunnelOutput = shell_exec("zrok share public 127.0.0.1:25565 --backend-mode proxy 2>&1");
preg_match('/tcp:\/\/(.+):(\d+)/', $tunnelOutput, $matches);

if (!$matches) {
    echo "Errore nella creazione del tunnel zrok:\n$tunnelOutput";
    exit;
}

$zrokHost = $matches[1]; // es. zrok.io
$zrokPort = $matches[2]; // es. 12345

// 3. Genera sottodominio univoco
$subdomain = "mc" . $server['id']; // es. mc5
$fullDomain = "$subdomain.sians.it";

// 4. Imposta variabili per Cloudflare
$cfApiToken = 'GB_VVFoJoCoOi49P-ZeoNt7xf3kWAuWGPxDv1GMv';
$zoneId = 'ad73843747d02aa059e3a650182af704';

$headers = [
    "Authorization: Bearer $cfApiToken",
    "Content-Type: application/json"
];

// 5. Crea un record A/SRV su Cloudflare

// (a) Record A puntato all’host TCP di zrok (non serve davvero ma evitiamo errori)
$dataA = [
    "type" => "A",
    "name" => $subdomain,
    "content" => "192.0.2.1", // IP placeholder, Minecraft usa solo SRV
    "ttl" => 120,
    "proxied" => false
];
$ch = curl_init("https://api.cloudflare.com/client/v4/zones/$zoneId/dns_records");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($dataA),
]);
$responseA = curl_exec($ch);
curl_close($ch);

// (b) Record SRV per Minecraft (_minecraft._tcp.subdomain.sians.it)
$dataSRV = [
    "type" => "SRV",
    "data" => [
        "service" => "_minecraft",
        "proto" => "_tcp",
        "name" => $subdomain,
        "priority" => 0,
        "weight" => 0,
        "port" => (int)$zrokPort,
        "target" => $zrokHost
    ],
    "ttl" => 120
];
$ch = curl_init("https://api.cloudflare.com/client/v4/zones/$zoneId/dns_records");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($dataSRV),
]);
$responseSRV = curl_exec($ch);
curl_close($ch);

// 6. Salva il dominio nel DB
$stmt = $pdo->prepare("UPDATE servers SET subdomain = ? WHERE id = ?");
$stmt->execute([$fullDomain, $server['id']]);

echo "Tunnel e DNS configurati correttamente per $fullDomain!";
// header("Location: dashboard.php");
// exit;