<?php
session_start();
require 'config/config.php';
require 'includes/auth.php';

$error = '';
$postServerName = $_POST['server_name'] ?? '';
$postType = $_POST['type'] ?? 'vanilla';
$postVersion = $_POST['version'] ?? '';
$postModpackId = $_POST['modpack_id'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($postServerName)) {
        $error = "Il nome del server è obbligatorio.";
    } else {
        $stmt = $pdo->query("SELECT * FROM minecraft_vms WHERE assigned_user_id IS NULL AND assigned_server_id IS NULL LIMIT 1");
        $vm = $stmt->fetch();

        if (!$vm) {
            $error = "❌ Nessuna VM disponibile al momento.";
        } else {
            $type = $postType;
            $versionOrSlug = $postVersion;
            $downloadUrl = '';
            $installMethod = '';
            $modpackName = '';

            if ($type === 'modpack') {
                $stmt = $pdo->prepare("SELECT * FROM modpacks WHERE id = ?");
                $stmt->execute([$postModpackId]);
                $modpack = $stmt->fetch();

                if (!$modpack) {
                    $error = "❌ Modpack con ID $postModpackId non trovato.";
                } else {
                    $modpackName = $modpack['name'];
                    $downloadUrl = "https://api.modrinth.com/v2/project/" . $modpack['slug'] . "/version/" . $modpack['version_id'];
                    $installMethod = 'modrinth-fabric';
                    $versionOrSlug = $modpack['minecraftVersion'];
                }
            }

            if (!$error) {
                $stmt = $pdo->prepare("INSERT INTO servers (user_id, name, type, status) VALUES (?, ?, ?, 'installing')");
                $stmt->execute([$_SESSION['user_id'], $postServerName, $type]);
                $serverId = $pdo->lastInsertId();

                $stmt = $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = ?, assigned_server_id = ? WHERE id = ?");
                $stmt->execute([$_SESSION['user_id'], $serverId, $vm['id']]);

                $escapedServerName = escapeshellarg($postServerName);
                $escapedType = escapeshellarg($type);
                $escapedVersionOrSlug = escapeshellarg($versionOrSlug);
                $escapedDownloadUrl = escapeshellarg($downloadUrl);
                $escapedInstallMethod = escapeshellarg($installMethod);
                $escapedServerId = escapeshellarg($serverId);

                $command = "/usr/bin/php install_server.php $escapedServerId $escapedType $escapedVersionOrSlug $escapedDownloadUrl $escapedInstallMethod > /dev/null 2>&1 &";
                exec($command);

                header("Location: create_tunnel_and_dns.php?server_id=$serverId");
                exit;
            }
        }
    }
}

$stmt = $pdo->query("SELECT id, name, minecraftVersion FROM modpacks ORDER BY name");
$modpacks = $stmt->fetchAll();
?>

<?php include 'includes/header.php'; ?>

<div class="container mt-5">
    <h2>Crea nuovo server Minecraft</h2>
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" class="mt-4">
        <div class="mb-3">
            <label for="server_name" class="form-label">Nome Server</label>
            <input type="text" class="form-control" id="server_name" name="server_name" required>
        </div>

        <div class="mb-3">
            <label for="type" class="form-label">Tipo di Server</label>
            <select class="form-select" id="type" name="type" onchange="toggleFields()">
                <option value="vanilla">Vanilla</option>
                <option value="modpack">Modpack (Fabric - Modrinth)</option>
            </select>
        </div>

        <div class="mb-3" id="version-field">
            <label for="version" class="form-label">Versione Minecraft</label>
            <input type="text" class="form-control" id="version" name="version" placeholder="es: 1.20.1">
        </div>

        <div class="mb-3 d-none" id="modpack-field">
            <label for="modpack_id" class="form-label">Seleziona Modpack</label>
            <select class="form-select" id="modpack_id" name="modpack_id">
                <?php foreach ($modpacks as $modpack): ?>
                    <option value="<?= htmlspecialchars($modpack['id']) ?>">
                        <?= htmlspecialchars($modpack['name']) ?> (<?= htmlspecialchars($modpack['minecraftVersion']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="btn btn-success mt-3">Crea Server</button>
    </form>
</div>

<script>
function toggleFields() {
    const type = document.getElementById('type').value;
    const versionField = document.getElementById('version-field');
    const modpackField = document.getElementById('modpack-field');

    if (type === 'modpack') {
        versionField.classList.add('d-none');
        modpackField.classList.remove('d-none');
    } else {
        versionField.classList.remove('d-none');
        modpackField.classList.add('d-none');
    }
}

// Inizializza visibilità corretta al caricamento
document.addEventListener('DOMContentLoaded', toggleFields);
</script>

<?php include 'includes/footer.php'; ?>
