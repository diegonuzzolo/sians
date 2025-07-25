<?php
require 'config/config.php';
require 'includes/auth.php';

if (!isset($_GET['server_id'])) {
    http_response_code(400);
    die("Parametro server_id mancante");
}

$serverId = intval($_GET['server_id']);
if ($serverId <= 0) {
    http_response_code(400);
    die("server_id non valido");
}

// Recupera server e IP della VM
$stmt = $pdo->prepare("
    SELECT servers.*, minecraft_vms.ip 
    FROM servers 
    JOIN minecraft_vms ON servers.vm_id = minecraft_vms.id
    WHERE servers.id = ?
");
$stmt->execute([$serverId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    http_response_code(404);
    die("Server non trovato");
}

$vmIp = $server['ip'];
if (!$vmIp) {
    http_response_code(500);
    die("IP della VM mancante");
}

$sshUser = 'diego';
$sshKey = '/var/www/.ssh/id_rsa';

// Evita problemi di shell injection
$escapedSshKey = escapeshellarg($sshKey);
$escapedVmIp = escapeshellarg($vmIp);
$escapedUserAtIp = escapeshellarg("$sshUser@$vmIp");

// Comando per avviare tunnel ngrok in background sulla VM
$commandStartTunnel = "ssh -i $escapedSshKey -o StrictHostKeyChecking=no $sshUser@$vmIp 'nohup ngrok tcp 25565 > ngrok.log 2>&1 &'";

exec($commandStartTunnel, $outputStart, $exitCodeStart);

if ($exitCodeStart !== 0) {
    die("Errore nell'avvio del tunnel ngrok sulla VM: $vmIp");
}

// Attendi qualche secondo per sicurezza
sleep(5);

// Comando per ottenere info tunnel
$commandGetTunnel = "ssh -i $escapedSshKey -o StrictHostKeyChecking=no $sshUser@$vmIp 'curl -s http://127.0.0.1:4040/api/tunnels'";
exec($commandGetTunnel, $outputTunnel, $exitCodeTunnel);

if ($exitCodeTunnel !== 0) {
    die("Errore nel recupero dati tunnel dalla VM: $vmIp");
}

$tunnelJson = implode("\n", $outputTunnel);
$tunnelData = json_decode($tunnelJson, true);

if (!$tunnelData || !isset($tunnelData['tunnels']) || count($tunnelData['tunnels']) === 0) {
    die("Nessun tunnel ngrok trovato sulla VM ($vmIp). Assicurati che ngrok sia installato e correttamente configurato.");
}

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

// Salva tunnel_url nel DB
$updateStmt = $pdo->prepare("UPDATE servers SET tunnel_url = ?, status = 'online' WHERE id = ?");
$updateStmt->execute([$tunnelUrl, $serverId]);

echo "<h3>Tunnel creato con successo!</h3>";
echo "<p>🔗 URL pubblico: <code>" . htmlspecialchars($tunnelUrl) . "</code></p>";
echo "<p><a href='dashboard.php' class='btn btn-success'>Vai alla dashboard</a></p>";
