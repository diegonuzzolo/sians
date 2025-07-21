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

// Quanti slot ancora disponibili in totale
$stmt = $pdo->query("SELECT COUNT(*) FROM minecraft_vms WHERE assigned_user_id IS NULL");
$slotDisponibili = $stmt->fetchColumn();

// Quanti server ha l'utente
$stmt = $pdo->prepare("SELECT COUNT(*) FROM servers WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$mieiServer = $stmt->fetchColumn();

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
      <a href="add_server.php" class="btn btn-primary">Crea il tuo Server</a>
    <?php else: ?>
      <div class="alert alert-danger mt-3">
        Nessuno slot disponibile al momento. Riprova più tardi.
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="container my-5">
    <h2 class="mb-4">Benvenuto nella tua Dashboard</h2>

    <div class="row">
        <!-- Slot liberi -->
        <div class="col-md-4">
            <div class="card text-white bg-success mb-3">
                <div class="card-body">
                    <h5 class="card-title">Slot disponibili</h5>
                    <p class="card-text fs-3"><?= $slotDisponibili ?></p>
                </div>
            </div>
        </div>

        <!-- Server creati -->
        <div class="col-md-4">
            <div class="card text-white bg-primary mb-3">
                <div class="card-body">
                    <h5 class="card-title">I tuoi server</h5>
                    <p class="card-text fs-3"><?= $mieiServer ?></p>
                </div>
            </div>
        </div>

        <!-- Azione -->
        <div class="col-md-4">
            <div class="card bg-light mb-3">
                <div class="card-body text-center">
                    <h5 class="card-title">Nuovo Server</h5>
                    <?php if ($slotDisponibili > 0): ?>
                        <a href="add_server.php" class="btn btn-success">Crea Nuovo Server</a>
                    <?php else: ?>
                        <p class="text-danger mt-2">Nessuno slot disponibile</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>



</div>

</body>
</html>
