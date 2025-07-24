<?php
require 'config/config.php';
require 'includes/auth.php';
require 'includes/functions.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serverName = $_POST['name'] ?? null;
    $subdomainInput = $_POST['subdomain'] ?? null;

    if (!$serverName || !$subdomainInput) {
        $error = "Nome server o sottodominio mancante.";
    } else {
        // Cerca una VM disponibile
        $stmt = $pdo->prepare("SELECT * FROM minecraft_vms WHERE assigned = 0 LIMIT 1");
        $stmt->execute();
        $vm = $stmt->fetch();

        if (!$vm) {
            $error = "Nessuna VM disponibile.";
        } else {
            // Ottieni tunnel ngrok
            $tunnel = getNgrokTunnel();
            if (!$tunnel) {
                $error = "Nessun tunnel ngrok TCP attivo trovato.";
            } else {
                $subdomain = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $subdomainInput));
                $domain = $subdomain . '.' . DOMAIN;

                // Inserisci il nuovo server
                $stmt = $pdo->prepare("INSERT INTO servers (user_id, name, subdomain, status, ip_address, proxmox_vmid, ngrok_tcp_host, ngrok_tcp_port) VALUES (?, ?, ?, 'spento', NULL, ?, ?, ?)");
                $stmt->execute([
                    $_SESSION['user_id'],
                    $serverName,
                    $domain,
                    $vm['proxmox_vmid'],
                    $tunnel['host'],
                    $tunnel['port']
                ]);

                // Segna la VM come assegnata
                $stmt = $pdo->prepare("UPDATE minecraft_vms SET assigned = 1 WHERE id = ?");
                $stmt->execute([$vm['id']]);

                header("Location: dashboard.php");
                exit;
            }
        }
    }
}
?>

<?php include 'includes/header.php'; ?>
<div class="container mt-5">
    <h2>Aggiungi un nuovo Server</h2>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" class="mt-4" style="max-width: 350px;">
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
</div>
<?php include 'includes/footer.php'; ?>
