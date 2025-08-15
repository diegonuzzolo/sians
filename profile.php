<?php
require 'config/config.php';
require 'auth_check.php'; // redirect se non loggato

$user_id = $_SESSION['user_id'];

// Recupera dati utente
$stmt = $pdo->prepare("SELECT username, email, billing_name, billing_address, billing_country, billing_vat, last_password_change FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Aggiorna dati profilo
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $billing_name = trim($_POST['billing_name']);
    $billing_address = trim($_POST['billing_address']);
    $billing_country = trim($_POST['billing_country']);
    $billing_vat = trim($_POST['billing_vat']);

    $stmt = $pdo->prepare("UPDATE users SET email=?, billing_name=?, billing_address=?, billing_country=?, billing_vat=? WHERE id=?");
    $stmt->execute([$email, $billing_name, $billing_address, $billing_country, $billing_vat, $user_id]);

    $success = "Profilo aggiornato con successo!";
}
?>

<?php include "includes/header.php"; ?>

<div class="container py-5">
    <h1 class="mb-4">Profilo Utente</h1>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="post" class="card p-4 bg-dark text-light">
        <div class="mb-3">
            <label class="form-label">Username (non modificabile)</label>
            <input type="text" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" disabled>
        </div>

        <div class="mb-3">
            <label class="form-label">Email *</label>
            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" required>
        </div>

        <hr>
        <h4 class="mt-3">Dati di fatturazione</h4>

        <div class="mb-3">
            <label class="form-label">Nome / Ragione sociale</label>
            <input type="text" name="billing_name" class="form-control" value="<?= htmlspecialchars($user['billing_name']) ?>">
        </div>

        <div class="mb-3">
            <label class="form-label">Indirizzo</label>
            <textarea name="billing_address" class="form-control"><?= htmlspecialchars($user['billing_address']) ?></textarea>
        </div>

        <div class="mb-3">
            <label class="form-label">Paese</label>
            <input type="text" name="billing_country" class="form-control" value="<?= htmlspecialchars($user['billing_country']) ?>">
        </div>

        <div class="mb-3">
            <label class="form-label">Partita IVA (opzionale)</label>
            <input type="text" name="billing_vat" class="form-control" value="<?= htmlspecialchars($user['billing_vat']) ?>">
        </div>

        <button type="submit" class="btn btn-warning">Salva modifiche</button>
        <a href="change_password.php" class="btn btn-secondary ms-2">Cambia password</a>
    </form>
</div>

<?php include "includes/footer.php"; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>