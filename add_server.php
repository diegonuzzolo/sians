<?php
require 'config/config.php';
require 'includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');


    if (!$name) {
        $error = "Inserisci il nome del server";
    } else {
        // Cerca una VM libera
        $stmt = $pdo->query("SELECT * FROM minecraft_vms WHERE assigned_user_id IS NULL LIMIT 1");
        $vm = $stmt->fetch();

        if (!$vm) {
            $error = "Nessun server disponibile al momento. Riprova più tardi.";
        } else {
            // Crea il server e associa la VM
            $stmt = $pdo->prepare("INSERT INTO servers (user_id, name, proxmox_vmid) VALUES (?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $name, $vm['proxmox_vmid']]);
            $server_id = $pdo->lastInsertId();

            // Aggiorna la VM come assegnata
            $stmt = $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = ?, assigned_server_id = ? WHERE id = ?");
            $stmt->execute([$_SESSION['user_id'], $server_id, $vm['id']]);

            header("Location: dashboard.php");
            exit;
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

    <form method="post" class="mt-4">
        <div class="mb-3">
            <label for="name" class="form-label">Nome del Server</label>
            <input type="text" name="name" id="name" class="form-control" required>
        </div>

        <button type="submit" class="btn btn-primary">Crea Server</button>
        <a href="dashboard.php" class="btn btn-secondary">Annulla</a>
    </form>
</div>

<?php include 'includes/footer.php'; ?>
