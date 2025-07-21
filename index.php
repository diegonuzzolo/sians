<?php
session_start();
require 'config/config.php';
require 'includes/auth.php';

// Conta gli slot liberi
$stmt = $pdo->query("SELECT COUNT(*) FROM minecraft_vms WHERE assigned_user_id IS NULL");
$slotDisponibili = $stmt->fetchColumn();


$stmt = $pdo->prepare("SELECT COUNT(*) FROM servers WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$mieiServer = $stmt->fetchColumn();
?>

<?php include 'includes/header.php'; ?>

<!-- Hero -->
<div class="bg-dark text-white text-center py-5" style="background-image: url('assets/minecraft-bg1.jpg'); background-size: cover; background-position: center;">
    <div class="container">
        <h1 class="display-4 fw-bold">Crea il tuo Server Minecraft</h1>
        <p class="lead">Hosting veloce, semplice e automatico. Altamente personalizzabile.</p>
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


<!-- Footer -->
<?php include 'includes/footer.php'; ?>
