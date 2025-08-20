<?php
session_start();
require 'config/config.php';
require 'includes/header.php';
require_once 'includes/csrf.php';

// Abilita error reporting (rimuovi in produzione)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Reindirizza se già loggato
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_or_die();
    
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$username || !$email || !$password) {
        $error = "Compila tutti i campi.";
    } elseif (strlen($password) < 8) {
        $error = "La password deve contenere almeno 8 caratteri.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Indirizzo email non valido.";
    } else {
        // Verifica se username o email esistono già
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetchColumn() > 0) {
            $error = "Username o email già registrati.";
        } else {
            // Hash password e inserimento
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
            if ($stmt->execute([$username, $email, $password_hash])) {
                $success = "Registrazione completata! Ora puoi <a href='login.php' class='font-bold text-blue-400 hover:text-blue-500'>accedere</a>.";
                $_POST = array(); // Pulisci i campi
            } else {
                $error = "Errore durante la registrazione. Riprova più tardi.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrazione - Minecraft Panel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background-color: #121212;
            color: #fff;
        }
    </style>
</head>
<body class="bg-gray-900 text-white font-sans antialiased">

    <main class="flex items-center justify-center min-h-screen p-4">
        <div class="w-full max-w-md bg-gray-800 rounded-3xl p-8 shadow-2xl backdrop-blur-md bg-opacity-70 border border-gray-700">
            <div class="text-center mb-6">
                <i class="bi bi-person-plus-fill text-5xl text-blue-400 mb-2"></i>
                <h2 class="text-3xl font-bold text-white">Registrati</h2>
                <p class="text-gray-400 mt-2">Crea un account per gestire i tuoi server.</p>
            </div>

            <?php if ($error): ?>
                <div class="bg-red-500 text-white text-sm py-2 px-4 rounded-lg mb-4 text-center">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="bg-green-500 text-white text-sm py-2 px-4 rounded-lg mb-4 text-center">
                    <?= $success ?>
                </div>
            <?php endif; ?>

            <form method="post" class="space-y-4">
                <?= csrf_field() ?>
                <div>
                    <label for="username" class="block text-gray-300 text-sm font-semibold mb-2">Username</label>
                    <input name="username" id="username" class="w-full p-3 rounded-lg bg-gray-700 text-white border border-gray-600 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                </div>
                
                <div>
                    <label for="email" class="block text-gray-300 text-sm font-semibold mb-2">Email</label>
                    <input type="email" name="email" id="email" class="w-full p-3 rounded-lg bg-gray-700 text-white border border-gray-600 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>
                
                <div>
                    <label for="password" class="block text-gray-300 text-sm font-semibold mb-2">Password</label>
                    <input type="password" name="password" id="password" class="w-full p-3 rounded-lg bg-gray-700 text-white border border-gray-600 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200" required>
                </div>
                
                <button type="submit" class="w-full py-3 mt-4 bg-gradient-to-r from-blue-600 to-purple-600 text-white font-bold rounded-full shadow-lg hover:from-blue-700 hover:to-purple-700 transition-all duration-300 transform hover:scale-105">
                    Registrati
                </button>
            </form>
            
            <div class="text-center mt-6 text-gray-400">
                <p>Hai già un account? <a href="login.php" class="text-blue-400 hover:text-blue-500 font-semibold transition-colors duration-200">Accedi</a></p>
            </div>
        </div>
    </main>

</body>
</html>