<?php
require 'config/config.php';

$subdomain = $_POST['subdomain'] ?? null;
$localPort = $_POST['local_port'] ?? 25565;

if (!$subdomain) {
    http_response_code(400);
    echo "Subdomain mancante";
    exit;
}

// Avvia ngrok TCP in background e salva JSON in un file temporaneo
$tmpFile = '/tmp/ngrok_tunnel_' . uniqid() . '.json';
$cmd = "/usr/local/bin/ngrok tcp $localPort --log=stdout --log-format=json > $tmpFile &";
exec($cmd);

// Aspetta un attimo che ngrok si avvii
sleep(3);

// Leggi il file temporaneo
$content = file_get_contents($tmpFile);

// Cerca l'ultima riga valida (può contenere + righe di log JSON)
$lines = explode("\n", $content);
$host = null;
$port = null;

foreach (array_reverse($lines) as $line) {
    $data = json_decode($line, true);
    if (!$data) continue;

    if (isset($data['msg']) && $data['msg'] === 'started tunnel' && isset($data['url'])) {
        // url es: tcp://1.tcp.ngrok.io:12345
        $url = $data['url'];
        $url = str_replace("tcp://", "", $url);
        [$host, $port] = explode(":", $url);
        break;
    }
}

unlink($tmpFile); // pulizia

if (!$host || !$port) {
    http_response_code(500);
    echo "Errore nell’ottenere l’host ngrok";
    exit;
}

// Chiamata a create_dns_record.php per creare il record SRV
$postData = http_build_query([
    'subdomain' => $subdomain,
    'target' => $host,
    'port' => $port
]);

$opts = [
    'http' => [
        'method'  => 'POST',
        'header'  => 'Content-Type: application/x-www-form-urlencoded',
        'content' => $postData
    ]
];

$context  = stream_context_create($opts);
$response = file_get_contents('http://localhost/create_dns_record.php', false, $context);

if ($response === false) {
    echo "Errore nella creazione del record DNS SRV";
    exit;
}

echo "✅ Tunnel creato su $host:$port\n";
echo "✅ Record SRV creato per _minecraft._tcp.$subdomain.sians.it\n";
