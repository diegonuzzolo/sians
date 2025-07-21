<?php
session_start();
require 'config/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];

// Conta i server totali disponibili
$totaleSlot = 10;

// Conta quelli già assegnati
$stmt = $pdo->query("SELECT COUNT(*) FROM servers WHERE user_id IS NOT NULL");
$assegnati = $stmt->fetchColumn();
$disponibili = max(0, $totaleSlot - $assegnati);

// Cerca il server dell'utente attuale
$stmt = $pdo->prepare("SELECT * FROM servers WHERE user_id = ?");
$stmt->execute([$userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Dashboard - Server Minecraft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background-color: #f7f9fc; }
    .server-box {
      background: white;
      border-radius: 12px;
      padding: 25px;
      box-shadow: 0 4px 10px rgba(0,0,0,0.1);
    }
  </style>
</head>
<body>
<nav class="navbar navbar-dark bg-dark">
  <div class="container">
    <a class="navbar-brand" href="index.php">Sians Hosting</a>
    <div class="d-flex">
      <a href="logout.php" class="btn btn-outline-light">Logout</a>
    </div>
  </div>
</nav>

<div class="container my-5">
  <h1 class="mb-4">La tua Dashboard</h1>

  <?php if ($server): ?>
    <div class="server-box">
      <h3>Il tuo server Minecraft</h3>
      <p><strong>Nome:</strong> <?= htmlspecialchars($server['name']) ?></p>
      <p><strong>IP:</strong> <?= htmlspecialchars($server['ip']) ?></p>
      <p><strong>Stato:</strong> 
        <span class="badge bg-<?= $server['status'] === 'online' ? 'success' : 'secondary' ?>">
          <?= htmlspecialchars($server['status']) ?>
        </span>
      </p>
    </div>
  <?php else: ?>
    <div class="alert alert-warning">
      Non hai ancora creato un server Minecraft.
    </div>

    <?php if ($disponibili > 0): ?>
      <a href="crea_server.php" class="btn btn-primary">Crea il tuo Server</a>
    <?php else: ?>
      <div class="alert alert-danger mt-3">
        Nessuno slot disponibile al momento. Riprova più tardi.
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

</body>
</html>
