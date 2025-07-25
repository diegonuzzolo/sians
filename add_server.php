<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'config/config.php'; // Connessione PDO
require 'includes/auth.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serverName = trim($_POST['server_name'] ?? '');
    $subdomain = trim($_POST['subdomain'] ?? '');
    $userId = $_SESSION['user_id'] ?? null;
    $type = trim($_POST['type'] ?? '');
    $version = trim($_POST['version'] ?? '');

    if (!$serverName || !$subdomain || !$userId) {
        $error = "Nome server, sottodominio e login sono obbligatori.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM minecraft_vms WHERE assigned_user_id IS NULL AND assigned_server_id IS NULL LIMIT 1");
        $stmt->execute();
        $vm = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$vm) {
            $error = "Nessuna VM libera disponibile.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO servers (name, user_id, vm_id, proxmox_vmid, subdomain, status) VALUES (?, ?, ?, ?, ?, 'created')");
            $successInsert = $stmt->execute([
                $serverName,
                $userId,
                $vm['id'],
                $vm['proxmox_vmid'],
                $subdomain
            ]);

            if (!$successInsert) {
                $error = "Errore durante la creazione del server.";
            } else {
                $serverId = $pdo->lastInsertId();

                $stmt = $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = ?, assigned_server_id = ? WHERE id = ?");
                $stmt->execute([$userId, $serverId, $vm['id']]);

                $sshUser = 'diego';
                $sshKey = '/var/www/.ssh/id_rsa';
                $vmIp = $vm['ip'];
                $remoteScript = "/var/www/html/install_server.php";
                $installCommand = "php $remoteScript $vmIp $serverId $version";
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

include("includes/header.php");
?>


<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

<form method="POST" action="add_server.php" class="p-4 bg-light rounded shadow-sm" style="max-width:700px; margin:auto;">
    <h2 class="mb-4 text-center fw-bold">Crea un nuovo Server Minecraft</h2>

    <div class="row g-3 mb-3">
        <div class="col-md-6 position-relative">
            <label for="server_name" class="form-label fw-semibold">Nome Server</label>
            <div class="input-group">
                <span class="input-group-text bg-primary text-white"><i class="bi bi-hdd-network"></i></span>
                <input type="text" name="server_name" id="server_name" class="form-control" placeholder="Es. MondoMagico" required>
            </div>
            <div class="form-text">Un nome unico per il tuo server.</div>
        </div>

        <div class="col-md-6 position-relative">
            <label for="subdomain" class="form-label fw-semibold">Sottodominio</label>
            <div class="input-group">
                <span class="input-group-text bg-primary text-white"><i class="bi bi-link-45deg"></i></span>
                <input type="text" name="subdomain" id="subdomain" class="form-control" placeholder="Es. mc123" required>
                <span class="input-group-text bg-secondary text-white"><?= DOMAIN ?></span>
            </div>
            <div class="form-text">Indirizzo per collegarti: <code>mc123.<?= DOMAIN ?></code></div>
        </div>

           <div class="mb-3">
        <label for="type" class="form-label">Tipo di Server</label>
        <select name="type" id="type" class="form-select" required>
            <option value="vanilla" <?= (($_POST['type'] ?? '') === 'vanilla') ? 'selected' : '' ?>>Vanilla</option>
            <option value="paper" <?= (($_POST['type'] ?? '') === 'paper') ? 'selected' : '' ?>>Bukkit (Paper)</option>
            <option value="modpack" <?= (($_POST['type'] ?? '') === 'modpack') ? 'selected' : '' ?>>Modpack</option>
        </select>
    </div>
        <div class="mb-3" id="modpack_selector" style="display: <?= (($_POST['type'] ?? '') === 'modpack') ? 'block' : 'none' ?>">
        <label for="modpack_id" class="form-label">Modpack</label>
        <select name="modpack_id" id="modpack_id" class="form-select">
            <option value="">-- Seleziona un Modpack --</option>
            <?php
            $stmt = $pdo->query("SELECT id, name, version FROM modpacks ORDER BY name");
            while ($modpack = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $selected = (intval($_POST['modpack_id'] ?? 0) === intval($modpack['id'])) ? 'selected' : '';
                $label = htmlspecialchars($modpack['name'] . " (" . $modpack['version'] . ")");
                echo "<option value=\"{$modpack['id']}\" $selected>$label</option>";
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
                $selected = (($_POST['version'] ?? '') === $v) ? 'selected' : '';
                echo "<option value=\"$v\" $selected>$v</option>";
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
