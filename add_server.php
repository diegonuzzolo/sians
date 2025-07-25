<?php
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
        // Cerca VM libera
        $stmt = $pdo->prepare("SELECT * FROM minecraft_vms WHERE assigned_user_id IS NULL AND assigned_server_id IS NULL LIMIT 1");
        $stmt->execute();
        $vm = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$vm) {
            $error = "Nessuna VM libera disponibile al momento.";
        } else {
            // Inserisci server nel DB con modpack_id opzionale
            $stmt = $pdo->prepare("INSERT INTO servers (name, user_id, vm_id, proxmox_vmid, subdomain, status, modpack_id) VALUES (?, ?, ?, ?, ?, 'created', ?)");
            $successInsert = $stmt->execute([
                $serverName,
                $userId,
                $vm['id'],
                $vm['proxmox_vmid'],
                $subdomain,
                $modpackId
            ]);

            if (!$successInsert) {
                $error = "Errore durante la creazione del server.";
            } else {
                $serverId = $pdo->lastInsertId();

                // Aggiorna VM assegnata
                $stmt = $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = ?, assigned_server_id = ? WHERE id = ?");
                $stmt->execute([$userId, $serverId, $vm['id']]);

                // Esegui install_server.php da remoto via SSH (esempio base)
                $sshUser = 'diego';
                $sshKey = '/var/www/.ssh/id_rsa';
                $vmIp = $vm['ip'];
                $remoteScript = "/var/www/html/install_server.php";
                $mpIdForCommand = $modpackId ?? 0;

                $installCommand = "php $remoteScript $vmIp $serverId $version $mpIdForCommand";
                exec($installCommand, $output, $exitCode);

                if ($exitCode !== 0) {
                    $error = "Errore durante l'installazione del server Minecraft sulla VM. Output: " . implode("\n", $output);
                } else {
                    header("Location: create_tunnel_and_dns.php?server_id=$serverId&type=$type&modpack_id=$mpIdForCommand");
                    exit;
                }
            }
        }
    }
}

include("includes/header.php");
?>

<div class="container my-5" style="max-width: 600px;">
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white text-center">
            <h2 class="mb-0"><i class="bi bi-plus-circle"></i> Crea un nuovo Server Minecraft</h2>
        </div>
        <div class="card-body">
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Chiudi"></button>
                </div>
            <?php endif; ?>

            <form method="POST" action="create_server.php" novalidate>
                <div class="mb-4">
                    <label for="server_name" class="form-label fw-semibold">Nome Server <span class="text-danger">*</span></label>
                    <input type="text" name="server_name" id="server_name" class="form-control form-control-lg" placeholder="Es. IlMioServer" required value="<?= htmlspecialchars($_POST['server_name'] ?? '') ?>">
                </div>

                <div class="mb-4">
                    <label for="subdomain" class="form-label fw-semibold">Sottodominio <span class="text-danger">*</span></label>
                    <div class="input-group input-group-lg">
                        <input type="text" name="subdomain" id="subdomain" class="form-control" placeholder="mc123" required value="<?= htmlspecialchars($_POST['subdomain'] ?? '') ?>">
                        <span class="input-group-text"><?= DOMAIN ?></span>
                    </div>
                    <div class="form-text">Sarà l'indirizzo per collegarti al server (es. <code>mc123.<?= DOMAIN ?></code>).</div>
                </div>

                <div class="mb-4">
                    <label for="type" class="form-label fw-semibold">Tipo di Server <span class="text-danger">*</span></label>
                    <select name="type" id="type" class="form-select form-select-lg" required>
                        <option value="vanilla" <?= (($_POST['type'] ?? '') === 'vanilla') ? 'selected' : '' ?>>Vanilla</option>
                        <option value="bukkit" <?= (($_POST['type'] ?? '') === 'bukkit') ? 'selected' : '' ?>>Bukkit</option>
                        <option value="modpack" <?= (($_POST['type'] ?? '') === 'modpack') ? 'selected' : '' ?>>Modpack</option>
                    </select>
                </div>

                <div class="mb-4" id="modpack_selector" style="display: <?= (($_POST['type'] ?? '') === 'modpack') ? 'block' : 'none' ?>;">
                    <label for="modpack_id" class="form-label fw-semibold">Seleziona Modpack</label>
                    <select name="modpack_id" id="modpack_id" class="form-select form-select-lg">
                        <option value="">-- Nessun Modpack --</option>
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

                <div class="mb-4">
                    <label for="version" class="form-label fw-semibold">Versione Minecraft <span class="text-danger">*</span></label>
                    <select name="version" id="version" class="form-select form-select-lg" required>
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

                <div class="d-flex justify-content-between align-items-center">
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="bi bi-rocket-fill me-2"></i> Crea Server
                    </button>
                    <a href="dashboard.php" class="btn btn-outline-secondary btn-lg">Annulla</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('type').addEventListener('change', function () {
    const show = this.value === 'modpack';
    document.getElementById('modpack_selector').style.display = show ? 'block' : 'none';
});
</script>

<?php include("includes/footer.php"); ?>
