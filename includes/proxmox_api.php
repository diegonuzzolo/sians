<?php
function proxmoxApiRequest(string $method, string $endpoint, array $postData = null): array
{
    $url = PROXMOX_HOST . '/api2/json' . $endpoint;

    $headers = [
        'Authorization: PVEAPIToken=' . PROXMOX_API_TOKEN_ID . '=' . PROXMOX_API_TOKEN_SECRET,
        'Accept: application/json',
    ];

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    if ($postData !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    }

    $response = curl_exec($ch);

    if ($response === false) {
        throw new Exception('Errore cURL: ' . curl_error($ch));
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    file_put_contents('/tmp/proxmox_api.log', date('Y-m-d H:i:s') . " $method $url\nResponse HTTP $httpCode: $response\n\n", FILE_APPEND);

    $data = json_decode($response, true);

    if ($data === null) {
        throw new Exception("Risposta API non valida: $response");
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $errMsg = $data['errors'] ?? $data['message'] ?? 'Errore API sconosciuto';
        throw new Exception("Errore API: HTTP $httpCode - " . json_encode($errMsg));
    }

    return $data['data'] ?? [];
}
