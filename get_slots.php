<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include("auth_check.php");
require 'config/config.php';

$userId = $_SESSION['user_id'] ?? 0;

if (!$userId) {
    die("Accesso negato.");
}

// Slot server disponibili (non ancora associati a un server)
$stmtSlots = $pdo->prepare("
    SELECT ss.id, ss.status, p.name, p.price_eur, p.code as plan_code
    FROM server_slots ss
    JOIN plans p ON p.stripe_price_id = ss.plan_id
    WHERE ss.user_id = ? AND ss.server_id IS NULL AND ss.status = 'pending'
");
$stmtSlots->execute([$userId]);
$serverSlots = $stmtSlots->fetchAll(PDO::FETCH_ASSOC);

ob_start(); // Inizia a catturare l'output HTML
?>
<?php foreach ($serverSlots as $slot): ?>
    <div class="col-md-4 mb-3" id="slot-<?= $slot['id'] ?>">
        <div class="card card-server p-3 shadow">
            <div class="server-name">Slot disponibile</div>
            <div class="server-ip">Piano: <?= htmlspecialchars($slot['name']) ?></div>
            <span class="status-badge status-available">Disponibile</span>
            <div class="server-actions mt-2">
                <a href="new_server.php?plan_code=<?= $slot['plan_code'] ?>&slot_id=<?= $slot['id'] ?>" class="btn btn-primary btn-server w-100">Crea Server</a>
            </div>
        </div>
    </div>
<?php endforeach; ?>
<?php
$htmlContent = ob_get_clean(); // Termina la cattura e salva l'HTML
echo $htmlContent; // Invia l'HTML come risposta
?>
