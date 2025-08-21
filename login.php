<?php
// FILE: login.php (Versione Finale con Stile Aggiornato)

session_start();
require 'config/config.php';
require_once 'includes/csrf.php';

// Abilita error reporting (rimuovi in produzione)
ini_set('display_errors', 1);
error_reporting(E_ALL);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_or_die(); 

    $login = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$login || !$password) {
        $error = "Compila tutti i campi";
    } else {
        $stmt = $pdo->prepare(
            "SELECT id, username, password_hash, role, last_password_change, force_password_reset, email_verified
            FROM users WHERE username = ? OR email = ?"
        );
        $stmt->execute([$login, $login]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = "Nome utente o password non corretti.";
        } 
        elseif ($user['email_verified'] != 1) {
            $error = "Il tuo account non è stato attivato. Controlla la tua email per il link di verifica.";
        } 
        else {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            $pwdChangedAt = $user['last_password_change'];
            $needsReset = (bool)$user['force_password_reset'];
            $forceReset = $needsReset || !$pwdChangedAt || (time() - strtotime($pwdChangedAt)) > 90 * 24 * 3600;

            if ($forceReset) {
                $_SESSION['force_password_reset'] = true;
                header('Location: profile.php?reset_password=1');
                exit;
            }

            header("Location: dashboard.php");
            exit;
        }
    }
}

include 'includes/header.php';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Sians Hosting</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fade-in {
            animation: fadeIn 0.8s ease-out forwards;
        }
    </style>
</head>
<body class="bg-slate-900 text-slate-100 font-sans antialiased">

    <main class="flex items-center justify-center min-h-screen p-4 bg-cover bg-center" style="background-image: url('/path/to/your/minecraft-background.jpg');">
        <div class="w-full max-w-md bg-slate-800/70 rounded-3xl p-8 shadow-2xl backdrop-blur-xl border border-slate-700 animate-fade-in">
            
            <div class="text-center mb-8">
                <i class="bi bi-box-arrow-in-right text-5xl text-cyan-400 mb-3"></i>
                <h2 class="text-3xl font-bold text-white">Bentornato!</h2>
                <p class="text-slate-400 mt-2">Accedi per gestire i tuoi server.</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="bg-red-500/20 border border-red-500 text-red-300 text-sm py-2 px-4 rounded-lg mb-4 text-center animate-fade-in">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="post" class="space-y-6">
                <?= csrf_field() ?>
                <div>
                    <label for="username" class="block text-slate-300 text-sm font-semibold mb-2">Username o Email</label>
                    <div class="relative">
                        <i class="bi bi-person absolute top-1/2 left-3 transform -translate-y-1/2 text-slate-400"></i>
                        <input name="username" id="username" class="w-full pl-10 p-3 rounded-lg bg-slate-700/50 text-white border border-slate-600 focus:ring-2 focus:ring-cyan-500 focus:border-transparent transition-all duration-200" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" placeholder="Il tuo username o email">
                    </div>
                </div>

                <div>
                    <label for="password" class="block text-slate-300 text-sm font-semibold mb-2">Password</label>
                    <div class="relative">
                        <i class="bi bi-shield-lock absolute top-1/2 left-3 transform -translate-y-1/2 text-slate-400"></i>
                        <input type="password" name="password" id="password" class="w-full pl-10 p-3 rounded-lg bg-slate-700/50 text-white border border-slate-600 focus:ring-2 focus:ring-cyan-500 focus:border-transparent transition-all duration-200" required placeholder="La tua password">
                    </div>
                </div>

                <button type="submit" class="w-full py-3 mt-4 bg-gradient-to-r from-cyan-500 to-blue-600 text-white font-bold rounded-full shadow-lg hover:shadow-cyan-500/50 transition-all duration-300 transform hover:scale-105">
                    Accedi
                </button>
            </form>

            <div class="text-center mt-6 text-slate-400 text-sm">
                <p>Non hai un account? <a href="register.php" class="text-cyan-400 hover:text-cyan-300 font-semibold transition-colors duration-200">Registrati ora</a></p>
            </div>
        </div>
    </main>

</body>
</html>