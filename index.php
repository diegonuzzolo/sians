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

<!-- Hero -->
<div class="container">
    <h6 style="text-shadow: 2px 2px 4px; color: black;" class="fw-bold">Crea il tuo Server Minecraft</h6>
</div>

<div id="banner" class="bg-dark text-white text-center py-5"
     style="height: 100vh; border-radius: 15px; background-image: url('assets/banner.png'); background-size: cover; background-position: center;">
    
    <div class="position-relative top-0 start-50 translate-middle-x ">
        <?php if (!isset($_SESSION['user_id'])): ?>
            <a href="register.php" class="btn btn-success btn-lg mt-3 me-2">Registrati</a>
            <a href="login.php" class="btn btn-outline-light btn-lg mt-3">Accedi</a>
        <?php else: ?>
            <a href="dashboard.php" class="btn btn-primary btn-lg mt-3">Vai alla Dashboard</a>
        <?php endif; ?>
    </div>
  
</div>

<script>
    // Script per cambiare il banner su mobile
    window.addEventListener("load", function () {
        const isMobile = window.innerWidth < 768;
        const banner = document.getElementById("banner");

        if (isMobile) {
            banner.style.backgroundImage = "url('assets/banner-mobile.png')";
        }
    });
</script>

<!-- Sezione slot -->
<div class="container my-5 text-center">
    <h2 class="mb-4">Slot Server Disponibili</h2>
    <div class="display-5 text-success fw-bold"><?= htmlspecialchars($slotDisponibili) ?></div>
    <p class="text-muted">Slot disponibili per creare nuovi server Minecraft.</p>
</div>

<!-- Sezione caratteristiche risorse VM -->
<div class="container my-5">
    <h2 class="mb-4 text-center">Caratteristiche del Server Minecraft</h2>
    <div class="row justify-content-center text-center">
        <div class="col-md-3 mb-4">
            <i class="bi bi-cpu-fill text-danger fs-1"></i>
            <h5>CPU</h5>
            <p class="fs-5 fw-bold"><?= $vmCores ?> Core virtuali dedicati</p>
        </div>
        <div class="col-md-3 mb-4">
            <i class="bi bi-memory text-primary fs-1"></i>
            <h5>RAM</h5>
            <p class="fs-5 fw-bold"><?= $vmRam ?> GB DDR4 dedicati</p>
        </div>
        <div class="col-md-3 mb-4">
            <i class="bi bi-hdd-fill text-success fs-1"></i>
            <h5>Storage</h5>
            <p class="fs-5 fw-bold">SSD NVMe <?= number_format($vmStorageSpeed, 0, ',', '.') ?> MB/s</p>
        </div>
    </div>
    <p class="text-center text-muted fst-italic">Risorse garantite per ogni macchina virtuale, per un'esperienza di gioco fluida e performante.</p>
</div>

<!-- Vantaggi -->
<div class="container my-5">
    <div class="row text-center">
        <div class="col-md-4 mb-4">
            <i class="bi bi-lightning-charge-fill text-warning fs-1"></i>
            <h5>Prestazioni Elevate</h5>
            <p>Server su macchine virtuali dedicate con risorse garantite.</p>
        </div>
        <div class="col-md-4 mb-4">
            <i class="bi bi-gear-fill text-info fs-1"></i>
            <h5>Controllo Totale</h5>
            <p>Avvia, spegni o elimina i tuoi server dalla dashboard.</p>
        </div>
        <div class="col-md-4 mb-4">
            <i class="bi bi-check-circle-fill text-success fs-1"></i>
            <h5>Facile da Usare</h5>
            <p>Interfaccia semplice e intuitiva per tutti i giocatori.</p>
        </div>
    </div>
</div>

<!-- Footer -->
<?php include 'includes/footer.php'; ?>
