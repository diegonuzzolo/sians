<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include("auth_check.php");
require 'config/config.php';

$userId = $_SESSION['user_id'] ?? 0;

// --- Recupera lo Stripe Customer ID dell'utente loggato ---
$stmt = $pdo->prepare("SELECT stripe_customer_id FROM users WHERE id = ?");
$stmt->execute([$userId]);
$stripeCustomerId = $stmt->fetchColumn();

// --- Recupera i server attivi ---
$stmt = $pdo->prepare("
    SELECT s.id, s.name, s.status, s.type,
           c.ip AS ip, c.ram_mb, c.proxmox_containerId, s.plan_id
    FROM servers s
    LEFT JOIN containers c ON c.id = s.container_id
    WHERE s.user_id=? AND s.status != 'deleted'
");
$stmt->execute([$userId]);
$servers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Recupera le subscription Stripe ---
$subscriptions = [];
if ($stripeCustomerId) {
    $stmt = $pdo->prepare("
        SELECT sub.*, p.code AS plan_code, p.name AS plan_name, p.ram_gb, p.cpu_cores
        FROM subscriptions sub
        JOIN plans p ON p.id = sub.stripe_price_id
        WHERE sub.stripe_customer_id = ? AND sub.status = 'active'
    ");
    $stmt->execute([$stripeCustomerId]);
    $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- Mappa piano -> subscription ---
$subsByPlan = [];
foreach ($subscriptions as $sub) {
    $subsByPlan[$sub['plan_code']] = $sub;
}

// --- DEBUG opzionale ---
// Decommenta per controllare se le subscription arrivano correttamente
// echo "<pre>"; var_dump($subscriptions); echo "</pre>";
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Dashboard</title>
    <style>
        body { font-family: Arial, sans-serif; }
        .server-box { border: 1px solid #ccc; padding: 10px; margin: 10px; border-radius: 8px; }
        .active { background: #e8ffe8; }
        .stopped { background: #ffe8e8; }
    </style>
</head>
<body>
    <h1>Benvenuto nella tua Dashboard</h1>

    <h2>I tuoi server</h2>
    <?php if (count($servers) > 0): ?>
        <?php foreach ($servers as $server): ?>
            <div class="server-box <?= $server['status'] ?>">
                <strong><?= htmlspecialchars($server['name']) ?></strong><br>
                Tipo: <?= htmlspecialchars($server['type']) ?><br>
                IP: <?= htmlspecialchars($server['ip'] ?? '-') ?><br>
                RAM: <?= intval($server['ram_mb']) ?> MB<br>
                Stato: <?= htmlspecialchars($server['status']) ?><br>
                ContainerID: <?= intval($server['proxmox_containerId']) ?><br>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <p>Nessun server attivo.</p>
    <?php endif; ?>

    <h2>I tuoi abbonamenti</h2>
    <?php if (count($subscriptions) > 0): ?>
        <ul>
            <?php foreach ($subscriptions as $sub): ?>
                <li>
                    <strong><?= htmlspecialchars($sub['plan_name']) ?></strong> (<?= htmlspecialchars($sub['plan_code']) ?>) –
                    RAM: <?= intval($sub['ram_gb']) ?> GB –
                    CPU: <?= intval($sub['cpu_cores']) ?> core –
                    Stato: <?= htmlspecialchars($sub['status']) ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p>Nessun abbonamento attivo.</p>
    <?php endif; ?>
</body>
</html>
