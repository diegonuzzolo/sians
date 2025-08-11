    <?php
    function proxmoxApiRequest(string $method, string $endpoint, array $data = []) {
    $baseUrl = "https://192.168.1.251:8006/api2/json";
    $tokenId = "root@pam!sians-token"; // es. user@realm!tokenid
    $tokenSecret = "04630a7b-98e3-4090-9226-b9b7dfa025b9";

    $headers = [
        "Authorization: PVEAPIToken=$tokenId=$tokenSecret",
        "Accept: application/json"
    ];

    $url = $baseUrl . $endpoint;

    $ch = curl_init();
    if ($method === 'GET' && !empty($data)) {
        $url .= '?' . http_build_query($data);
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

    if (in_array($method, ['POST', 'PUT']) && !empty($data)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }

    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception('Curl error: ' . curl_error($ch));
    }
    curl_close($ch);

    $json = json_decode($response, true);
    if (!$json || !isset($json['data'])) {
        throw new Exception("Invalid API response: $response");
    }

    return $json['data'];
}