<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require 'config/config.php';
include 'includes/header.php';
// include 'includes/force_https.php';


// Variabile usata per storage speed nella descrizione
$vmStorageSpeed = 7500;
?>

<style>
  body {
    background: linear-gradient(to right, #0f172a, #1e293b);
    color: #f1f5f9;
    font-family: 'Segoe UI', sans-serif;
    margin: 0;
  }

  /* HERO BANNER */
  .hero-banner {
    position: relative;
    width: 95%;
    margin: 2rem auto;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 0 30px rgba(250, 204, 21, 0.7);
  }

  .hero-banner .banner-img {
    width: 100%;
    height: 55vh;
    object-fit: cover;
    filter: brightness(70%);
  }

  .hero-banner .banner-content {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    text-align: center;
    color: #f1f5f9;
    padding: 0 1rem;
    width: 100%;
  }

  .hero-banner h1 {
    font-size: 3rem;
    font-weight: 900;
    color: #facc15;
    text-shadow: 3px 3px 15px rgba(0, 0, 0, 0.7);
    margin-bottom: 0.5rem;
  }

  .hero-banner p {
    font-size: 1.4rem;
    color: #f3e8a9;
    max-width: 90%;
    margin: 0 auto 2rem;
    text-shadow: 1px 1px 6px rgba(0, 0, 0, 0.6);
  }

  /* BOTTONI HERO */
  .hero-buttons .btn {
    margin: 0.5rem;
    padding: 14px 30px;
    font-weight: 700;
    font-size: 1.2rem;
    border-radius: 14px;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    box-shadow: 0 5px 20px rgba(250, 204, 21, 0.6);
  }

  .hero-buttons .btn:hover {
    transform: scale(1.08);
    box-shadow: 0 8px 30px rgba(250, 204, 21, 0.9);
  }

  /* INFO BOX */
  .content-wrapper {
    max-width: 1200px;
    margin: 0 auto 4rem;
    text-align: center;
  }

  .info-box {
    background-color: #1e293b;
    border-radius: 14px;
    padding: 30px 20px;
    color: #f8fafc;
    box-shadow: 0 0 15px rgba(250, 204, 21, 0.4);
    transition: transform 0.25s ease, box-shadow 0.25s ease;
  }

  .info-box:hover {
    transform: scale(1.05);
    box-shadow: 0 0 30px rgba(250, 204, 21, 0.7);
  }

  .info-box i {
    font-size: 3rem;
    margin-bottom: 15px;
  }

  .text-gold {
    color: #facc15;
  }

  /* TABLET */
  @media (max-width: 992px) {
    .hero-banner h1 {
      font-size: 2.2rem;
    }

    .hero-banner p {
      font-size: 1.1rem;
    }
  }

  /* MOBILE */
  @media (max-width: 576px) {
    .hero-banner {
      width: 100%;
      border-radius: 0;
    }

    .hero-banner .banner-img {
      height: 35vh;
    }

    .hero-banner .banner-content {
      top: 55%;
      transform: translate(-50%, -50%);
    }

    .hero-banner h1 {
      font-size: 1.6rem;
      margin-bottom: 0.8rem;
    }

    .hero-banner p {
      font-size: 0.95rem;
      margin-bottom: 1rem;
    }

    .hero-buttons .btn {
      font-size: 1rem;
      padding: 10px 18px;
      width: auto;
      display: inline-block;
    }
  }

  /* Nuova sezione piano */
  .plan-section {
    background-color: #1a202c;
    padding: 3rem 1rem;
    border-radius: 16px;
    box-shadow: 0 0 30px rgba(250, 204, 21, 0.5);
    margin-top: 3rem;
    color: #f8fafc;
  }

  .plan-section h2 {
    color: #facc15;
    font-weight: 900;
    margin-bottom: 1.5rem;
  }

  .plan-list {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 1.5rem;
  }

  .plan-item {
    background-color: #334155;
    border-radius: 12px;
    flex: 1 1 280px;
    max-width: 320px;
    padding: 1.5rem;
    box-shadow: 0 0 15px rgba(250, 204, 21, 0.3);
    text-align: center;
  }

  .plan-item i {
    font-size: 2.5rem;
    margin-bottom: 0.75rem;
    color: #facc15;
  }

  .plan-item h5 {
    margin-bottom: 0.75rem;
    font-weight: 700;
  }

  .plan-item p {
    font-size: 0.95rem;
    line-height: 1.4;
  }
</style>

<main>
  <!-- Banner visivo -->
  <div class="hero-banner">
    <img src="assets/banner.png" alt="Minecraft Bedrock Hosting" class="banner-img" />
    
    <div class="banner-content">
      <h1>Hosting Minecraft Bedrock Edition</h1>
      
      <div class="hero-buttons">
        <?php if (!isset($_SESSION['user_id'])): ?>
          <a href="register.php" class="btn btn-warning">Registrati Ora</a>
          <a href="login.php" class="btn btn-light">Accedi</a>
        <?php else: ?>
          <a href="dashboard.php" class="btn btn-success">Vai alla Dashboard</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</main>

<!-- Contenuto principale sotto il banner -->
<div class="content-wrapper">
  <h2 class="text-gold mt-5 mb-4">Perché scegliere noi?</h2>
  <div class="row text-center g-4">
    <div class="col-12 col-md-4">
      <div class="info-box">
        <i class="bi bi-lightning-charge-fill text-warning"></i>
        <h5>Velocità</h5>
        <p>Server sempre reattivi grazie a container isolati e configurazioni ottimizzate.</p>
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

  <!-- Nuova sezione piano -->
  <section class="plan-section">
    <div class="plan-list">
      <div class="plan-item">
        <i class="bi bi-cpu-fill"></i>
        <h5>CPU e RAM dedicate</h5>
        <p>Processori a 6 GHz e RAM DDR5 dedicati al tuo server.</p>
      </div>
      <div class="plan-item">
        <i class="bi bi-hdd-fill"></i>
        <h5>Storage veloce NVMe</h5>
        <p>SSD NVMe con velocità fino a <?= number_format($vmStorageSpeed, 0, ',', '.') ?> MB/s per caricamenti rapidissimi.</p>
      </div>
      <div class="plan-item">
        <i class="bi bi-wifi"></i>
        <h5>Banda 300 Mbps garantita</h5>
        <p>Connessione internet ultra veloce e stabile con bassa latenza.</p>
      </div>
      <div class="plan-item">
        <i class="bi bi-shield-lock-fill"></i>
        <h5>Protezione DDoS</h5>
        <p>Sicurezza avanzata per proteggere il tuo server da attacchi esterni.</p>
      </div>
      <div class="plan-item">
        <i class="bi bi-server"></i>
        <h5>Backup automatici giornalieri</h5>
        <p>Backup completi e ripristino rapido per non perdere mai i tuoi dati.</p>
      </div>
      <div class="plan-item">
        <i class="bi bi-headset"></i>
        <h5>Supporto tecnico 24/7</h5>
        <p>Assistenza professionale sempre disponibile via chat, ticket o telefono.</p>
      </div>
      <div class="plan-item">
        <i class="bi bi-tools"></i>
        <h5>Gestione e aggiornamenti</h5>
        <p>Manutenzione, aggiornamenti e ottimizzazioni server incluse nel prezzo.</p>
      </div>
      <div class="plan-item">
        <i class="bi bi-layout-text-window-reverse"></i>
        <h5>Console Web e Dashboard</h5>
        <p>Accesso remoto sicuro per controllo completo e gestione intuitiva.</p>
      </div>
      <div class="plan-item">
        <i class="bi bi-graph-up"></i>
        <h5>Scalabilità semplice</h5>
        <p>Possibilità di aumentare CPU, RAM e banda quando vuoi.</p>
      </div>
      <div class="plan-item">
        <i class="bi bi-shield-check"></i>
        <h5>Uptime garantito 99.9%</h5>
        <p>Affidabilità elevata con infrastruttura ridondata e monitoraggio costante.</p>
      </div>
    </div>
  </section>
</div>

<?php include("includes/footer.php"); ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
