<?php
session_start();
require 'config/config.php';
require 'includes/auth.php';

$error = '';
$postServerName = $_POST['server_name'] ?? '';
$postType = $_POST['type'] ?? 'vanilla';
$postVersion = $_POST['version'] ?? '';
$postModpackId = $_POST['modpack_id'] ?? '';

// Carica i modpack per dropdown
$stmt = $pdo->query("SELECT * FROM modpacks ORDER BY name ASC");
$modpacks = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($postServerName)) {
        $error = "❌ Nome server mancante.";
    } elseif ($postType === 'vanilla' && empty($postVersion)) {
        $error = "❌ Seleziona una versione per Vanilla.";
    } elseif ($postType === 'modpack' && empty($postModpackId)) {
        $error = "❌ Seleziona un Modpack.";
    } else {
        // Recupera una VM disponibile
        $stmt = $pdo->prepare("SELECT * FROM minecraft_vms WHERE assigned_user_id IS NULL AND assigned_server_id IS NULL LIMIT 1");
        $stmt->execute();
        $vm = $stmt->fetch();

        if (!$vm) {
            $error = "❌ Nessuna VM disponibile al momento.";
        } else {
            $userId = $_SESSION['user_id'];
            $vmId = $vm['id'];
            $proxmoxVmid = $vm['proxmox_vmid'];
            $createdAt = date('Y-m-d H:i:s');

            // Inserisci nuovo server
            $stmt = $pdo->prepare("INSERT INTO servers (name, type, version, modpack_id, user_id, vm_id, proxmox_vmid, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'installing', ?)");
            $stmt->execute([$postServerName, $postType, $postVersion, $postModpackId, $userId, $vmId, $proxmoxVmid, $createdAt]);

            $serverId = $pdo->lastInsertId();

            // Assegna la VM
            $stmt = $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = ?, assigned_server_id = ? WHERE id = ?");
            $stmt->execute([$userId, $serverId, $vmId]);

            // Reindirizza all'installazione
            header("Location: install_server.php?server_id=$serverId");
            exit;
        }
    }
}
?>

<?php


$versions = [
              "1.21.8", "1.21.7", "1.21.6", "1.21.5", "1.21.4", "1.21.3", "1.21.2", "1.21.1", "1.21",
              "1.20.6", "1.20.5", "1.20.4", "1.20.3", "1.20.2", "1.20.1", "1.20",
              "1.19.4", "1.19.3", "1.19.2", "1.19.1", "1.19",
              "1.18.2", "1.18.1", "1.18",
              "1.17.1", "1.17",
              "1.16.5", "1.16.4", "1.16.3", "1.16.2", "1.16.1", "1.16",
              "1.15.2", "1.15.1", "1.15",
              "1.14.4", "1.14.3", "1.14.2", "1.14.1", "1.14",
              "1.13.2", "1.13.1", "1.13",
              "1.12.2", "1.12.1", "1.12",
              "1.11.2", "1.11.1", "1.11",
              "1.10.2", "1.10.1", "1.10",
              "1.9.4", "1.9.3", "1.9.2", "1.9.1", "1.9",
              "1.8.9", "1.8.8", "1.8.7", "1.8.6", "1.8.5", "1.8.4", "1.8.3", "1.8.2", "1.8.1", "1.8",
              "1.7.10", "1.7.9", "1.7.8", "1.7.6", "1.7.5", "1.7.4"
];
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Crea Server Minecraft</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/css/add_server.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
<div class="main-container">
    <div class="card-create-server">
        <h1>Crea un Nuovo Server</h1>
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?= $error ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="mb-3">
                <label for="server_name">Nome Server</label>
                <input type="text" class="form-control" name="server_name" required>
            </div>
            <div class="mb-3">
                <label for="type">Tipo Server</label>
                <select name="type" class="form-select" required onchange="toggleFields()">
                    <option value="vanilla">Vanilla</option>
                    <option value="bukkit">Bukkit</option>
                    <option value="modpack">Modpack</option>
                </select>
            </div>
            <div class="mb-3" id="version-group">
                <label for="version">Versione Minecraft</label>
                <select name="version" class="form-select">
                   <?php
                   foreach ($versions as $ver) {
            echo "<option value=\"$ver\">$ver</option>";
        }
        ?>
                   ?>
                </select>
            </div>
            <div class="mb-3" id="modpack-group" style="display:none;">
                <label for="modpack_id">Modpack</label>
                <select name="modpack_id" class="form-select">
                    <?php foreach ($modpacks as $modpack): ?>
                        <option value="<?= $modpack['id'] ?>"><?= htmlspecialchars($modpack['name']) ?> (<?= $modpack['version'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary w-100">Crea Server</button>
        </form>
    </div>

    <div class="side-panel">
        <h3>Azioni Rapide</h3>
        <a href="dashboard.php" class="btn-accent dashboard">
            <i class="bi bi-speedometer2"></i> Torna alla Dashboard
        </a>
        <a href="logout.php" class="btn-accent logout">
            <i class="bi bi-box-arrow-right"></i> Logout
        </a>
    </div>
</div>

<script>
    function toggleFields() {
        const type = document.querySelector('select[name="type"]').value;
        const versionGroup = document.getElementById('version-group');
        const modpackGroup = document.getElementById('modpack-group');

        if (type === 'modpack') {
            versionGroup.style.display = 'none';
            modpackGroup.style.display = 'block';
        } else {
            versionGroup.style.display = 'block';
            modpackGroup.style.display = 'none';
        }
    }

    // Init state on load
    document.addEventListener("DOMContentLoaded", toggleFields);
</script>
</body>
</html>
