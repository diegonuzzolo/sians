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

  /* Banner */
.hero-banner {
  background-image: linear-gradient(to bottom, rgba(15,23,42,0.9), rgba(30,41,59,0.95)),
                    url('assets/banner.png');
  background-repeat: no-repeat;
  background-position: center top;
  background-size: contain;
  width: 100%;
  aspect-ratio: 16 / 9; /* o proporzioni reali dell’immagine */
  border-radius: 20px;
  padding: 3rem 1rem;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  box-shadow: 0 0 20px rgba(0,0,0,0.6);
  position: relative;
  overflow: hidden;
  max-width: 1800px;
  margin: 0 auto;

}

  .hero-banner h1 {
    font-size: 3.5rem;
    font-weight: 800;
    color: #facc15;
    text-shadow: 2px 2px 12px rgba(0,0,0,0.6);
    margin-bottom: 1rem;
    line-height: 1.2;
  }

  .hero-buttons .btn {
    margin: 10px;
    padding: 14px 28px;
    font-weight: 600;
    font-size: 1.2rem;
    border-radius: 12px;
    transition: all 0.25s ease-in-out;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
  }

  .hero-buttons .btn:hover {
    transform: scale(1.07);
    box-shadow: 0 6px 18px rgba(250, 204, 21, 0.3);
  }


  /* Scroll indicator */
  .scroll-indicator i {
    font-size: 1.2rem;
    color: #facc15;
  }

  /* Info boxes */
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

  .text-gold {
    color: #facc15;
  }

  /* Animation pulse */
  @keyframes pulse {
    0% { transform: scale(1); opacity: 0.7; }
    50% { transform: scale(1.15); opacity: 1; }
    100% { transform: scale(1); opacity: 0.7; }
  }

  /* Wrapper contenuti sotto il banner */
  .content-wrapper {
    margin-top: 6rem; /* spazio per staccare dal banner */
  }

  /* Responsive */
@media (max-width: 767px) {
  .hero-banner {
    aspect-ratio: 16 / 9;
    background-size: contain;
    background-position: left top;
    padding: 2rem 1rem;
  }
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

    /* Riduci margine per mobile */
    .content-wrapper {
      margin-top: 4rem;
    }


  @media (max-width: 767px) {
  #freccetta {
    display: none;
  }
}

</style>

<!-- Banner visivo -->
<div class="container-fluid hero-banner"></div>

<!-- Contenuto principale sotto il banner -->
<div class="text-center py-5">
  <h1 class="fw-bold text-warning">Benvenuto su Sians</h1>

  <div class="hero-buttons mt-4">
    <?php if (!isset($_SESSION['user_id'])): ?>
      <a href="register.php" class="btn btn-warning">Registrati</a>
      <a href="login.php" class="btn btn-light">Accedi</a>
    <?php else: ?>
      <a href="dashboard.php" class="btn btn-success">Vai alla Dashboard</a>
    <?php endif; ?>
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
