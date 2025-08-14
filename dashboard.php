<?php
include("auth_check.php");
require 'config/config.php';

$userId = $_SESSION['user_id'] ?? 0;

// Recupera tutti i server dell’utente
$stmt = $pdo->prepare("
    SELECT s.id, s.name, s.status, s.type, s.version, s.progress,
           c.ip AS private_ip, c.ram_gb
    FROM servers s
    LEFT JOIN containers c ON c.id = s.container_id
    WHERE s.user_id = ?
");
$stmt->execute([$userId]);
$servers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
.bg-bedrock { color: #00c8ff; text-shadow: 1px 1px 3px rgba(0,0,0,0.8); }
.card-hover:hover { transform: scale(1.03); transition: 0.2s; cursor: pointer; }
.progress-bar { transition: width 0.5s; }
</style>
</head>
<body class="bg-dark text-white">
<?php include "includes/header.php"; ?>

<div class="container my-5">
    <h2 class="mb-4 text-center bg-bedrock">🖥️ I tuoi server</h2>

    <?php if (empty($servers)): ?>
        <div class="alert alert-info">Non hai ancora server attivi.</div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($servers as $server): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card bg-secondary text-white shadow rounded-4 p-3 card-hover">
                        <h5 class="card-title"><?= htmlspecialchars($server['name']) ?></h5>
                        <p class="mb-1">IP: <?= htmlspecialchars($server['private_ip'] ?? 'N/A') ?></p>
                        <p class="mb-2">RAM: <?= htmlspecialchars($server['ram_gb'] ?? 0) ?> MB</p>
                        <p class="mb-2">- <?= htmlspecialchars($server['type']) ?> - Versione: <?= htmlspecialchars($server['version'] ?? '-') ?></p>

                        <div class="progress mb-2" style="height: 20px;">
                            <div id="progress-<?= $server['id'] ?>" class="progress-bar bg-info" role="progressbar"
                                 style="width: <?= $server['progress'] ?? 0 ?>%">
                                 <?= $server['progress'] ?? 0 ?>%
                            </div>
                        </div>

                        <p id="status-<?= $server['id'] ?>" class="mb-2">Stato: <?= htmlspecialchars($server['status']) ?></p>

                        <a href="server_panel.php?id=<?= $server['id'] ?>" class="btn btn-primary w-100">Gestisci</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Aggiornamento dinamico dello stato e progress bar ogni 5 secondi
setInterval(() => {
    <?php foreach ($servers as $server): ?>
        fetch('check_status.php?id=<?= $server['id'] ?>')
            .then(res => res.json())
            .then(data => {
                const statusEl = document.getElementById('status-<?= $server['id'] ?>');
                const progressEl = document.getElementById('progress-<?= $server['id'] ?>');
                if(statusEl) statusEl.textContent = "Stato: " + data.status;
                if(progressEl) {
                    progressEl.style.width = data.progress + "%";
                    progressEl.textContent = data.progress + "%";
                }
            });
    <?php endforeach; ?>
}, 5000);
</script>

<?php include "includes/footer.php"; ?>
</body>
</html>
