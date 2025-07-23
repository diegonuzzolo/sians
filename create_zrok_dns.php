<?php
require 'config/config.php';
require 'includes/auth.php';

function create_zrok_tunnel($subdomain) {
    // Qui dovrai lanciare il comando CLI o chiamare l'API di zrok per creare un tunnel TCP.
    // Ad esempio (assumendo CLI zrok configurato):
    $command = "zrok tcp create --port 25565 --name " . escapeshellarg($subdomain);
    exec($command, $output, $return_var);

    if ($return_var !== 0) {
        throw new Exception("Errore nella creazione del tunnel zrok: " . implode("\n", $output));
    }

    // L'output deve contenere host e porta pubblica del tunnel; parse qui sotto
    // Esempio ipotetico di output:
    // Tunnel created:
    // Host: mysubdomain.zrok.io
    // Port: 12345

    $host = null;
    $port = null;
    foreach ($output as $line) {
        if (preg_match('/Host:\s*(\S+)/', $line, $matches)) {
            $host = $matches[1];
        }
        if (preg_match('/Port:\s*(\d+)/', $line, $matches)) {
            $port = (int)$matches[1];
        }
    }

    if (!$host || !$port) {
        throw new Exception("Impossibile estrarre host e porta dal comando zrok");
    }

    return ['host' => $host, 'port' => $port];
}

function create_dns_srv_record($subdomain, $host, $port) {
    // Usa l'API Cloudflare per creare il record DNS SRV
    // Devi implementare la chiamata API qui con i tuoi token e zone ID

    // Esempio minimo (usa la tua libreria preferita o cURL)
    $api_token = 'GB_VVFoJoCoOi49P-ZeoNt7xf3kWAuWGPxDv1GMv';
    $zone_id = 'ad73843747d02aa059e3a650182af704';

    $url = "https://api.cloudflare.com/client/v4/zones/$zone_id/dns_records";

    $data = [
        'type' => 'SRV',
        'name' => $subdomain . '.sians.it',
        'data' => [
            'service' => '_minecraft',
            'proto' => '_tcp',
            'name' => $subdomain . '.sians.it',
            'priority' => 0,
            'weight' => 0,
            'port' => $port,
            'target' => $host,
        ],
        'ttl' => 300,
        'proxied' => false,
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $api_token",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    if (!$result['success']) {
        throw new Exception('Errore creazione record DNS: ' . json_encode($result['errors']));
    }

    return true;
}


try {
    if (!isset($_GET['server_id'])) {
        throw new Exception('ID server mancante');
    }

    $server_id = intval($_GET['server_id']);

    // Recupera il server dal DB per prendere subdomain e proxmox_vmid
    $stmt = $pdo->prepare("SELECT * FROM servers WHERE id = ? AND user_id = ?");
    $stmt->execute([$server_id, $_SESSION['user_id']]);
    $server = $stmt->fetch();

    if (!$server) {
        throw new Exception('Server non trovato o non autorizzato');
    }

    $subdomain = $server['subdomain'];

    // Crea tunnel zrok e ottieni host e porta
    $zrok = create_zrok_tunnel($subdomain);

    // Aggiorna il DB con host e porta tunnel
    $stmt = $pdo->prepare("UPDATE servers SET zrok_host = ?, zrok_port = ? WHERE id = ?");
    $stmt->execute([$zrok['host'], $zrok['port'], $server_id]);

    // Crea record DNS SRV su Cloudflare
    create_dns_srv_record($subdomain, $zrok['host'], $zrok['port']);

    // Redirect alla dashboard con successo
    header("Location: dashboard.php?msg=Server creato e DNS configurato");
    exit;

} catch (Exception $e) {
    echo "<h3>Errore:</h3><p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<a href='dashboard.php'>Torna alla dashboard</a>";
}
