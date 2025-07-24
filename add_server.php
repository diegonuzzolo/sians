<?php
require 'config/config.php';
require 'includes/auth.php';

$serverName = $_POST['server_name'] ?? null;

if (!$serverName) {
    http_response_code(400);
    echo "Nome server mancante";
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
$stmt = $pdo->prepare("INSERT INTO servers (name, user_id, vm_id, proxmox_vmid) VALUES (?, ?, ?, ?)");
$success = $stmt->execute([$serverName, $userId, $vm['id'], $vm['proxmox_vmid']]);

if (!$success) {
    echo "Errore database: " . implode(", ", $stmt->errorInfo());
    exit;
}

$serverId = $pdo->lastInsertId();

// Aggiorna la VM per associarla all’utente e al server
$stmt = $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = ?, assigned_server_id = ? WHERE id = ?");
$stmt->execute([$userId, $serverId, $vm['id']]);

// Reindirizza alla creazione del tunnel e DNS
header("Location: create_tunnel_and_dns.php?server_id=$serverId");
exit;
?>


<?php include 'includes/header.php'; ?>

<div class="container mt-4">
    <h2>Crea un nuovo server Minecraft</h2>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php elseif ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="POST" action="add_server.php">
    <input type="text"  placeholder="Nome server" required>
    <button name="server_name" type="submit">Crea server</button>
</form>

</div>

<?php include 'includes/footer.php'; ?>
