<?php
require 'config/config.php';
require 'includes/auth.php';

$serverName = $_POST['server_name'] ?? null;
$subdomain = $_POST['subdomain'] ?? null;

if (!$serverName || !$subdomain) {
    http_response_code(400);
    echo "Nome server o sottodominio mancante";
    exit;
}

// Verifica che il sottodominio non sia già usato
$stmt = $pdo->prepare("SELECT COUNT(*) FROM servers WHERE subdomain = ?");
$stmt->execute([$subdomain]);
if ($stmt->fetchColumn() > 0) {
    http_response_code(409); // Conflict
    echo "Il sottodominio è già in uso";
    exit;
}

// Trova una VM libera
$stmt = $pdo->prepare("SELECT * FROM minecraft_vms WHERE assigned_user_id IS NULL AND assigned_server_id IS NULL LIMIT 1");
$stmt->execute();
$vm = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$vm) {
    http_response_code(500);
    echo "Nessuna VM disponibile";
    exit;
}

// Inserisci il nuovo server nella tabella `servers`
$stmt = $pdo->prepare("INSERT INTO servers (name, subdomain, user_id, vm_id, proxmox_vmid) VALUES (?, ?, ?, ?, ?)");
$success = $stmt->execute([$serverName, $subdomain, $userId, $vm['id'], $vm['proxmox_vmid']]);

if (!$success) {
    echo "Errore database: " . implode(", ", $stmt->errorInfo());
    exit;
}

$serverId = $pdo->lastInsertId();

// Aggiorna la VM per associarla all’utente e al server
$stmt = $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = ?, assigned_server_id = ? WHERE id = ?");
$stmt->execute([$userId, $serverId, $vm['id']]);

// Reindirizza alla creazione del tunnel e DNS
header("Location: create_tunnel_and_dns.php?server_id=$serverId&subdomain=$subdomain");
exit;
?>

<?php include 'includes/header.php'; ?>

<div class="container mt-4">
    <h2>Crea un nuovo server Minecraft</h2>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php elseif (!empty($success)): ?>
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
            <small class="form-text text-muted">Il dominio sarà <strong><span id="subdomain-preview"><?= htmlspecialchars($_POST['subdomain'] ?? 'mcX') ?></span>.sians.it</strong></small>
        </div>

        <button type="submit" class="btn btn-primary">Crea server</button>
    </form>
</div>

<?php include 'includes/footer.php'; ?>

