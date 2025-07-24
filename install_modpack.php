<?php
require 'config/config.php';

$serverId = $_POST['server_id'] ?? null;
$modpackId = $_POST['modpack_id'] ?? null;

if (!$serverId || !$modpackId) {
    http_response_code(400);
    die("Missing parameters");
}

$stmt = $pdo->prepare("SELECT v.ip_address FROM servers s JOIN minecraft_vms v ON s.vm_id = v.id WHERE s.id = ?");
$stmt->execute([$serverId]);
$vmIp = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT name, download_url FROM modpack_repository WHERE id = ?");
$stmt->execute([$modpackId]);
$modpack = $stmt->fetch();

$tmpFile = tempnam("/tmp", "modpack_");
file_put_contents($tmpFile, file_get_contents($modpack['download_url']));

// Copia ed estrai sulla VM
$sshUser = "diego";
$sshKey = "/var/www/.ssh/id_rsa";
$remoteZip = "/home/diego/server/mods/modpack.zip";

exec("scp -i $sshKey -o StrictHostKeyChecking=no $tmpFile $sshUser@$vmIp:$remoteZip", $scpOut, $scpCode);
if ($scpCode !== 0) die("Errore copia modpack");

$cmdExtract = "ssh -i $sshKey -o StrictHostKeyChecking=no $sshUser@$vmIp 'unzip -o $remoteZip -d /home/diego/server/mods/'";
exec($cmdExtract, $out, $code);
if ($code !== 0) die("Errore estrazione modpack");

echo "✅ Modpack '{$modpack['name']}' installato con successo!";
