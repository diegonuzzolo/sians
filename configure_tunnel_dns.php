<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'config/config.php';
require 'includes/auth.php';

$serverId = $_GET['server_id'] ?? null;

if (!$serverId) {
    http_response_code(400);
    echo "ID server mancante";
    exit;
}

// Recupera info del server e della VM
$stmt = $pdo->prepare(
    "SELECT s.id, s.proxmox_vmid, s.subdomain, s.zrok_tcp_endpoint, s.zrok_host, s.zrok_port, v.ip_address 
     FROM servers s 
     JOIN minecraft_vms v ON s.proxmox_vmid = v.proxmox_vmid 
     WHERE s.id = ? AND s.user_id = ?"
);
$stmt->execute([$serverId, $_SESSION['user_id']]);
$server = $stmt->fetch();

if (!$server) {
    http_response_code(404);
    echo "Server non trovato o non tuo";
    exit;
}

echo "Server trovato, Proxmox VM ID: " . $server['proxmox_vmid'] . ", VM IP: " . $server['ip_address'] . "\n";

// Crea tunnel con zrok
// Assicurati che il comando sia corretto per il tuo setup e che 'zrok' sia accessibile dal PHP
$tunnelOutput = shell_exec('sudo -u www-data HOME=/var/www zrok share public 127.0.0.1:25565 --backend-mode proxy </dev/null');
$command = 'sudo -u www-data HOME=/var/www zrok share public 127.0.0.1:25565 --backend-mode proxy </dev/null 2>&1';

exec($command, $output, $return_var);

preg_match('/tcp:\/\/(.+):(\d+)/', $tunnelOutput ?? '', $matches);


if (!$matches) {
    echo "Errore nella creazione del tunnel zrok:\n$tunnelOutput";
    exit;
}

$zrokHost = $matches[1]; // es. zrok.io
$zrokPort = $matches[2]; // es. 12345

// Genera sottodominio univoco
$subdomain = "mc" . $server['id']; // es. mc154
$fullDomain = "$subdomain.sians.it";

echo "Sottodominio generato: $fullDomain\n";

// Configurazione Cloudflare API
$cfApiToken = 'GB_VVFoJoCoOi49P-ZeoNt7xf3kWAuWGPxDv1GMv';
$zoneId = 'ad73843747d02aa059e3a650182af704';

$headers = [
    "Authorization: Bearer $cfApiToken",
    "Content-Type: application/json"
];

// Funzione per creare record DNS su Cloudflare
function createDnsRecord($zoneId, $data, $headers) {
    $ch = curl_init("https://api.cloudflare.com/client/v4/zones/$zoneId/dns_records");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

// 1) Creazione record A (con IP placeholder perché Minecraft usa SRV)
$dataA = [
    "type" => "A",
    "name" => $subdomain,
    "content" => "192.0.2.1", // IP placeholder (non deve puntare a IP reale)
    "ttl" => 120,
    "proxied" => false
];
$responseA = createDnsRecord($zoneId, $dataA, $headers);
echo "Risposta Cloudflare A record:\n";
print_r($responseA);

// 2) Creazione record SRV per Minecraft (_minecraft._tcp.subdomain)
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
$responseSRV = createDnsRecord($zoneId, $dataSRV, $headers);
echo "Risposta Cloudflare SRV record:\n";
print_r($responseSRV);

// Aggiorna tabella servers con i dati
$stmt = $pdo->prepare(
    "UPDATE servers SET 
       subdomain = ?, 
       zrok_tcp_endpoint = ?, 
       zrok_host = ?, 
       zrok_port = ?, 
       dns_created = 1
     WHERE id = ?"
);
$stmt->execute([
    $fullDomain,
    "tcp://$zrokHost:$zrokPort",
    $zrokHost,
    $zrokPort,
    $server['id']
]);

echo "Tunnel e DNS configurati correttamente per $fullDomain!\n";
