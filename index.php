<?php
session_start();
require 'config/config.php';

// Conta gli slot liberi
$stmt = $pdo->query("SELECT COUNT(*) FROM minecraft_vms WHERE assigned_user_id IS NULL");
$slotDisponibili = $stmt->fetchColumn();


?>

<?php include 'includes/header.php'; ?>

<!-- Hero -->
<div class="bg-dark text-white  text-center py-5" style="border-radius: 15px; background-image: url('assets/minecraft-bg2.jpg'); background-size: cover; background-position: center;">
    <div class="container">
        <h1 class="display-4 fw-bold ">Crea il tuo Server Minecraft</h1>
        <i style="color: black;" class="bi-check-circle-fill text-success">Hosting veloce</i><br>
        <i style="color: black;" class="bi-check-circle-fill text-success">Semplice e automatico</i><br>
        <i style="color: black;" class="bi-check-circle-fill text-success">Altamente personalizzabile</i><br>


        <?php if (!isset($_SESSION['user_id'])): ?>
            <a href="register.php" class="btn btn-success btn-lg mt-3 me-2">Registrati</a>
            <a href="login.php" class="btn btn-outline-light btn-lg mt-3">Accedi</a>
            <?php else: ?>
                <a href="dashboard.php" class="btn btn-primary btn-lg mt-3">Vai alla Dashboard</a>
                <?php endif; ?>
            </div>
        </div>

<!-- Sezione slot -->
<div class="container my-5 text-center">
    <h2 class="mb-4">Slot Server Disponibili</h2>
    <div class="display-5 text-success fw-bold"><?= $slotDisponibili ?></div>
    <p class="text-muted">Slot disponibili per creare nuovi server Minecraft.</p>
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
