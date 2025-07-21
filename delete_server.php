<?php
require 'config/config.php';
require 'includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['server_id'])) {
    $server_id = intval($_POST['server_id']);

    // Recupera il VMID associato al server
    $stmt = $pdo->prepare("SELECT proxmox_vmid FROM servers WHERE id = ? AND user_id = ?");
    $stmt->execute([$server_id, $_SESSION['user_id']]);
    $server = $stmt->fetch();

    if ($server) {
        // Elimina il server
        $stmt = $pdo->prepare("DELETE FROM servers WHERE id = ? AND user_id = ?");
        $stmt->execute([$server_id, $_SESSION['user_id']]);

        // Libera la VM associata
        $stmt = $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = NULL, assigned_server_id = NULL WHERE proxmox_vmid = ?");
        $stmt->execute([$server['proxmox_vmid']]);
    }

    header("Location: dashboard.php");
    exit;
}
?>