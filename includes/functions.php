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
    $cmd = "sudo -u www-data HOME=/var/www /usr/local/bin/ngrok tcp $localPort --log=stdout > /tmp/ngrok_$localPort.log 2>&1 & echo $!";
    $output = shell_exec($cmd);
    if (!$output) {
        error_log("Errore avvio ngrok. Comando eseguito: $cmd");
        return false;
    }

    // Aspetta un po’ per dare tempo a ngrok di avviarsi
    sleep(2);

    // Controlla se il processo è in esecuzione
    $pid = trim($output);
    if (!is_numeric($pid)) {
        error_log("PID ngrok non valido: $output");
        return false;
    }

    return $pid;
}

