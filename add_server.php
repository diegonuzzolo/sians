<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'config/config.php';
require 'includes/auth.php';

$error = '';
$success = '';

$postServerName = $_POST['server_name'] ?? '';
$postType = $_POST['type'] ?? '';
$postVersion = $_POST['version'] ?? '';
$postModpackId = $_POST['modpack_id'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serverName = trim($postServerName);
    $userId = $_SESSION['user_id'] ?? null;
    $type = trim($postType);
    $version = trim($postVersion);
    $modpackId = ($type === 'modpack' && !empty($postModpackId)) ? intval($postModpackId) : null;

    if (!$serverName || !$userId) {
        $error = "Il nome del server e il login sono obbligatori.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM minecraft_vms WHERE assigned_user_id IS NULL AND assigned_server_id IS NULL LIMIT 1");
        $stmt->execute();
        $vm = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$vm) {
            $error = "Nessuna VM libera disponibile.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO servers (name, user_id, vm_id, proxmox_vmid, status, type, version, modpack_id) VALUES (?, ?, ?, ?, 'created', ?, ?, ?)");
            $successInsert = $stmt->execute([$serverName, $userId, $vm['id'], $vm['proxmox_vmid'], $type, $version, $modpackId]);

            if (!$successInsert) {
                $error = "Errore durante la creazione del server.";
            } else {
                $serverId = $pdo->lastInsertId();
                $stmt = $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = ?, assigned_server_id = ? WHERE id = ?");
                $stmt->execute([$userId, $serverId, $vm['id']]);

                $sshUser = 'diego';
                $vmIp = $vm['ip'];
                $remoteScript = "/var/www/html/install_server.php";

                // Costruisco il comando con il tipo e la versione/modpack corretti
                if ($type === 'modpack') {
                    $installCommand = "php $remoteScript $vmIp $serverId $type $modpackId";
                } else {
                    $installCommand = "php $remoteScript $vmIp $serverId $type $version";
                }

                // Debug: log del comando
                error_log("Comando installazione: $installCommand");

                exec($installCommand, $output, $exitCode);

                if ($exitCode !== 0) {
                    $error = "Errore durante l'installazione del server Minecraft sulla VM. Output: " . implode("\n", $output);
                } else {
                    header("Location: create_tunnel_and_dns.php?server_id=$serverId");
                    exit;
                }
            }
        }
    }
}
?>
