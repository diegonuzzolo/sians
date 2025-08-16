<?php
session_start();
require 'config/config.php';
require_once 'includes/csrf.php';


// Abilita error reporting (rimuovi in produzione)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_or_die(); // controlla CSRF

    $login = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$login || !$password) {
        $error = "Compila tutti i campi";
    } else {
        // Recupera utente
        $stmt = $pdo->prepare("
            SELECT id, username, email, password_hash, role, last_password_change, force_password_reset
            FROM users
            WHERE username = ? OR email = ?
        ");
        $stmt->execute([$login, $login]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $error = "Utente non trovato";
        } elseif (!password_verify($password, $user['password_hash'])) {
            $error = "Password errata";
        } else {
            // Login corretto
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            // Controllo cambio password periodico (90 giorni)
            $pwdChangedAt = $user['password_changed_at'];
            $needsReset = (bool)$user['force_password_reset'];
            $forceReset = $needsReset || !$pwdChangedAt || (time() - strtotime($pwdChangedAt)) > 90*24*3600;

            if ($forceReset) {
                $_SESSION['force_password_reset'] = true;
                header('Location: profile.php?reset_password=1');
                exit;
            }

            // Login completato, vai alla dashboard
            header("Location: dashboard.php");
            exit;
        }
    }
}

// Ora includi l’header, dopo che NON c’è stato output
include 'includes/header.php';
?>

<main>
  <div class="main-container">
    <div class="card-custom">
      <h2>Accedi</h2>

      <?php if (!empty($error)): ?>
        <div class="alert-danger"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="post" novalidate>
        <?= csrf_field() ?>
        <label for="username" class="form-label">Username o Email</label>
        <input name="username" id="username" class="form-control" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">

        <label for="password" class="form-label" style="margin-top:1rem;">Password</label>
        <input type="password" name="password" id="password" class="form-control" required>

        <button type="submit" class="btn-custom" style="margin-top:1.5rem;">Accedi</button>
      </form>

      <div class="form-link">
        <p>Non hai un account? <a href="register.php">Registrati</a></p>
      </div>
    </div>
  </div>
</main>

<?php include 'includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
