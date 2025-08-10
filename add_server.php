<?php
include(__DIR__ . "/auth_check.php");
require 'config/config.php';

$userId = $_SESSION['user_id'] ?? 0;

function findAvailableContainer(PDO $pdo) {
    $sql = "SELECT * FROM containers WHERE assigned_user_id IS NULL LIMIT 1";
    $stmt = $pdo->query($sql);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serverName = trim($_POST['server_name'] ?? '');
    $planId = intval($_POST['plan_id'] ?? 0);

    if (empty($serverName)) {
        $error = "Inserisci un nome valido per il server.";
    } else {
        // Verifica piano
        $stmt = $pdo->prepare("SELECT * FROM plans WHERE id = ?");
        $stmt->execute([$planId]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$plan) {
            $error = "Piano selezionato non valido.";
        }
    }

    if (!isset($error)) {
        $container = findAvailableContainer($pdo);
        if (!$container) {
            $error = "Nessun container disponibile. Contatta l'amministratore.";
        }
    }

    if (!isset($error)) {
        // Inserisci server
        $stmt = $pdo->prepare("INSERT INTO servers 
            (name, user_id, container_id, proxmox_containerId, status, type, version, progress, plan_id, created_at, updated_at) 
            VALUES (?, ?, ?, ?, 'stopped', 'bedrock', NULL, 0, ?, NOW(), NOW())");
        $stmt->execute([
            $serverName,
            $userId,
            $container['id'],
            $container['proxmox_containerId'],
            $planId
        ]);
        $serverId = $pdo->lastInsertId();

        // Aggiorna container assegnato
        $stmt = $pdo->prepare("UPDATE containers SET assigned_user_id = ?, assigned_server_id = ? WHERE id = ?");
        $stmt->execute([$userId, $serverId, $container['id']]);

        header("Location: dashboard.php?created=1");
        exit;
    }
}

// Recupera piani
$stmt = $pdo->query("SELECT * FROM plans ORDER BY price_per_month DESC");
$plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<link rel="stylesheet" href="assets/css/style.css">
<?php include("includes/header.php"); ?>

<div class="container my-5" style="max-width:600px;">
    <h2>Crea un nuovo server Minecraft Bedrock</h2>
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="POST" action="">
        <div class="mb-3">
            <label for="server_name" class="form-label">Nome Server</label>
            <input type="text" name="server_name" id="server_name" class="form-control" required value="<?= htmlspecialchars($_POST['server_name'] ?? '') ?>">
        </div>
        <div class="mb-3">
            <label for="plan_id" class="form-label">Seleziona Piano</label>
            <select name="plan_id" id="plan_id" class="form-select" required>
                <option value="" disabled selected>Seleziona un piano...</option>
                <?php foreach ($plans as $plan): ?>
                    <option value="<?= htmlspecialchars($plan['id']) ?>" <?= (($_POST['plan_id'] ?? '') == $plan['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($plan['name']) ?> — <?= htmlspecialchars($plan['cpu_cores']) ?> Core / <?= htmlspecialchars($plan['ram_gb']) ?> GB RAM / €<?= htmlspecialchars($plan['price_per_month']) ?>/mese
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Crea Server</button>
    </form>
</div>

<?php include("includes/footer.php"); ?>
