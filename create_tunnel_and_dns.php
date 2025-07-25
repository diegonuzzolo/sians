<?php
require 'config/config.php';
require 'includes/auth.php';

// if (!isset($_GET['server_id'])) {
//     http_response_code(400);
//     die("Parametro server_id mancante");
// }

// $serverId = intval($_GET['server_id']);
// if ($serverId <= 0) {
//     http_response_code(400);
//     die("server_id non valido");
// }

// Recupera server e IP della VM
$stmt = $pdo->prepare("
    SELECT s.*, v.ip 
    FROM servers s
    JOIN minecraft_vms v ON s.vm_id = v.id
    WHERE s.id = ?
");
$stmt->execute([$serverId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    http_response_code(404);
    die("Server non trovato");
}

$vmIp = $server['ip'];
$sshUser = 'diego';
$sshKey = '/var/www/.ssh/id_rsa';

// 🧠 Avvia tunnel ngrok sulla VM (via SSH)
$commandStartTunnel = "ssh -i $sshKey -o StrictHostKeyChecking=no $sshUser@$vmIp 'nohup ngrok tcp 25565 > ngrok.log 2>&1 &'";

exec($commandStartTunnel, $output, $exitCode);

if ($exitCode !== 0) {
    die("Errore nell'avvio del tunnel ngrok sulla VM: $vmIp");
}

// ⏳ Attesa per avvio tunnel e apertura porta ngrokd
sleep(5);

// 🔄 Ottieni info tunnel dalla VM
$commandGetTunnel = "ssh -i $sshKey -o StrictHostKeyChecking=no $sshUser@$vmIp 'curl -s http://127.0.0.1:4040/api/tunnels'";
$tunnelJson = shell_exec($commandGetTunnel);
$tunnelData = json_decode($tunnelJson, true);

if (!$tunnelData || !isset($tunnelData['tunnels']) || count($tunnelData['tunnels']) === 0) {
    die("Nessun tunnel ngrok trovato sulla VM ($vmIp). Assicurati che ngrok sia installato e correttamente configurato.");
}

// 🎯 Cerca tunnel TCP e salva l'URL pubblico
$tunnelUrl = null;
foreach ($tunnelData['tunnels'] as $tunnel) {
    if ($tunnel['proto'] === 'tcp') {
        $tunnelUrl = $tunnel['public_url']; // es. tcp://xyz.ngrok.io:12345
        break;
    }
}

if (!$tunnelUrl) {
    die("Tunnel TCP non trovato nella risposta ngrok.");
}

// 💾 Salva nel DB
$updateStmt = $pdo->prepare("UPDATE servers SET tunnel_url = ?, status = 'online' WHERE id = ?");
$updateStmt->execute([$tunnelUrl, $serverId]);

// ✅ Output finale
echo "<h3>Tunnel creato con successo!</h3>";
echo "<p>🔗 URL pubblico: <code>$tunnelUrl</code></p>";
echo "<p><a href='dashboard.php' class='btn btn-success'>Vai alla dashboard</a></p>";
