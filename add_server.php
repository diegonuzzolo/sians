<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'config/config.php';
require 'includes/auth.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serverName = trim($_POST['server_name'] ?? '');
    $subdomain = trim($_POST['subdomain'] ?? '');
    $userId = $_SESSION['user_id'] ?? null;
    $type = trim($_POST['type'] ?? '');
    $version = trim($_POST['version'] ?? '');
    $modpackId = !empty($_POST['modpack_id']) ? intval($_POST['modpack_id']) : null;

    if (!$serverName || !$subdomain || !$userId) {
        $error = "Nome server, sottodominio e login sono obbligatori.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM minecraft_vms WHERE assigned_user_id IS NULL AND assigned_server_id IS NULL LIMIT 1");
        $stmt->execute();
        $vm = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$vm) {
            $error = "Nessuna VM libera disponibile.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO servers (name, user_id, vm_id, proxmox_vmid, subdomain, status, modpack_id, type, version) VALUES (?, ?, ?, ?, ?, 'created', ?, ?, ?)");
            $successInsert = $stmt->execute([
                $serverName,
                $userId,
                $vm['id'],
                $vm['proxmox_vmid'],
                $subdomain,
                $modpackId,
                $type,
                $version
            ]);

            if (!$successInsert) {
                $error = "Errore durante la creazione del server.";
            } else {
                $serverId = $pdo->lastInsertId();

                $stmt = $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = ?, assigned_server_id = ? WHERE id = ?");
                $stmt->execute([$userId, $serverId, $vm['id']]);

                // Esegui installazione server
                $sshUser = 'diego';
                $vmIp = $vm['ip'];
                $installCommand = "php /var/www/html/install_server.php $vmIp $serverId $version";

                exec($installCommand, $output, $exitCode);

                if ($exitCode !== 0) {
                    $error = "Errore durante l'installazione del server Minecraft. Output:\n" . implode("\n", $output);
                } else {
                    header("Location: create_tunnel_and_dns.php?server_id=$serverId&type=$type&modpack_id=$modpackId");
                    exit;
                }
            }
        }
    }
}

include("includes/header.php");
?>

<h1>Aggiungi un nuovo Server Minecraft</h1>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="POST" action="">
    <div class="mb-3">
        <label for="server_name" class="form-label">Nome del Server</label>
        <input type="text" name="server_name" id="server_name" class="form-control" required value="<?= htmlspecialchars($_POST['server_name'] ?? '') ?>" />
    </div>

    <!-- <div class="mb-3">
        <label for="subdomain" class="form-label">Sottodominio</label>
        <div class="input-group">
            <input type="text" name="subdomain" id="subdomain" class="form-control" required value="<?= htmlspecialchars($_POST['subdomain'] ?? '') ?>" />
            <span class="input-group-text"><?= DOMAIN ?></span>
        </div>
    </div> -->

    <div class="mb-3">
        <label for="type" class="form-label">Tipo di Server</label>
        <select name="type" id="type" class="form-select" required>
            <option value="vanilla">Vanilla</option>
            <option value="paper">Paper</option>
            <option value="modpack">Modpack</option>
        </select>
    </div>

    <div class="mb-3" id="modpack_selector" style="display:none;">
        <label for="modpack_id" class="form-label">Modpack</label>
        <select name="modpack_id" id="modpack_id" class="form-select">
            <option value="">-- Seleziona un Modpack --</option>
            <?php
            $stmt = $pdo->query("SELECT id, name, version FROM modpacks ORDER BY name");
            while ($modpack = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $label = htmlspecialchars($modpack['name'] . " (" . $modpack['version'] . ")");
                echo "<option value=\"{$modpack['id']}\">$label</option>";
            }
            ?>
        </select>
    </div>

<div class="mb-3">
    <label for="version" class="form-label">Versione Minecraft</label>
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
            "1.7.10", "1.7.9", "1.7.8", "1.7.6", "1.7.5", "1.7.4", "1.7.2",
            "1.6.4", "1.6.2", "1.6.1",
            "1.5.2", "1.5.1", "1.5",
            "1.4.7", "1.4.6", "1.4.5", "1.4.4", "1.4.3", "1.4.2",
            "1.3.2", "1.3.1",
            "1.2.5", "1.2.4", "1.2.3", "1.2.2", "1.2.1",
            "1.1", "1.0"
        ];
        foreach ($versions as $v) {
            echo "<option value=\"$v\">$v</option>";
        }
        ?>
    </select>
</div>


    <button type="submit" class="btn btn-primary">Crea Server</button>
    <a href="dashboard.php" class="btn btn-secondary ms-2">Annulla</a>
</form>

<script>
document.getElementById('type').addEventListener('change', function () {
    const show = this.value === 'modpack';
    document.getElementById('modpack_selector').style.display = show ? 'block' : 'none';
});
</script>

<?php include("includes/footer.php"); ?>
