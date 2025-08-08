<?php
session_start();
require 'config/config.php';

$stmt = $pdo->query("SELECT COUNT(*) FROM minecraft_vms WHERE assigned_user_id IS NULL");
$slotDisponibili = $stmt->fetchColumn();

// Risorse server Bedrock potenziate
$vmCores = 20;
$vmRam = 40;
$vmStorageSpeed = 7500;
?>

<?php include 'includes/header.php'; ?>

<style>
  body {
    background: linear-gradient(to right, #0f172a, #1e293b);
    color: #f1f5f9;
    font-family: 'Segoe UI', sans-serif;
  }

  .hero-banner {
    background-image:
      linear-gradient(to bottom, rgba(15,23,42,0.8), rgba(30,41,59,0.9)),
      url('assets/banner.png');
    background-repeat: no-repeat;
    background-position: center center;
    background-size: cover;
    width: 100%;
    max-width: 1800px;
    height: 450px;
    border-radius: 20px;
    padding: 0 2rem;
    display: flex;
    flex-direction: column;
    justify-content: center;
    text-align: center;
    margin: 3rem auto 2rem;
    box-shadow: 0 0 30px rgba(250, 204, 21, 0.7);
  }

  .hero-banner h1 {
    font-size: 3.8rem;
    font-weight: 900;
    color: #facc15;
    text-shadow: 3px 3px 15px rgba(0,0,0,0.7);
    margin-bottom: 0.5rem;
  }

  .hero-banner p {
    font-size: 1.6rem;
    color: #f3e8a9;
    max-width: 900px;
    margin: 0 auto 2rem;
    text-shadow: 1px 1px 6px rgba(0,0,0,0.6);
  }

  .hero-buttons .btn {
    margin: 0 1rem;
    padding: 16px 36px;
    font-weight: 700;
    font-size: 1.3rem;
    border-radius: 14px;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    box-shadow: 0 5px 20px rgba(250, 204, 21, 0.6);
  }

  .hero-buttons .btn:hover {
    transform: scale(1.1);
    box-shadow: 0 8px 30px rgba(250, 204, 21, 0.9);
  }

  .content-wrapper {
    max-width: 1200px;
    margin: 0 auto 4rem;
  }

  .info-box {
    background-color: #1e293b;
    border-radius: 14px;
    padding: 30px 20px;
    text-align: center;
    color: #f8fafc;
    box-shadow: 0 0 15px rgba(250, 204, 21, 0.4);
    transition: transform 0.25s ease, box-shadow 0.25s ease;
  }

  .info-box:hover {
    transform: scale(1.05);
    box-shadow: 0 0 30px rgba(250, 204, 21, 0.7);
  }

  .info-box i {
    font-size: 3.5rem;
    margin-bottom: 20px;
  }

  .text-gold {
    color: #facc15;
  }

  @media (max-width: 767px) {
    .hero-banner h1 {
      font-size: 2.5rem;
    }
    .hero-banner p {
      font-size: 1.1rem;
      padding: 0 1rem;
    }
    .hero-buttons .btn {
      font-size: 1.1rem;
      padding: 14px 28px;
      margin: 0.5rem;
      width: 90%;
      display: block;
    }
  }
</style>

<!-- Banner visivo -->
<div class="hero-banner">
  <h1>Hosting Minecraft Bedrock Edition</h1>
  <p>Server dedicati con <strong>20 core</strong> e <strong>40 GB RAM</strong> per performance eccezionali e zero lag. La scelta perfetta per grandi community Bedrock.</p>

  <div class="hero-buttons">
    <?php if (!isset($_SESSION['user_id'])): ?>
      <a href="register.php" class="btn btn-warning">Registrati Ora</a>
      <a href="login.php" class="btn btn-light">Accedi</a>
    <?php else: ?>
      <a href="dashboard.php" class="btn btn-success">Vai alla Dashboard</a>
    <?php endif; ?>
  </div>
</div>

<!-- Contenuto principale sotto il banner -->
<div class="content-wrapper text-center">
  <h2 class="text-gold mb-4">Slot Disponibili</h2>
  <div class="display-5 text-success fw-bold mb-5"><?= htmlspecialchars($slotDisponibili) ?></div>

  <h2 class="text-gold mb-5">Caratteristiche del server</h2>
  <div class="row justify-content-center g-4">
    <div class="col-12 col-md-4">
      <div class="info-box">
        <i class="bi bi-cpu-fill text-danger"></i>
        <h5>CPU</h5>
        <p><strong><?= $vmCores ?></strong> Core dedicati</p>
      </div>
    </div>
    <div class="col-12 col-md-4">
      <div class="info-box">
        <i class="bi bi-memory text-primary"></i>
        <h5>RAM</h5>
        <p><strong><?= $vmRam ?></strong> GB DDR5 dedicati</p>
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

  <h2 class="text-gold mt-5 mb-4">Perché scegliere noi?</h2>
  <div class="row text-center g-4">
    <div class="col-12 col-md-4">
      <div class="info-box">
        <i class="bi bi-lightning-charge-fill text-warning"></i>
        <h5>Velocità</h5>
        <p>Server sempre reattivi grazie a VM isolate e configurazioni ottimizzate.</p>
      </div>
    </div>
    <div class="col-12 col-md-4">
      <div class="info-box">
        <i class="bi bi-gear-fill text-info"></i>
        <h5>Controllo Totale</h5>
        <p>Gestisci facilmente i tuoi server con una dashboard intuitiva.</p>
      </div>
    </div>
    <div class="col-12 col-md-4">
      <div class="info-box">
        <i class="bi bi-check-circle-fill text-success"></i>
        <h5>Semplicità</h5>
        <p>Avvia, crea e personalizza il tuo mondo in pochi clic.</p>
      </div>
    </div>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
