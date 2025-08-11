<?php
function proxmoxApiRequest(string $method, string $endpoint, array $postData = null) {
    $baseUrl = "https://IP_O_HOSTNAME_PROXMOX:8006/api2/json";
    $tokenName = "root@pam!mytoken";  // sostituisci con il tuo token name
    $tokenValue = "il_tuo_token_api";

    $url = $baseUrl . $endpoint;

    $headers = [
        "Authorization: PVEAPIToken=$tokenName=$tokenValue",
        "Accept: application/json"
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);  // solo per test, in produzione abilita

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($postData !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        }
    } elseif ($method === 'GET') {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    }

    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception("Errore CURL: " . curl_error($ch));
    }
    curl_close($ch);

    $json = json_decode($response, true);
    if (!$json) {
        throw new Exception("Risposta API non valida: $response");
    }
    if (isset($json['errors'])) {
        throw new Exception("Errore API Proxmox: " . json_encode($json['errors']));
    }

    return $json;
}
