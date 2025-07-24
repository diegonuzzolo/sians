<?php
$vmIp = $_GET['vm_ip'] ?? null;

if (!$vmIp) {
    http_response_code(400);
    echo "IP VM mancante";
    exit;
}

// Percorso chiave SSH
$sshKey = '/home/diego/.ssh/id_rsa';  // o dov’è la tua chiave privata
$sshUser = 'diego';             // utente della VM

// Avvia ngrok TCP in background sulla VM
$commandStartNgrok = "ssh -i $sshKey -o StrictHostKeyChecking=no $sshUser@$vmIp 'nohup ngrok tcp 25565 > /dev/null 2>&1 &'";

// Avvio ngrok
exec($commandStartNgrok, $outputStart, $exitStart);
if ($exitStart !== 0) {
    echo "Errore nell'avviare ngrok sulla VM $vmIp";
    exit;
}

// Attendi 3-5 secondi che ngrok si avvii e esponga l'API locale
sleep(5);

// Recupera il tunnel via SSH + curl interno
$commandGetTunnel = "ssh -i $sshKey -o StrictHostKeyChecking=no $sshUser@$vmIp 'curl -s http://127.0.0.1:4040/api/tunnels'";

$json = shell_exec($commandGetTunnel);
$data = json_decode($json, true);

if (!isset($data['tunnels']) || count($data['tunnels']) == 0) {
    echo "Nessun tunnel ngrok attivo trovato sulla VM $vmIp.";
    exit;
}

// Estrai il public_url tcp
$tunnelUrl = null;
foreach ($data['tunnels'] as $tunnel) {
    if ($tunnel['proto'] === 'tcp') {
        $tunnelUrl = $tunnel['public_url'];
        break;
    }
}

if (!$tunnelUrl) {
    echo "Nessun tunnel TCP trovato.";
    exit;
}

echo "Tunnel attivo: $tunnelUrl";
