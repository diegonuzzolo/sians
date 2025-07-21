<?php
require 'config/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$username || !$email || !$password) {
        die("Compila tutti i campi");
    }

    // Hash password con bcrypt
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    // Verifica se username o email esistono già
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $email]);
    if ($stmt->fetchColumn() > 0) {
        die("Username o email già registrati");
    }

    // Inserimento utente
    $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
    $stmt->execute([$username, $email, $password_hash]);

    echo "Registrazione completata!";
} else {
    // Mostra form HTML semplice
    ?>
    <form method="post">
      Username: <input name="username" required><br>
      Email: <input type="email" name="email" required><br>
      Password: <input type="password" name="password" required><br>
      <button type="submit">Registrati</button>
      <a href="login.php">Hai già un account? Accedi</a>

    </form>
    <?php
}
