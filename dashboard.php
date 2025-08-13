<?php
include("auth_check.php");
require 'config/config.php';

$userId = $_SESSION['user_id'] ?? 0;

// Recupera tutti i server dell’utente
$stmt = $pdo->prepare("SELECT s.id, s.name, s.status, c.ip, c.ram_gb
                       FROM servers s
                       JOIN containers c ON c.id = s.container_id
                       WHERE s.user_id = ?");
$stmt->execute([$userId]);
$servers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark text-white">
<?php include "includes/header.php"; ?>
<div class="container my-5">
    <h2 class="mb-4 text-center text-bedrock">🖥️ I tuoi server</h2>

    <?php if (empty($servers)): ?>
        <div class="alert alert-info">Non hai ancora server attivi.</div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($servers as $server): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card bg-secondary text-white shadow rounded-4 p-3">
                        <h5 class="card-title"><?= htmlspecialchars($server['name']) ?></h5>
                        <p class="mb-1">IP: <?= htmlspecialchars($server['private_ip']) ?></p>
                        <p class="mb-2">RAM: <?= htmlspecialchars($server['ram_mb']) ?> MB</p>
                        
                        <div class="progress mb-2" style="height: 20px;">
                            <div id="progress-<?= $server['id'] ?>" class="progress-bar bg-info" role="progressbar"
                                 style="width: 0%">In attesa...</div>
                        </div>

                        <p id="status-<?= $server['id'] ?>" class="mb-0">Stato: <?= htmlspecialchars($server['status']) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>




</script>

<style>
.bg-bedrock { color: #00c8ff; text-shadow: 1px 1px 3px rgba(0,0,0,0.8); }
</style>
<?php include "includes/footer.php"; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
