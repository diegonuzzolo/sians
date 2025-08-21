<?php

/**
 * Elimina i record DNS (A + SRV) su Cloudflare per un server Bedrock
 *
 * @param string $serverName Nome del server (es: "mc1" → mc1.sians.it)
 * @return bool True se ha eliminato correttamente, False se nessun record trovato
 */
function deleteCloudflareDnsBedrock(string $serverName): bool {
    $subdomain = "{$serverName}.sians.it";

    $headers = [
        "Authorization: Bearer " . CLOUDFLARE_API_TOKEN,
        "Content-Type: application/json"
    ];

    $deletedSomething = false;

    // --- 1. Elimina record A (es: mc1.sians.it)
    $searchUrl = "https://api.cloudflare.com/client/v4/zones/" . CLOUDFLARE_ZONE_ID . "/dns_records?name={$subdomain}";
    $resp = curlRequest($searchUrl, $headers);
    $result = json_decode($resp, true);

    if (!empty($result['result'])) {
        foreach ($result['result'] as $record) {
            $deleteUrl = "https://api.cloudflare.com/client/v4/zones/" . CLOUDFLARE_ZONE_ID . "/dns_records/" . $record['id'];
            curlRequest($deleteUrl, $headers, "DELETE");
            $deletedSomething = true;
        }
    }

    // --- 2. Elimina record SRV (_minecraft._udp.mc1.sians.it)
    $srvSearch = "https://api.cloudflare.com/client/v4/zones/" . CLOUDFLARE_ZONE_ID . "/dns_records?name=_minecraft._udp.{$subdomain}";
    $resp = curlRequest($srvSearch, $headers);
    $resultSrv = json_decode($resp, true);

    if (!empty($resultSrv['result'])) {
        foreach ($resultSrv['result'] as $record) {
            $deleteUrl = "https://api.cloudflare.com/client/v4/zones/" . CLOUDFLARE_ZONE_ID . "/dns_records/" . $record['id'];
            curlRequest($deleteUrl, $headers, "DELETE");
            $deletedSomething = true;
        }
    }

    return $deletedSomething;
}

/**
 * Helper CURL per GET/DELETE
 */
function curlRequest(string $url, array $headers, string $method = "GET"): string {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($method === "DELETE") {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    }
    $resp = curl_exec($ch);
    curl_close($ch);
    return $resp;
}


function deleteCloudflareResources(string $serverName, string $tunnelName, string $tunnelUuid) {
    $hostname = $serverName . '.sians.it';
    
    // --- PARTE 1: Eliminazione del record DNS tramite API ---
    
    // 1a. Trova l'ID del record DNS
    $findUrl = "https://api.cloudflare.com/client/v4/zones/" . CLOUDFLARE_ZONE_ID . "/dns_records?name=" . urlencode($hostname);
    $ch_find = curl_init($findUrl);
    curl_setopt($ch_find, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_find, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . CLOUDFLARE_API_TOKEN,
        "Content-Type: application/json"
    ]);
    $response = curl_exec($ch_find);
    if (curl_errno($ch_find)) {
        throw new Exception("Errore cURL nella ricerca DNS: " . curl_error($ch_find));
    }
    $data = json_decode($response, true);
    curl_close($ch_find);

    // 1b. Se il record esiste, eliminalo
    if (isset($data['success']) && $data['success'] === true && !empty($data['result'])) {
        $recordId = $data['result'][0]['id'];
        
        $deleteUrl = "https://api.cloudflare.com/client/v4/zones/" . CLOUDFLARE_ZONE_ID . "/dns_records/" . $recordId;
        $ch_del = curl_init($deleteUrl);
        curl_setopt($ch_del, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($ch_del, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_del, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . CLOUDFLARE_API_TOKEN,
            "Content-Type: application/json"
        ]);
        $del_response = curl_exec($ch_del);
        if (curl_errno($ch_del)) {
            throw new Exception("Errore cURL nell'eliminazione DNS: " . curl_error($ch_del));
        }
        $del_data = json_decode($del_response, true);
        curl_close($ch_del);

        if (!isset($del_data['success']) || $del_data['success'] !== true) {
            throw new Exception("Errore API Cloudflare: Impossibile eliminare il record DNS.");
        }
    }
    // Se il record non viene trovato (result è vuoto), non facciamo nulla e procediamo.

    // --- PARTE 2: Pulizia del tunnel e dei file locali tramite CLI ---
    exec("sudo /usr/bin/systemctl disable --now cloudflared@{$tunnelName}.service");
    exec("sudo /usr/bin/cloudflared tunnel delete {$tunnelName}");
    exec("sudo /bin/rm /root/.cloudflared/{$tunnelName}.yml");
   
}