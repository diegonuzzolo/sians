<?php
session_start();
require 'config/config.php';

$stmt = $pdo->query("SELECT COUNT(*) FROM minecraft_vms WHERE assigned_user_id IS NULL");
$slotDisponibili = $stmt->fetchColumn();

$vmCores = 5;
$vmRam = 10;
$vmStorageSpeed = 7500;
?>

<?php include 'includes/header.php'; ?>

<style>
  body {
    background: linear-gradient(to right, #0f172a, #1e293b);
    color: #f1f5f9;
    font-family: 'Segoe UI', sans-serif;
  }

@media (max-width: 767px) {
  .hero-banner {
    aspect-ratio: 16 / 9;
    background-size: contain;
    background-position: center top;
    padding: 2rem 1rem;
  }

  .hero-banner h1 {
    font-size: 2.2rem;
  }

  .hero-buttons .btn {
    font-size: 1rem;
    padding: 12px;
    width: 90%;
    margin: 10px auto;
    display: block;
  }

  .content-wrapper {
    margin-top: 4rem;
  }

  #freccetta {
    display: none;
  }
}


</style>

<div class="container-fluid hero-banner">
  <h1>Benvenuto su Sians</h1>

  <div class="hero-buttons text-center">
    <?php if (!isset($_SESSION['user_id'])): ?>
      <a href="register.php" class="btn btn-warning">Registrati</a>
      <a href="login.php" class="btn btn-light">Accedi</a>
    <?php else: ?>
      <a href="dashboard.php" class="btn btn-success">Vai alla Dashboard</a>
    <?php endif; ?>
  </div>

  <div id="freccetta" class="scroll-indicator" onclick="scrollToContent()">
    <i class="bi bi-arrow-down"></i>
  </div>
</div>

<!-- Wrapper per i contenuti che vanno spinti sotto il banner -->
<div class="content-wrapper">
  <div class="container my-5 text-center" id="scroll-target">
    <h2 class="text-gold">Slot Disponibili</h2>
    <div class="display-5 text-success fw-bold"><?= htmlspecialchars($slotDisponibili) ?></div>
  </div>

  <div class="container my-5">
    <h2 class="text-center mb-5 text-gold">Caratteristiche del tuo server</h2>
    <div class="row justify-content-center">
      <div class="col-12 col-md-4">
        <div class="info-box">
          <i class="bi bi-cpu-fill text-danger"></i>
          <h5>CPU</h5>
          <p><?= $vmCores ?> Core virtuali dedicati</p>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <div class="info-box">
          <i class="bi bi-memory text-primary"></i>
          <h5>RAM</h5>
          <p><?= $vmRam ?> GB DDR5 dedicati</p>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <div class="info-box">
          <i class="bi bi-hdd-fill text-success"></i>
          <h5>Storage</h5>
          <p>NVMe <?= number_format($vmStorageSpeed, 0, ',', '.') ?> MB/s</p>
        </div>
      </div>
    </div>
    <p class="text-center text-muted mt-3 fst-italic">Massime prestazioni per ogni partita.</p>
  </div>

  <div class="container my-5">
    <h2 class="text-center text-gold mb-5">Perché scegliere noi?</h2>
    <div class="row text-center">
      <div class="col-12 col-md-4">
        <div class="info-box">
          <i class="bi bi-lightning-charge-fill text-warning"></i>
          <h5>Velocità</h5>
          <p>Server sempre reattivi grazie alle VM isolate e ottimizzate.</p>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <div class="info-box">
          <i class="bi bi-gear-fill text-info"></i>
          <h5>Controllo Totale</h5>
          <p>Gestisci i tuoi server da una dashboard intuitiva e completa.</p>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <div class="info-box">
          <i class="bi bi-check-circle-fill text-success"></i>
          <h5>Semplicità</h5>
          <p>Basta pochi clic per creare, avviare e personalizzare il tuo mondo.</p>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  function scrollToContent() {
    document.getElementById('scroll-target').scrollIntoView({ behavior: 'smooth' });
  }
</script>

<?php include 'includes/footer.php'; ?>
