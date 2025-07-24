<?php
require 'config/config.php';
require 'includes/auth.php';

$error = '';
$success = '';

// Se il form è stato inviato
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serverName = $_POST['server_name'] ?? null;
    $subdomain = $_POST['subdomain'] ?? null;
    $userId = $_SESSION['user_id'] ?? null;

    if (!$serverName || !$subdomain || !$userId) {
        $error = "Tutti i campi sono obbligatori.";
    } else {
        try {
            // Trova una VM libera
            $stmt = $pdo->prepare("SELECT * FROM minecraft_vms WHERE assigned_user_id IS NULL AND assigned_server_id IS NULL LIMIT 1");
            $stmt->execute();
            $vm = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($vm) {
                $vmId = $vm['id'];
                $proxmoxVmid = $vm['proxmox_vmid'];

                // Inserisci il server
                $stmt = $pdo->prepare("INSERT INTO servers (name, subdomain, user_id, vm_id, proxmox_vmid) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$serverName, $subdomain, $userId, $vmId, $proxmoxVmid]);
                $serverId = $pdo->lastInsertId();

                // Segna la VM come assegnata
                $stmt = $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = ?, assigned_server_id = ? WHERE id = ?");
                $stmt->execute([$userId, $serverId, $vmId]);

                // Reindirizza alla creazione tunnel/DNS
                header("Location: create_tunnel_and_dns.php?server_id=" . $serverId);
                exit;
            } else {
                $error = "Nessuna VM disponibile al momento.";
            }
        } catch (PDOException $e) {
            $error = "Errore database: " . $e->getMessage();
        }
    }
}
?>

<?php include 'includes/header.php'; ?>

<div class="container mt-4">
    <h2>Crea un nuovo server Minecraft</h2>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php elseif ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label for="server_name">Nome Server</label>
            <input type="text" class="form-control" id="server_name" name="server_name"
                   value="<?= htmlspecialchars($_POST['server_name'] ?? '') ?>" required>
        </div>

        <div class="form-group">
            <label for="subdomain">Sottodominio (es. mc1)</label>
            <input type="text" class="form-control" id="subdomain" name="subdomain"
                   value="<?= htmlspecialchars($_POST['subdomain'] ?? '') ?>" required>
            <small class="form-text text-muted">Il dominio sarà <strong>mcX.sians.it</strong></small>
        </div>

        <button type="submit" class="btn btn-primary">Crea server</button>
    </form>
</div>

<?php include 'includes/footer.php'; ?>
