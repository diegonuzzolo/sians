<?php

function avviaTunnelNgrokTcp(int $portaLocale = 25565): ?array {
    // Comando per avviare ngrok in background con output JSON
    $cmd = "ngrok tcp $portaLocale --log=stdout --log-format=json > /tmp/ngrok.log 2>&1 & echo $!";
    $pid = trim(shell_exec($cmd));
    if (!$pid) return null;

    sleep(5);

    $log = file_get_contents('/tmp/ngrok.log');
    if (!$log) return null;

    $lines = explode("\n", $log);
    foreach ($lines as $line) {
        $data = json_decode($line, true);
        if ($data && isset($data['msg']) && $data['msg'] === 'started tunnel') {
            $url = $data['url'] ?? null;
            if ($url && preg_match('#tcp://([\w\.]+):(\d+)#', $url, $matches)) {
                return ['host' => $matches[1], 'port' => intval($matches[2])];
            }
        }
    }
    return null;
}

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

function startNgrokTcpTunnel(int $localPort = 25565): ?array {
    // Comando per avviare ngrok in background (modifica il path se serve)
    $cmd = "ngrok   tcp $localPort --log=stdout > /dev/null 2>&1 &";

    // Avvia il processo ngrok
    exec($cmd);

    // Attendi qualche secondo per permettere a ngrok di partire
    sleep(5);

    // Ora ottieni lo stato del tunnel tramite API ngrok (default 4040)
    $statusJson = file_get_contents('http://127.0.0.1:4040/api/tunnels');
    if (!$statusJson) return null;

    $data = json_decode($statusJson, true);
    if (!$data || !isset($data['tunnels'])) return null;

    foreach ($data['tunnels'] as $tunnel) {
        if ($tunnel['proto'] === 'tcp') {
            // L'endpoint pubblico è tipo tcp://0.tcp.ngrok.io:xxxxx
            $publicUrl = $tunnel['public_url'];
            if (preg_match('/tcp:\/\/([^:]+):(\d+)/', $publicUrl, $matches)) {
                return [
                    'host' => $matches[1],
                    'port' => intval($matches[2])
                ];
            }
        }
    }

    return null;
}
