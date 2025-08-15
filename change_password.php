<?php
require 'config/config.php';
require 'auth_check.php';
include 'includes/csrf.php';

csrf_check_or_die();
$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old_pass = $_POST['old_password'] ?? '';
    $new_pass = $_POST['new_password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';

    // Recupera password attuale
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id=?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($old_pass, $user['password_hash'])) {
        $error = "Password attuale errata.";
    } elseif ($new_pass !== $confirm_pass) {
        $error = "Le nuove password non coincidono.";
    } elseif (strlen($new_pass) < 8) {
        $error = "La nuova password deve contenere almeno 8 caratteri.";
    } else {
        $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password_hash=?, last_password_change=NOW() WHERE id=?");
        $stmt->execute([$new_hash, $user_id]);
        $success = "Password aggiornata con successo.";
    }
}

include "includes/header.php";
?>

<div class="container py-5">
    <h1 class="mb-4">Cambia Password</h1>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="post" class="card p-4 bg-dark text-light">
        <?= csrf_field() ?>
        <div class="mb-3">
            <label>Password attuale</label>
            <input type="password" name="old_password" class="form-control" required>
        </div>

        <div class="mb-3">
            <label>Nuova password</label>
            <input type="password" name="new_password" class="form-control" required>
        </div>

        <div class="mb-3">
            <label>Conferma nuova password</label>
            <input type="password" name="confirm_password" class="form-control" required>
        </div>

        <button type="submit" class="btn btn-warning">Aggiorna Password</button>
    </form>
</div>

<?php include "includes/footer.php"; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>