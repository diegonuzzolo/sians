<?php
session_start();
require 'config/config.php';
require 'includes/auth.php';

$serverName = $_POST['server_name'] ?? null;
$userId = $_SESSION['user_id'] ?? null;

if (!$serverName || !$userId) {
    http_response_code(400);
    echo "Parametri mancanti";
    exit;
}

// 1. Cerca VM libera con proxmox_vmid incluso
$stmt = $pdo->prepare("SELECT * FROM minecraft_vms WHERE assigned_user_id IS NULL AND assigned_server_id IS NULL LIMIT 1");
$stmt->execute();
$vm = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$vm) {
    echo "Nessuna VM libera disponibile";
    exit;
}

$vmId = $vm['id'];
$proxmoxVmid = $vm['proxmox_vmid'];
$vmIp = $vm['ip']; // IP della VM per SSH

// 2. Inserisci nuovo server con proxmox_vmid
$stmt = $pdo->prepare("INSERT INTO servers (name, user_id, vm_id, proxmox_vmid) VALUES (?, ?, ?, ?)");
$stmt->execute([$serverName, $userId, $vmId, $proxmoxVmid]);
$serverId = $pdo->lastInsertId();

// 3. Aggiorna VM come assegnata
$stmt = $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = ?, assigned_server_id = ? WHERE id = ?");
$stmt->execute([$userId, $serverId, $vmId]);

// 4. Avvia tunnel ngrok sulla VM via SSH
$sshKey = '/home/diego/.ssh/id_rsa'; // percorso chiave SSH privata
$sshUser = 'diego';

// Avvia ngrok in background (porta 25565 per Minecraft)
$commandStartNgrok = "ssh -i $sshKey -o StrictHostKeyChecking=no $sshUser@$vmIp 'nohup ngrok tcp 25565 > /dev/null 2>&1 &'";

exec($commandStartNgrok, $outputStart, $exitStart);
if ($exitStart !== 0) {
    echo "Errore nell'avviare ngrok sulla VM $vmIp";
    exit;
}

// Aspetta 5 secondi che ngrok si avvii
sleep(5);

// Ottieni tunnel da API locale ngrok
$commandGetTunnel = "ssh -i $sshKey -o StrictHostKeyChecking=no $sshUser@$vmIp 'curl -s http://127.0.0.1:4040/api/tunnels'";
$json = shell_exec($commandGetTunnel);
$data = json_decode($json, true);

if (!isset($data['tunnels']) || count($data['tunnels']) === 0) {
    echo "Nessun tunnel ngrok attivo trovato sulla VM $vmIp.";
    exit;
}

// Estrai URL pubblico tcp
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

// 5. Aggiorna server con tunnel_url
$stmt = $pdo->prepare("UPDATE servers SET tunnel_url = ? WHERE id = ?");
$stmt->execute([$tunnelUrl, $serverId]);

// 6. Risposta finale
echo "Server creato con successo. Tunnel attivo: $tunnelUrl";
