<?php



function createOrUpdateCloudflareDnsRecord(string $subdomain, string $targetHost): bool {
    // Usa le costanti definite nel config
    $zoneId = CLOUDFLARE_ZONE_ID;
    $apiToken = CLOUDFLARE_API_TOKEN;
    $apiBase = CLOUDFLARE_API_BASE;
    $fullDomain = $subdomain . '.' . DOMAIN;

    // Headers comuni per API Cloudflare
    $headers = [
        "Authorization: Bearer $apiToken",
        "Content-Type: application/json"
    ];

    // 1. Cerco se il record esiste già (tipo A)
    $url = "$apiBase/zones/$zoneId/dns_records?type=A&name=$fullDomain";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return false;
    }

    $data = json_decode($response, true);
    if (!$data || !isset($data['result'])) {
        return false;
    }

    $recordId = null;
    if (count($data['result']) > 0) {
        $recordId = $data['result'][0]['id'];
    }

    // Corpo della richiesta (record di tipo A, proxied a false)
    $postData = json_encode([
        "type" => "A",
        "name" => $fullDomain,
        "content" => $targetHost,
        "ttl" => 120,
        "proxied" => false
    ]);

    if ($recordId) {
        // Aggiorno il record esistente
        $url = "$apiBase/zones/$zoneId/dns_records/$recordId";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode === 200);
    } else {
        // Creo un nuovo record DNS
        $url = "$apiBase/zones/$zoneId/dns_records";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode === 200 || $httpCode === 201);
    }
}

function startNgrokTcpTunnel($localPort) {
    // Avvia ngrok in background
    $cmd = "sudo -u www-data /usr/local/bin/ngrok tcp $localPort --log=stdout --log-level=info --log-format=json 2>&1";

    // Lancia il comando e cattura output
    exec($cmd, $output, $return_var);

    if ($return_var !== 0) {
        // Errore esecuzione
        return false;
    }

    // Cerca nel log l’endpoint TCP esposto (ngrok scrive su stdout in JSON)
    foreach ($output as $line) {
        $json = json_decode($line, true);
        if ($json && isset($json['msg']) && $json['msg'] === 'started tunnel') {
            // L’endpoint è dentro 'url' es: tcp://1.tcp.ngrok.io:12345
            if (preg_match('#tcp://([^:]+):(\d+)#', $json['url'], $matches)) {
                return [
                    'host' => $matches[1],
                    'port' => intval($matches[2]),
                ];
            }
        }
    }

    return false;
}


function getNgrokTunnel() {
    $json = @file_get_contents('http://127.0.0.1:4040/api/tunnels');
    if ($json === false) return null;

    $data = json_decode($json, true);
    if (!isset($data['tunnels'])) return null;

    foreach ($data['tunnels'] as $tunnel) {
        if (strpos($tunnel['proto'], 'tcp') !== false) {
            $parts = parse_url($tunnel['public_url']);
            if (!isset($parts['host']) || !isset($parts['port'])) continue;

            return [
                'host' => $parts['host'],
                'port' => $parts['port']
            ];
        }
    }

    return null;
}
