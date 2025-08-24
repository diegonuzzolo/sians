<?php
// FILE: verify_email.php (Versione Corretta e Pulita)

session_start();
require 'config/config.php';

$message = '';
$is_success = false;
$token = $_GET['token'] ?? '';

if (empty($token)) {
    $message = "Token di verifica mancante o non valido.";
} else {
    try {
        // Cerca un utente con il token fornito che non sia scaduto
        $stmt = $pdo->prepare(
            "SELECT id FROM users WHERE verification_token = ? AND token_expires_at > NOW()"
        );
        $stmt->execute([$token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $message = "Token non valido o scaduto. Prova a registrarti di nuovo.";
        } else {
            // Token valido, attiva l'account dell'utente
            $stmt_update = $pdo->prepare(
                "UPDATE users SET email_verified = 1, verification_token = NULL, token_expires_at = NULL WHERE id = ?"
            );
            $stmt_update->execute([$user['id']]);

            $message = "Email verificata con successo! Ora puoi effettuare il login.";
            $is_success = true;
        }
    } catch (PDOException $e) {
        $message = "Errore del database. Riprova più tardi.";
    }
}

// Includi l'header DOPO la logica PHP
require 'includes/header.php';
?>

<main class="flex items-center justify-center flex-grow p-4 bg-cover bg-center" style="background-image: url('assets/background.jpg');">
    <div class="w-full max-w-md bg-slate-800/70 rounded-3xl p-8 shadow-2xl backdrop-blur-xl border border-slate-700 text-center animate-fade-in">
        
        <?php if ($is_success): ?>
            <i class="bi bi-check-circle-fill text-6xl text-green-400 mb-4"></i>
            <h2 class="text-3xl font-bold text-white">Verifica Completata!</h2>
        <?php else: ?>
            <i class="bi bi-x-circle-fill text-6xl text-red-400 mb-4"></i>
            <h2 class="text-3xl font-bold text-white">Ops! Qualcosa è andato storto.</h2>
        <?php endif; ?>
        
        <p class="text-slate-300 mt-4 mb-8"><?= htmlspecialchars($message) ?></p>

        <?php if ($is_success): ?>
            <a href="login.php" class="w-full py-3 mt-2 bg-gradient-to-r from-cyan-500 to-blue-600 text-white font-bold rounded-full shadow-lg hover:shadow-cyan-500/50 transition-all duration-300 transform hover:scale-105 inline-block">
                Vai al Login
            </a>
        <?php else: ?>
            <a href="register.php" class="w-full py-3 mt-2 bg-gradient-to-r from-gray-600 to-gray-700 text-white font-bold rounded-full shadow-lg hover:shadow-gray-600/50 transition-all duration-300 transform hover:scale-105 inline-block">
                Torna alla Registrazione
            </a>
        <?php endif; ?>
    </div>
</main>

<?php
// Includi il footer per chiudere la pagina e aggiungere gli script
require 'includes/footer.php';