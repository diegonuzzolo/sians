<?php
require 'config/config.php';
require 'includes/auth.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serverName = trim($_POST['server_name'] ?? '');
    $subdomain = trim($_POST['subdomain'] ?? '');
    $userId = $_SESSION['user_id'] ?? null;

    if (!$serverName || !$subdomain || !$userId) {
        $error = "Nome server, sottodominio e login sono obbligatori.";
    } else {
        // Cerca VM libera
        $stmt = $pdo->prepare("SELECT * FROM minecraft_vms WHERE assigned_user_id IS NULL AND assigned_server_id IS NULL LIMIT 1");
        $stmt->execute();
        $vm = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$vm) {
            $error = "Nessuna VM libera disponibile.";
        } else {
            // Inserisci server nel DB
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

                // Aggiorna VM assegnata
                $stmt = $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = ?, assigned_server_id = ? WHERE id = ?");
                $stmt->execute([$userId, $serverId, $vm['id']]);

                // Redirect alla pagina di gestione tunnel/DNS (opzionale)
                header("Location: create_tunnel_and_dns.php?server_id=$serverId");
                exit;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8" />
    <title>Aggiungi Server Minecraft</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
</head>
<body>
<div class="container mt-5">
    <h1>Aggiungi un nuovo Server Minecraft</h1>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="mb-3">
            <label for="server_name" class="form-label">Nome del Server</label>
            <input type="text" name="server_name" id="server_name" class="form-control" required value="<?= htmlspecialchars($_POST['server_name'] ?? '') ?>" />
        </div>

        <div class="mb-3">
            <label for="subdomain" class="form-label">Sottodominio (es. mc123)</label>
            <div class="input-group">
                <input type="text" name="subdomain" id="subdomain" class="form-control" required value="<?= htmlspecialchars($_POST['subdomain'] ?? '') ?>" />
                <span class="input-group-text"><?= DOMAIN ?></span>
            </div>
            <div class="form-text">Questo sarà l'indirizzo per collegarti al server Minecraft.</div>
        </div>

        <button type="submit" class="btn btn-primary">Crea Server</button>
        <a href="dashboard.php" class="btn btn-secondary ms-2">Annulla</a>
    </form>
</div>
</body>
</html>
