<?php
require 'config/config.php';
require 'includes/auth.php';

function stopProxmoxVM($host, $tokenId, $tokenSecret, $node, $vmid) {
    $url = "$host/api2/json/nodes/$node/qemu/$vmid/status/shutdown";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: PVEAPIToken=$tokenId=$tokenSecret"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 || $httpCode === 500) {
        return true;
    }
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['server_id'])) {
    $server_id = intval($_POST['server_id']);

    $stmt = $pdo->prepare("SELECT proxmox_vmid FROM servers WHERE id = ? AND user_id = ?");
    $stmt->execute([$server_id, $_SESSION['user_id']]);
    $server = $stmt->fetch();

    if ($server) {
        $vmid = intval($server['proxmox_vmid']);

        $success = stopProxmoxVM(PROXMOX_HOST, PROXMOX_API_TOKEN_ID, PROXMOX_API_TOKEN_SECRET, PROXMOX_NODE, $vmid);

        if ($success) {
            $stmt = $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = NULL, assigned_server_id = NULL, status = 'stopped' WHERE proxmox_vmid = ?");
            $stmt->execute([$vmid]);

            $stmt = $pdo->prepare("DELETE FROM servers WHERE id = ? AND user_id = ?");
            $stmt->execute([$server_id, $_SESSION['user_id']]);

            header("Location: dashboard.php?msg=Server eliminato correttamente");
            exit;
        } else {
            header("Location: dashboard.php?error=Impossibile spegnere la VM su Proxmox");
            exit;
        }
    } else {
        exit("Server non trovato o non autorizzato.");
    }
}
