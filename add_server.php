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
    // Assicurati che modpackId sia null se il tipo non è 'modpack'
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

                $vmIp = $vm['ip'];
                $remoteScript = "/var/www/html/install_server.php";

                // Costruisci il comando di installazione in base al tipo di server
                // Passa sempre $type come argomento, in modo che lo script remoto sappia cosa aspettarsi
                $installCommand = "php $remoteScript $vmIp $serverId $type";

                if ($type === 'modpack') {
                    if (empty($modpackId)) {
                        $error = "Devi selezionare un modpack valido.";
                    } else {
                        // Aggiungi modpackId se il tipo è modpack
                        $installCommand .= " $modpackId";
                    }
                } else {
                    // Aggiungi la versione se il tipo è vanilla o bukkit
                    $installCommand .= " $version";
                }

                if (empty($error)) { // Esegui solo se non ci sono errori precedenti
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
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Crea Server Minecraft</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/add_server.css">
</head>
<body>

<div class="main-container">
    <div class="card-create-server shadow-lg">
        <h1>Crea il tuo Server Minecraft</h1>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
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
                <select name="version" id="version" class="form-select" required>
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
                        $label = htmlspecialchars($modpack['name'] . " (" . $modpack['minecraftVersion'] . ")");
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

    <div class="side-panel">
        <h3>Hai già un server?</h3>
        <a href="dashboard.php" class="btn btn-light btn-lg shadow"><i class="bi bi-house-door"></i> Vai alla Dashboard</a>
        <a href="logout.php" class="btn btn-danger btn-lg shadow"><i class="bi bi-box-arrow-right"></i> Esci</a>
    </div>
</div>

<script>
    const typeSelect = document.getElementById('type');
    const modpackSelector = document.getElementById('modpack_selector');
    const versionWrapper = document.getElementById('version_wrapper');

    function toggleFields() {
        if (typeSelect.value === 'modpack') {
            modpackSelector.style.display = 'block';
            versionWrapper.style.display = 'none';
        } else {
            modpackSelector.style.display = 'none';
            versionWrapper.style.display = 'block';
        }
    }

    typeSelect.addEventListener('change', toggleFields);
    toggleFields();
</script>

</body>
</html>