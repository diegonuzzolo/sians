<?php
require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php';

$serverId = intval($_GET['server_id'] ?? 0);
if ($serverId <= 0) die(json_encode(['status' => 'invalid']));

$stmt = $pdo->prepare("
    SELECT s.id, s.status, c.proxmox_containerId
    FROM servers s
    JOIN containers c ON c.id = s.container_id
    WHERE s.id = ?
");
$stmt->execute([$serverId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) die(json_encode(['status' => 'notfound']));

$vmid = $server['proxmox_containerId'];
$status = $server['status'];

// Controlla file .status nel container
$statusFile = "/opt/minecraft-bedrock/.status";
$output = null;
$return_var = null;

exec("pct exec $vmid -- bash -c 'if [ -f $statusFile ]; then cat $statusFile; else echo \"{$status}\"; fi'", $output, $return_var);
$containerStatus = $output[0] ?? $status;

echo json_encode(['status' => $containerStatus]);
