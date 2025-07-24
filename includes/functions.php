<?php



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
