<?php
session_start();
require 'config/config.php';  // Qui tieni zone_id, api_token, dominio base ecc.

// Funzione per chiamare API Cloudflare
function cloudflare_api_request($zone_id, $endpoint, $method = 'GET', $data = null) {
    global $cloudflare_api_token;

    $url = "https://api.cloudflare.com/client/v4/zones/$zone_id/dns_records$endpoint";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $cloudflare_api_token",
        "Content-Type: application/json",
    ]);
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

// Recupera il server_id e il sottodominio scelto
$server_id = $_GET['server_id'] ?? null;
if (!$server_id) {
    die("server_id mancante");
}

// Prendi i dati del server e user_id (da DB)
$stmt = $pdo->prepare("SELECT s.subdomain, s.proxmox_vmid FROM servers s WHERE s.id = ? AND s.user_id = ?");
$stmt->execute([$server_id, $_SESSION['user_id']]);
$server = $stmt->fetch();

if (!$server) {
    die("Server non trovato o non autorizzato.");
}

$subdomain = $server['subdomain'];
if (!$subdomain) {
    die("Subdomain non configurato.");
}

// --- 1) Avvia ngrok TCP tunnel (porta Minecraft 25565 locale sulla VM) ---

// Costruiamo il comando (adatta se devi specificare un indirizzo IP VM o localhost)
$cmd = 'sudo -u www-data ngrok  tcp 25565 --log=stdout --log-level=info';

// Lancia il comando in background, cattura output
exec($cmd . ' 2>&1', $output, $ret);

if ($ret !== 0) {
    die("Errore avvio tunnel ngrok: " . implode("\n", $output));
}

// L’output contiene l’url TCP tipo tcp://1.tcp.ngrok.io:12345
// Cerchiamo la riga che contiene tcp:// con regex
$tunnelUrl = null;
foreach ($output as $line) {
    if (preg_match('/tcp:\/\/([a-z0-9\.\-]+):(\d+)/i', $line, $matches)) {
        $host = $matches[1];
        $port = $matches[2];
        $tunnelUrl = "$host:$port";
        break;
    }
}
if (!$tunnelUrl) {
    die("Non ho trovato l’URL del tunnel nell’output di ngrok.");
}

// --- 2) Cerchiamo se esiste già un record DNS per questo sottodominio ---

$zone_id = 'IL_TUO_ZONE_ID'; // da config
$cloudflare_api_token = 'IL_TUO_TOKEN_API'; // da config
$domain = 'sians.it';

// Cerca record A o CNAME per il sottodominio
$dns_records = cloudflare_api_request($zone_id, "?name=$subdomain.$domain");

if (!$dns_records['success']) {
    die("Errore API Cloudflare: " . json_encode($dns_records));
}

$existing_record = null;
foreach ($dns_records['result'] as $record) {
    if ($record['name'] === "$subdomain.$domain") {
        $existing_record = $record;
        break;
    }
}

// Dati record DNS da creare/aggiornare
$dns_data = [
    'type' => 'CNAME',  // uso CNAME perché ngrok fornisce host dinamico, non IP statico
    'name' => $subdomain,
    'content' => $host,  // solo host senza porta (Cloudflare non gestisce porte)
    'ttl' => 120,
    'proxied' => false
];

// --- 3) Crea o aggiorna il record DNS ---

if ($existing_record) {
    // Aggiorna record esistente
    $update = cloudflare_api_request($zone_id, '/' . $existing_record['id'], 'PUT', $dns_data);
    if (!$update['success']) {
        die("Errore aggiornamento DNS: " . json_encode($update));
    }
} else {
    // Crea nuovo record
    $create = cloudflare_api_request($zone_id, '', 'POST', $dns_data);
    if (!$create['success']) {
        die("Errore creazione DNS: " . json_encode($create));
    }
}

// --- 4) Salva nel DB ip/host/porta del tunnel per il server ---

$stmt = $pdo->prepare("UPDATE servers SET zrok_host = ?, zrok_port = ? WHERE id = ?");
$stmt->execute([$host, $port, $server_id]);

echo "Tunnel creato con successo!\n";
echo "Collegati con: $subdomain.$domain:$port\n";

?>
