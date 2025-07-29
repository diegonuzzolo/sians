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

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Crea un nuovo Server Minecraft</title>
    <link rel="stylesheet" href="assets/css/add_server.css">
</head>
<body>
<div class="container">
    <h1>🎮 Crea un nuovo Server Minecraft</h1>

    <?php if (!empty($error)): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <label for="server_name">Nome Server:</label>
        <input type="text" id="server_name" name="server_name" value="<?= htmlspecialchars($postServerName) ?>" required>

        <label for="type">Tipo di Server:</label>
        <select id="type" name="type" required onchange="toggleFields()">
            <option value="vanilla" <?= $postType === 'vanilla' ? 'selected' : '' ?>>Vanilla</option>
            <option value="modpack" <?= $postType === 'modpack' ? 'selected' : '' ?>>Modpack (Modrinth)</option>
        </select>

        <div id="versionField" style="display: none;">
            <label for="version">Versione Minecraft:</label>
            <select id="version" name="version" multiple size="6">
                <?php
                $versions = [
                    "1.21.8", "1.21", "1.20.4", "1.20", "1.19.4", "1.19", "1.18.2", "1.18", "1.17.1", "1.17",
                    "1.16.5", "1.16", "1.15.2", "1.15", "1.14.4", "1.14", "1.13.2", "1.13", "1.12.2", "1.12",
                    "1.11.2", "1.11", "1.10.2", "1.10", "1.9.4", "1.9", "1.8.9", "1.8", "1.7.10", "1.7.4"
                ];
                foreach ($versions as $version) {
                    echo "<option value=\"$version\" " . ($postVersion === $version ? 'selected' : '') . ">$version</option>";
                }
                ?>
            </select>
        </div>

        <div id="modpackField" style="display: none;">
            <label for="modpack_id">Modpack:</label>
            <select id="modpack_id" name="modpack_id">
                <option value="">-- Seleziona un Modpack --</option>
                <?php foreach ($modpacks as $modpack): ?>
                    <option value="<?= $modpack['id'] ?>" <?= $postModpackId == $modpack['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($modpack['name']) ?> (<?= htmlspecialchars($modpack['minecraftVersion']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit">🚀 Crea Server</button>
    </form>

    <div class="nav-buttons">
        <a class="btn" href="dashboard.php">🏠 Torna alla Dashboard</a>
        <a class="btn logout" href="logout.php">🚪 Esci</a>
    </div>
</div>

<script>
    function toggleFields() {
        const type = document.getElementById("type").value;
        document.getElementById("versionField").style.display = (type === "vanilla") ? "block" : "none";
        document.getElementById("modpackField").style.display = (type === "modpack") ? "block" : "none";
    }
    toggleFields(); // chiamata iniziale al caricamento
</script>
</body>
</html>
