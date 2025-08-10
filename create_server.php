<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include(__DIR__ . "/auth_check.php"); // 🔒 Protezione login

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $planId = intval($_POST['plan_id']);
    $serverName = trim($_POST['server_name'] ?? '');
    $userId = $_SESSION['user_id'];

    if ($serverName === '' || strlen($serverName) < 3) {
        die("Nome server non valido, minimo 3 caratteri.");
    }

    // Verifica piano
    $stmt = $pdo->prepare("SELECT * FROM plans WHERE id = ?");
    $stmt->execute([$planId]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$plan) {
        http_response_code(400);
        die("Piano selezionato non valido.");
    }

    // Trova VM libera
    $stmt = $pdo->query("SELECT id FROM minecraft_vms WHERE assigned_user_id IS NULL LIMIT 1");
    $freeVm = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$freeVm) {
        die("Nessuna VM libera disponibile. Riprova più tardi.");
    }

    $vmId = $freeVm['id'];

    // Inserisci server con vm_id e nome
    $stmt = $pdo->prepare("
        INSERT INTO servers (user_id, plan_id, vm_id, name, created_at, status)
        VALUES (?, ?, ?, ?, NOW(), 'attivo')
    ");
    $stmt->execute([$userId, $planId, $vmId, $serverName]);

    // Assegna VM all'utente
    $update = $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = ? WHERE id = ?");
    $update->execute([$userId, $vmId]);

    $serverId = $pdo->lastInsertId();
}
 else {
    header("Location: select_plan.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Server Creato</title>
<style>
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: linear-gradient(135deg, #0d0d0d, #1a1a1a);
        color: #f2f2f2;
        margin: 0;
        padding: 0;
    }
    .container {
        max-width: 700px;
        margin: 60px auto;
        background: rgba(20, 20, 20, 0.95);
        padding: 30px;
        border-radius: 15px;
        box-shadow: 0 0 20px rgba(0,0,0,0.6);
        text-align: center;
    }
    h2 {
        color: #00ff88;
        font-size: 1.8em;
        margin-bottom: 20px;
    }
    p {
        font-size: 1.2em;
        margin-bottom: 15px;
    }
    a.button {
        display: inline-block;
        padding: 12px 25px;
        border-radius: 8px;
        background: linear-gradient(90deg, #00ff88, #00ccff);
        color: #000;
        font-weight: bold;
        text-decoration: none;
        font-size: 1.1em;
        transition: 0.3s ease;
    }
    a.button:hover {
        background: linear-gradient(90deg, #00ccff, #00ff88);
        transform: scale(1.05);
    }
     footer {
        text-align: center;
        padding: 15px;
        font-size: 0.9em;
        background: rgba(0,0,0,0.8);
        color: #aaa;
        border-top: 1px solid #222;
    }
    footer a {
        color: #00ff88;
        text-decoration: none;
    }
    footer a:hover {
        text-decoration: underline;
    }
</style>
</head>
<body>
<div class="container">
    <h2>🎉 Server Creato con Successo!</h2>
    <p>Il tuo server <strong>#<?= htmlspecialchars($serverId) ?></strong> è stato creato con il piano:</p>
    <p><strong><?= htmlspecialchars($plan['name']) ?></strong> — <?= htmlspecialchars($plan['cpu_cores']) ?> Core / <?= htmlspecialchars($plan['ram_gb']) ?> GB RAM / <?= htmlspecialchars($plan['bandwidth_mbps']) ?> Mbps</p>
    <p>Prezzo: <strong>€<?= htmlspecialchars($plan['price_per_month']) ?>/mese</strong></p>
    <a class="button" href="dashboard.php">Torna alla Dashboard</a>
</div>

<?php include("includes/footer.php"); ?>

</body>
</html>
