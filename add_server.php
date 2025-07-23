<?php
require 'config/config.php';
require 'includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $subdomain = strtolower(trim($_POST['subdomain'] ?? ''));

    if (!$name || !$subdomain) {
        $error = "Inserisci sia il nome del server che il sottodominio.";
    } elseif (!preg_match('/^[a-z0-9\-]+$/', $subdomain)) {
        $error = "Il sottodominio può contenere solo lettere, numeri e trattini.";
    } else {
        // Verifica se il sottodominio è già usato
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM servers WHERE subdomain = ?");
        $stmt->execute([$subdomain]);
        if ($stmt->fetchColumn() > 0) {
            $error = "Questo sottodominio è già in uso. Scegline un altro.";
        } else {
            // Cerca una VM libera
            $stmt = $pdo->query("SELECT * FROM minecraft_vms WHERE assigned_user_id IS NULL LIMIT 1");
            $vm = $stmt->fetch();

            if (!$vm) {
                $error = "Nessun server disponibile al momento. Riprova più tardi.";
            } else {
                // Crea il server e associa la VM
                $stmt = $pdo->prepare("INSERT INTO servers (user_id, name, subdomain, proxmox_vmid) VALUES (?, ?, ?, ?)");
                $stmt->execute([$_SESSION['user_id'], $name, $subdomain, $vm['proxmox_vmid']]);
                $server_id = $pdo->lastInsertId();

                // Aggiorna la VM come assegnata
                $stmt = $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = ?, assigned_server_id = ? WHERE id = ?");
                $stmt->execute([$_SESSION['user_id'], $server_id, $vm['id']]);
                // Reindirizza allo script che crea tunnel e DNS
                header("Location: configure_tunnel_dns.php?server_id=$server_id");
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
            <input type="text" name="name" id="name" class="form-control" required style="max-width: 300px;">
        </div>

        <div class="mb-3">
            <label for="subdomain" class="form-label">Hostname (es mc..)</label>
            <div class="input-group" style="max-width: 300px;">
                <input type="text" name="subdomain" id="subdomain" class="form-control" required>
                <span class="input-group-text">.sians.it</span>
            </div>
            <div class="form-text">Questo sarà l'indirizzo che userai per collegarti al server Minecraft.</div>
        </div>

        <button type="submit" class="btn btn-primary">Crea Server</button>
        <a href="dashboard.php" class="btn btn-secondary">Annulla</a>
    </form>
</div>


<?php include 'includes/footer.php'; ?>
