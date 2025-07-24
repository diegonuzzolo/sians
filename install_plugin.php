<?php
require 'config/config.php';

$serverId = $_POST['server_id'] ?? null;
$pluginId = $_POST['plugin_id'] ?? null;

if (!$serverId || !$pluginId) {
    http_response_code(400);
    die("Missing parameters");
}

// Recupera info server + VM
$stmt = $pdo->prepare("SELECT v.ip_address FROM servers s JOIN minecraft_vms v ON s.vm_id = v.id WHERE s.id = ?");
$stmt->execute([$serverId]);
$vmIp = $stmt->fetchColumn();

if (!$vmIp) die("VM IP not found");

$stmt = $pdo->prepare("SELECT name, download_url FROM plugin_repository WHERE id = ?");
$stmt->execute([$pluginId]);
$plugin = $stmt->fetch();

if (!$plugin) die("Plugin not found");

$tmpFile = tempnam("/tmp", "plugin_");
file_put_contents($tmpFile, file_get_contents($plugin['download_url']));

// Copia plugin sulla VM
$sshUser = "diego";
$sshKey = "/var/www/.ssh/id_rsa";

$remotePath = "/home/diego/server/plugins/" . basename($plugin['download_url']);

exec("scp -i $sshKey -o StrictHostKeyChecking=no $tmpFile $sshUser@$vmIp:$remotePath", $scpOut, $scpCode);
if ($scpCode !== 0) die("Errore copia plugin");

echo "✅ Plugin '{$plugin['name']}' installato con successo!";
