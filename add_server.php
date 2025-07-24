<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'config/config.php';
require 'includes/auth.php';
require 'includes/header.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serverName = trim($_POST['name'] ?? '');
    $subdomain = trim($_POST['subdomain'] ?? '');
    $userId = $_SESSION['user_id'] ?? null;

    if (!$serverName || !$subdomain || !$userId) {
        $error = "Tutti i campi sono obbligatori.";
    } else {
        try {
            // Trova una VM libera
            $stmt = $pdo->prepare("SELECT * FROM minecraft_vms WHERE assigned_user_id IS NULL AND assigned_server_id IS NULL LIMIT 1");
            $stmt->execute();
            $vm = $stmt->fetch();

            if (!$vm) {
                $error = "Nessuna VM disponibile";
            } else {
                $vmId = $vm['id'];
                $proxmoxVmid = $vm['proxmox_vmid'];

                // Inserisci nuovo server
                $stmt = $pdo->prepare("INSERT INTO servers (name, user_id, vm_id, proxmox_vmid, subdomain) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$serverName, $userId, $vmId, $proxmoxVmid, $subdomain]);
                $serverId = $pdo->lastInsertId();

                // Assegna la VM
                $stmt = $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = ?, assigned_server_id = ? WHERE id = ?");
                $stmt->execute([$userId, $serverId, $vmId]);

                // Reindirizza a tunnel/DNS
                header("Location: create_tunnel_and_dns.php?server_id=$serverId");
                exit;
            }
        } catch (PDOException $e) {
            $error = "Errore database: " . $e->getMessage();
        }
    }
}
?>
<?php include 'includes/header.php';?>
<div class="container mt-5">
    <h2>Aggiungi un nuovo Server Minecraft</h2>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php elseif ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <a href="dashboard.php" class="btn btn-primary">Torna alla Dashboard</a>
    <?php endif; ?>

    <?php if (!$success): ?>
    <form method="post" class="mt-4" style="max-width: 400px;">
        <div class="mb-3">
            <label for="name" class="form-label">Nome del Server</label>
            <input type="text" name="name" id="name" class="form-control" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
        </div>

        <div class="mb-3">
            <label for="subdomain" class="form-label">Hostname (es. mc123)</label>
            <div class="input-group">
                <input type="text" name="subdomain" id="subdomain" class="form-control" required value="<?= htmlspecialchars($_POST['subdomain'] ?? '') ?>">
                <span class="input-group-text">.<?= DOMAIN ?></span>
            </div>
            <div class="form-text">Questo sarà l'indirizzo che userai per collegarti al server Minecraft.</div>
        </div>

        <button type="submit" class="btn btn-primary">Crea Server</button>
        <a href="dashboard.php" class="btn btn-secondary">Annulla</a>
    </form>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
