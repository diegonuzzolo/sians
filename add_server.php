<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'config/config.php';
require 'includes/auth.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postServerName = trim($_POST['server_name'] ?? '');
    $postType = $_POST['type'] ?? 'vanilla';
    $postVersion = $_POST['version'] ?? '';
    $postModpackId = $_POST['modpack_id'] ?? '';

    if (empty($postServerName) || empty($postType)) {
        $error = "❌ Compila tutti i campi obbligatori.";
    } else {
        // Cerca VM libera
        $stmt = $pdo->query("SELECT * FROM minecraft_vms WHERE assigned_user_id IS NULL LIMIT 1");
        $vm = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$vm) {
            $error = "❌ Nessuna VM disponibile.";
        } else {
            $vmIp = $vm['ip'];
            $vmId = $vm['proxmox_vmid'];
            $userId = $_SESSION['user_id'];

            $modpackName = '';
            $downloadUrl = '';
            $installMethod = '';
            $versionOrSlug = $postVersion;

            if ($postType === 'modpack') {
                // Carica dati modpack
                $stmt = $pdo->prepare("SELECT * FROM modpacks WHERE id = ?");
                $stmt->execute([$postModpackId]);
                $modpack = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$modpack) {
                    $error = "❌ Modpack non trovato.";
                } else {
                    $modpackName = $modpack['name'] ?? '';
                    $downloadUrl = $modpack['downloadUrl'] ?? '';
                    $installMethod = $modpack['installMethod'] ?? '';
                    $versionOrSlug = $modpack['forgeVersion'] ?? '';
                }
            } elseif (!in_array($postType, ['vanilla', 'bukkit'])) {
                $error = "❌ Tipo server non supportato.";
            }

            if (!$error) {
                // Inserisci server nel DB
                $stmt = $pdo->prepare("INSERT INTO servers 
                    (name, type, version, vm_id, user_id, modpack_id, proxmox_vmid, subdomain, tunnel_url, status, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");

                $stmt->execute([
                    $postServerName,
                    $postType,
                    ($postType === 'modpack') ? null : $postVersion,
                    $vm['id'],
                    $userId,
                    ($postType === 'modpack') ? $postModpackId : null,
                    $vmId,
                    null,
                    null,
                    'stopped'
                ]);

                $serverId = $pdo->lastInsertId();

                $sshKey = '/var/www/.ssh/id_rsa';
                $sshUser = 'diego';
                $remoteScript = '/home/diego/setup_server.sh';
                $remoteLog = "/home/diego/install_$vmId.log";

                $sshCmd = sprintf(
                    'ssh -i %s -o StrictHostKeyChecking=no %s@%s',
                    escapeshellarg($sshKey),
                    escapeshellarg($sshUser),
                    escapeshellarg($vmIp)
                );

                $args = [];
                if ($postType === 'vanilla') {
                    $args = [
                        'vanilla',
                        $postVersion,
                        '',
                        '',
                        $vmId
                    ];

                } elseif ($postType === 'modpack') {
                    $args = [
                        'modpack',
                        $versionOrSlug,
                        $downloadUrl,
                        $installMethod,
                        $vmId
                    ];

                } elseif ($postType === 'bukkit') {
                    $args = [
                        'bukkit',
                        $postVersion,
                        '',
                        '',
                        $vmId
                    ];
                }

                $escapedArgs = implode(' ', array_map('escapeshellarg', $args));
                $command = "$sshCmd \"bash $remoteScript $escapedArgs > $remoteLog 2>&1\" &";
                exec($command);

                // Assegna VM
                $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = ?, assigned_server_id = ? WHERE id = ?")
                    ->execute([$userId, $serverId, $vm['id']]);

                // Redirect con parametri
                $queryString = "server_id=$serverId";
                if ($postType === 'modpack') {
                    $queryString .= "&modpack_id=" . urlencode($postModpackId);
                } else {
                    $queryString .= "&version=" . urlencode($postVersion);
                }

                header("Location: create_tunnel_and_dns.php?$queryString");
                exit;
            }
        }
    }
}
?>
