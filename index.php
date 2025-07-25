<?php
session_start();
require 'config/config.php';

// Conta gli slot liberi
$stmt = $pdo->query("SELECT COUNT(*) FROM minecraft_vms WHERE assigned_user_id IS NULL");
$slotDisponibili = $stmt->fetchColumn();

// Caratteristiche risorse per VM
$vmCores = 5;
$vmRam = 10; // in GB
$vmStorageSpeed = 7500; // MB/s
?>

<?php include 'includes/header.php'; ?>

<!-- Style aggiuntivo -->
<style>
  body {
    background: linear-gradient(to right, #0f172a, #1e293b);
    color: #f1f5f9;
    font-family: 'Segoe UI', sans-serif;
  }

  .hero-banner {
    height: 100vh;
    background: url('assets/banner.png') center center/cover no-repeat;
    border-radius: 15px;
    position: relative;
    text-align: center;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .hero-banner h1 {
    font-size: 3rem;
    color: #facc15;
    text-shadow: 2px 2px 6px #000;
  }

  .hero-buttons .btn {
    margin: 10px;
    padding: 12px 24px;
    font-weight: 600;
    font-size: 1.2rem;
    border-radius: 10px;
    transition: all 0.2s ease-in-out;
  }

  .hero-buttons .btn:hover {
    transform: scale(1.05);
  }

  .info-box {
    background-color: #1e293b;
    border-radius: 12px;
    padding: 25px;
    text-align: center;
    color: #f8fafc;
    box-shadow: 0 0 10px rgba(0,0,0,0.3);
    margin-bottom: 30px;
    transition: 0.2s;
  }

  .info-box:hover {
    transform: scale(1.03);
    box-shadow: 0 0 20px rgba(250, 204, 21, 0.3);
  }

  .info-box i {
    font-size: 3rem;
    margin-bottom: 15px;
  }

  .text-gold { color: #facc15; }
</style>

<!-- Hero Section -->
<div class="container-fluid hero-banner">
  <div class="text-center">
      <div class="hero-buttons">
          </div>
        </div>
    </div>
    <?php if (!isset($_SESSION['user_id'])): ?>
      <a href="register.php" class="btn btn-success">Registrati</a>
      <a href="login.php" class="btn btn-outline-light">Accedi</a>
      <?php else: ?>
          <a href="dashboard.php" class="btn btn-primary">Vai alla Dashboard</a>
          <?php endif; ?>
    
<!-- Slot disponibili -->
<div class="container my-5 text-center">
  <h2 class="text-gold">Slot Disponibili</h2>
  <div class="display-5 text-success fw-bold"><?= htmlspecialchars($slotDisponibili) ?></div>
  <p class="text-muted">Slot ancora liberi per creare nuovi server personalizzati.</p>
</div>

<!-- Caratteristiche server -->
<div class="container my-5">
  <h2 class="text-center mb-5 text-gold">Caratteristiche del tuo server</h2>
  <div class="row justify-content-center">
    <div class="col-md-4">
      <div class="info-box">
        <i class="bi bi-cpu-fill text-danger"></i>
        <h5>CPU</h5>
        <p><?= $vmCores ?> Core virtuali dedicati</p>
      </div>
    </div>
    <div class="col-md-4">
      <div class="info-box">
        <i class="bi bi-memory text-primary"></i>
        <h5>RAM</h5>
        <p><?= $vmRam ?> GB DDR5 dedicati</p>
      </div>
    </div>
    <div class="col-md-4">
      <div class="info-box">
        <i class="bi bi-hdd-fill text-success"></i>
        <h5>Storage</h5>
        <p>NVMe <?= number_format($vmStorageSpeed, 0, ',', '.') ?> MB/s</p>
      </div>
    </div>
  </div>
  <p class="text-center text-muted mt-3 fst-italic">Massime prestazioni per ogni partita.</p>
</div>

<!-- Vantaggi -->
<div class="container my-5">
  <h2 class="text-center text-gold mb-5">Perché scegliere noi?</h2>
  <div class="row text-center">
    <div class="col-md-4">
      <div class="info-box">
        <i class="bi bi-lightning-charge-fill text-warning"></i>
        <h5>Velocità</h5>
        <p>Server sempre reattivi grazie alle VM isolate e ottimizzate.</p>
      </div>
    </div>
    <div class="col-md-4">
      <div class="info-box">
        <i class="bi bi-gear-fill text-info"></i>
        <h5>Controllo Totale</h5>
        <p>Gestisci i tuoi server da una dashboard intuitiva e completa.</p>
      </div>
    </div>
    <div class="col-md-4">
      <div class="info-box">
        <i class="bi bi-check-circle-fill text-success"></i>
        <h5>Semplicità</h5>
        <p>Basta pochi clic per creare, avviare e personalizzare il tuo mondo.</p>
      </div>
    </div>
  </div>
</div>

<!-- Script banner mobile -->
<script>
  window.addEventListener("load", function () {
    const isMobile = window.innerWidth < 768;
    const banner = document.querySelector(".hero-banner");
    if (isMobile) {
      banner.style.backgroundImage = "url('assets/banner-mobile.png')";
    }
  });
</script>

<?php include 'includes/footer.php'; ?>
