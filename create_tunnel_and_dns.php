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

// Recupera dati server e IP VM con join
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

$vmIp = $server['ip'] ?? null;
if (!$vmIp) {
    http_response_code(500);
    die("IP della VM mancante");
}

// Qui metti il tuo codice per creare il tunnel ngrok/zrok e configurare DNS
// Esempio basilare per avviare tunnel SSH remoto (modifica secondo le tue esigenze):

$sshUser = 'diego';
$sshKey = '/home/diego/.ssh/id_rsa'; // percorso chiave privata

// Comando per avviare tunnel ngrok in background sulla VM
$commandStartTunnel = "ssh -i $sshKey -o StrictHostKeyChecking=no $sshUser@$vmIp 'nohup ngrok tcp 25565 > /dev/null 2>&1 &'";

exec($commandStartTunnel, $output, $exitCode);
if ($exitCode !== 0) {
    die("Errore avvio tunnel ngrok sulla VM $vmIp");
}

// Attendi qualche secondo per sicurezza (opzionale)
sleep(5);

// Recupera info tunnel
$commandGetTunnel = "ssh -i $sshKey -o StrictHostKeyChecking=no $sshUser@$vmIp 'curl -s http://127.0.0.1:4040/api/tunnels'";
$tunnelJson = shell_exec($commandGetTunnel);
$tunnelData = json_decode($tunnelJson, true);

if (empty($tunnelData['tunnels'])) {
    die("Nessun tunnel attivo trovato");
}

// Cerca tunnel TCP
$tunnelUrl = null;
foreach ($tunnelData['tunnels'] as $tunnel) {
    if ($tunnel['proto'] === 'tcp') {
        $tunnelUrl = $tunnel['public_url'];
        break;
    }
}

if (!$tunnelUrl) {
    die("Tunnel TCP non trovato");
}

// Salva tunnel_url nel DB
$updateStmt = $pdo->prepare("UPDATE servers SET tunnel_url = ? WHERE id = ?");
$updateStmt->execute([$tunnelUrl, $serverId]);

echo "Tunnel creato con successo: $tunnelUrl<br>";
echo "<a href='dashboard.php'>Torna alla dashboard</a>";
