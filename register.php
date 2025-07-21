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

<div class="container mt-5" style="max-width: 500px;">
    <h2 class="mb-4 text-center">Registrati</h2>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>

    <form method="post" class="bg-light p-4 rounded shadow-sm">
        <div class="mb-3">
            <label for="username" class="form-label">Username</label>
            <input name="username" id="username" class="form-control" required>
        </div>

        <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <input type="email" name="email" id="email" class="form-control" required>
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">Password</label>
            <input type="password" name="password" id="password" class="form-control" required>
        </div>

        <button type="submit" class="btn btn-primary w-100">Registrati</button>

        <div class="text-center mt-3">
            <a href="login.php">Hai già un account? Accedi</a>
        </div>
    </form>
</div>

<?php require 'includes/footer.php'; ?>
