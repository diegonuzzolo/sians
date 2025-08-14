<?php
function deleteServer(PDO $pdo, int $serverId): bool|string {
    try {
        // --- 1. Recupera le risorse assegnate ---
        $stmt = $pdo->prepare("
            SELECT assigned_cpu_cores, assigned_ram_mb, assigned_storage_gb, proxmox_containerId, container_id 
            FROM servers WHERE id = ?
        ");
        $stmt->execute([$serverId]);
        $server = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$server) {
            return "Server non trovato";
        }

        // --- 2. Rilascia risorse nel DB ---
        $stmt = $pdo->prepare("
            UPDATE resources SET
            used_cpu_cores = used_cpu_cores - ?,
            used_ram_mb = used_ram_mb - ?,
            used_storage_gb = used_storage_gb - ?
            WHERE id = 1
        ");
        $stmt->execute([
            $server['assigned_cpu_cores'],
            $server['assigned_ram_mb'],
            $server['assigned_storage_gb']
        ]);

        // --- 3. Elimina container Proxmox ---
        $vmid = $server['proxmox_containerId'];
        $sshKey = "/var/www/.ssh/id_proxmox";
        $nodeIp = "192.168.1.251";

        exec("sudo ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp pct destroy $vmid", $output, $status);
        if ($status !== 0) {
            return "Errore eliminazione container Proxmox: " . implode("\n", $output);
        }

        // --- 4. Elimina record server dal DB ---
        $stmt = $pdo->prepare("DELETE FROM servers WHERE id = ?");
        $stmt->execute([$serverId]);

        return true;

    } catch (Exception $e) {
        return "Errore: " . $e->getMessage();
    }
}
