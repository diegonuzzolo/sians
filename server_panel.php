<?php
session_start();
require 'config/config.php';
require 'includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (!isset($_GET['id'])) {
    die("ID server mancante.");
}

$serverId = intval($_GET['id']);
$userId = $_SESSION['user_id'];

// Controllo proprietà server
$stmt = $pdo->prepare("SELECT s.*, vm.ip AS ip_address, vm.hostname 
                       FROM servers s
                       JOIN minecraft_vms vm ON s.vm_id = vm.id
                       WHERE s.id = ? AND s.user_id = ?");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch();

if (!$server) {
    die("Server non trovato o non autorizzato.");
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Gestione Server - <?= htmlspecialchars($server['name']) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<?php include 'includes/header.php'; ?>

<div class="container my-4">
  <h2>🎮 Pannello di gestione - <?= htmlspecialchars($server['name']) ?></h2>
  
  <div class="card p-3 mt-3">
    <p><strong>IP:</strong> <?= htmlspecialchars($server['ip_address']) ?></p>
    <p><strong>Stato:</strong> <?= htmlspecialchars($server['status']) ?></p>
  </div>

  <hr>

  <!-- Sezione Modpack -->
  <h4>📦 Gestione Modpack</h4>
  <form action="install_modpack.php" method="POST" enctype="multipart/form-data">
    <input type="hidden" name="server_id" value="<?= $serverId ?>">
    <div class="mb-3">
      <label for="modpack" class="form-label">Carica Modpack (ZIP)</label>
      <input type="file" class="form-control" name="modpack" id="modpack" required>
    </div>
    <button type="submit" class="btn btn-primary">Installa Modpack</button>
  </form>

  <div class="mt-4">
    <a href="dashboard.php" class="btn btn-secondary">⬅ Torna alla Dashboard</a>
  </div>
</div>

</body>
</html>
