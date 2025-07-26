<?php
session_start();
require 'config/config.php';
require 'includes/auth.php';

$postServerName = $_POST['server_name'] ?? '';
$postType = $_POST['type'] ?? 'vanilla';
$postVersion = $_POST['version'] ?? '';
$postModpackId = $_POST['modpack_id'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serverName = trim($postServerName);
    $type = trim($postType);
    $version = ($type !== 'modpack') ? trim($postVersion) : null;
    $modpackId = ($type === 'modpack' && !empty($postModpackId)) ? intval($postModpackId) : null;

    // Trova una VM libera
    $stmt = $pdo->prepare("SELECT * FROM minecraft_vms WHERE assigned_user_id IS NULL AND assigned_server_id IS NULL LIMIT 1");
    $stmt->execute();
    $vm = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($vm) {
        $userId = $_SESSION['user_id'];
        $vmId = $vm['id'];
        $vmIp = $vm['ip_address'];

        // Inserisce il nuovo server
        $insertStmt = $pdo->prepare("INSERT INTO servers (name, user_id, vm_id, ip_address, type, version, modpack_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insertStmt->execute([$serverName, $userId, $vmId, $vmIp, $type, $version, $modpackId]);

        $serverId = $pdo->lastInsertId();

        // Assegna VM all'utente e al server
        $updateStmt = $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = ?, assigned_server_id = ? WHERE id = ?");
        $updateStmt->execute([$userId, $serverId, $vmId]);

        // Lancia script installazione server
        $remoteScript = "/var/www/html/install_server.php";
        $escapedServerName = escapeshellarg($serverName);

        if ($type === 'modpack') {
            $installCommand = "php $remoteScript $vmIp $serverId $type $modpackId";
        } else {
            $installCommand = "php $remoteScript $vmIp $serverId $type $version";
        }

        exec($installCommand, $output, $returnCode);

        if ($returnCode === 0) {
            header("Location: create_tunnel_and_dns.php?server_id=$serverId");
            exit;
        } else {
            $error = "Errore durante l'installazione del server Minecraft sulla VM. Output: " . implode("\n", $output);
        }
    } else {
        $error = "Nessuna VM disponibile.";
    }
}
?>

<?php include 'includes/header.php'; ?>

<div class="container mt-5">
    <h1 class="mb-4">Crea un nuovo server Minecraft</h1>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= nl2br(htmlspecialchars($error)) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="mb-4">
            <label for="server_name">Nome Server</label>
            <input type="text" name="server_name" id="server_name" class="form-control" required value="<?= htmlspecialchars($postServerName) ?>" placeholder="Es. AvventuraMagica">
        </div>

        <div class="mb-4">
            <label for="type">Tipo di Server</label>
            <select name="type" id="type" class="form-select" required>
                <option value="vanilla" <?= $postType === 'vanilla' ? 'selected' : '' ?>>Vanilla</option>
                <option value="bukkit" <?= $postType === 'bukkit' ? 'selected' : '' ?>>Bukkit</option>
                <option value="modpack" <?= $postType === 'modpack' ? 'selected' : '' ?>>Modpack</option>
            </select>
        </div>

        <div class="mb-4" id="version_wrapper" style="display: <?= ($postType === 'vanilla' || $postType === 'bukkit') ? 'block' : 'none' ?>;">
            <label for="version">Versione Minecraft</label>
            <select name="version" id="version" class="form-select">
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
                    "1.7.10", "1.7.9", "1.7.8", "1.7.6", "1.7.5", "1.7.4", "1.7.2"
                ];
                foreach ($versions as $v) {
                    $selected = ($postVersion === $v) ? 'selected' : '';
                    echo "<option value=\"$v\" $selected>$v</option>";
                }
                ?>
            </select>
        </div>

        <div class="mb-4" id="modpack_selector" style="display: <?= $postType === 'modpack' ? 'block' : 'none' ?>;">
            <label for="modpack_id">Scegli Modpack</label>
            <select name="modpack_id" id="modpack_id" class="form-select">
                <option value="">-- Seleziona un Modpack --</option>
                <?php
                $stmt = $pdo->query("SELECT id, name, minecraftVersion FROM modpacks ORDER BY name");
                while ($modpack = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $selected = ($postModpackId == $modpack['id']) ? 'selected' : '';
                    $label = htmlspecialchars($modpack['name'] . " ({$modpack['minecraftVersion']})");
                    echo "<option value=\"{$modpack['id']}\" $selected>$label</option>";
                }
                ?>
            </select>
        </div>

        <div class="d-flex justify-content-center gap-3">
            <button type="submit" class="btn btn-primary shadow">Crea Server</button>
            <a href="dashboard.php" class="btn btn-secondary shadow">Annulla</a>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const typeSelect = document.getElementById('type');
    const versionWrapper = document.getElementById('version_wrapper');
    const modpackWrapper = document.getElementById('modpack_selector');
    const versionSelect = document.getElementById('version');
    const modpackSelect = document.getElementById('modpack_id');

    function updateVisibility() {
        const type = typeSelect.value;

        if (type === 'modpack') {
            versionWrapper.style.display = 'none';
            versionSelect.disabled = true;

            modpackWrapper.style.display = 'block';
            modpackSelect.disabled = false;
        } else {
            versionWrapper.style.display = 'block';
            versionSelect.disabled = false;

            modpackWrapper.style.display = 'none';
            modpackSelect.disabled = true;
            modpackSelect.value = '';
        }
    }

    typeSelect.addEventListener('change', updateVisibility);
    updateVisibility();
});
</script>

<?php include 'includes/footer.php'; ?>
