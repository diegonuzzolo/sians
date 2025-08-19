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
            $error = "Nome utente o email non validi.";
        } elseif (!password_verify($password, $user['password_hash'])) {
            $error = "Password errata.";
        } else {
            // Login corretto
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            // Controllo cambio password periodico (90 giorni)
            $pwdChangedAt = $user['last_password_change'];
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

<main class="flex items-center justify-center min-h-screen bg-gray-900 p-4">
    <div class="w-full max-w-md bg-gray-800 rounded-3xl p-8 shadow-2xl backdrop-blur-md bg-opacity-70 border border-gray-700">
        <div class="text-center mb-6">
            <i class="bi bi-door-open-fill text-5xl text-blue-400 mb-2"></i>
            <h2 class="text-3xl font-bold text-white">Accedi</h2>
            <p class="text-gray-400 mt-2">Gestisci il tuo server con un solo click.</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="bg-red-500 text-white text-sm py-2 px-4 rounded-lg mb-4 text-center">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="post" novalidate class="space-y-4">
            <?= csrf_field() ?>
            <div>
                <label for="username" class="block text-gray-300 text-sm font-semibold mb-2">Username o Email</label>
                <input name="username" id="username" class="w-full p-3 rounded-lg bg-gray-700 text-white border border-gray-600 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>

            <div>
                <label for="password" class="block text-gray-300 text-sm font-semibold mb-2">Password</label>
                <input type="password" name="password" id="password" class="w-full p-3 rounded-lg bg-gray-700 text-white border border-gray-600 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200" required>
            </div>

            <button type="submit" class="w-full py-3 mt-4 bg-gradient-to-r from-blue-600 to-purple-600 text-white font-bold rounded-full shadow-lg hover:from-blue-700 hover:to-purple-700 transition-all duration-300 transform hover:scale-105">
                Accedi
            </button>
        </form>

        <div class="text-center mt-6 text-gray-400">
            <p>Non hai un account? <a href="register.php" class="text-blue-400 hover:text-blue-500 font-semibold transition-colors duration-200">Registrati</a></p>
        </div>
    </div>
</main>

<?php include 'includes/footer.php'; ?>
</body>
</html>