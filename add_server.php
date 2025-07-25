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

<h1>Aggiungi un nuovo Server Minecraft</h1>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="POST" action="add_server.php">
    <div class="row">
        <div class="mb-3 col-md-6">
            <label for="server_name" class="form-label">Nome Server</label>
            <input type="text" name="server_name" class="form-control" required>
        </div>

        <div class="mb-3 col-md-6">
            <label for="type" class="form-label">Tipo</label>
            <select name="type" id="type" class="form-select" required>
                <option value="vanilla">Vanilla</option>
                <option value="spigot">Spigot</option>
                <option value="modpack">Modpack</option>
            </select>
        </div>

        <div class="mb-3 col-md-6">
            <label for="version" class="form-label">Versione Minecraft</label>
            <select name="version" id="version" class="form-select" required>
                <?php
                foreach ($versions as $v) {
                    echo "<option value=\"$v\">$v</option>";
                }
                ?>
            </select>
        </div>

        <div class="mb-3 col-md-6">
            <label for="subdomain" class="form-label">Sottodominio</label>
            <input type="text" name="subdomain" class="form-control" required>
        </div>
    </div>

    <button type="submit" class="btn btn-primary">Crea Server</button>
    <a href="dashboard.php" class="btn btn-secondary ms-2">Annulla</a>
</form>


<?php include("includes/footer.php"); ?>
