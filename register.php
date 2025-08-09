
<?php
require 'config/config.php';
require 'includes/header.php';

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$username || !$email || !$password) {
        $error = "Compila tutti i campi";
    } else {
        // Verifica se username o email esistono già
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetchColumn() > 0) {
            $error = "Username o email già registrati";
        } else {
            // Hash password e inserimento
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
            $stmt->execute([$username, $email, $password_hash]);
            $success = "Registrazione completata! Ora puoi <a href='login.php'>accedere</a>.";
        }
    }
}
?>
<header>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>

</header>
<div class="main-container">
  <div class="card-custom">
    <h2>Registrati</h2>

    <?php if ($error): ?>
      <div class="alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
      <div class="alert-success"><?= $success ?></div>
    <?php endif; ?>

    <form method="post">
      <label for="username" class="form-label">Username</label>
      <input name="username" id="username" class="form-control" required>

      <label for="email" class="form-label" style="margin-top:1rem;">Email</label>
      <input type="email" name="email" id="email" class="form-control" required>

      <label for="password" class="form-label" style="margin-top:1rem;">Password</label>
      <input type="password" name="password" id="password" class="form-control" required>

      <button type="submit" class="btn-custom" style="margin-top:1.5rem;">Registrati</button>
    </form>

    <div class="form-link">
      <p>Hai già un account? <a href="login.php">Accedi</a></p>
    </div>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
</body>
</html>