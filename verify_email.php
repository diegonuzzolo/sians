<?php
// FILE: verify_email.php

session_start();
require 'config/config.php';
require 'includes/header.php'; // Includi il tuo header per un layout coerente

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
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifica Email - Sians Hosting</title>
    </head>
<body class="bg-gray-900 text-white font-sans antialiased">
    <main class="flex items-center justify-center min-h-screen p-4">
        <div class="w-full max-w-md bg-gray-800 rounded-3xl p-8 shadow-2xl text-center">
            <?php if ($is_success): ?>
                <i class="bi bi-check-circle-fill text-5xl text-green-400 mb-4"></i>
                <h2 class="text-3xl font-bold text-white">Verifica Completata!</h2>
            <?php else: ?>
                <i class="bi bi-x-circle-fill text-5xl text-red-400 mb-4"></i>
                <h2 class="text-3xl font-bold text-white">Ops! Qualcosa è andato storto.</h2>
            <?php endif; ?>
            
            <p class="text-gray-300 mt-4 mb-6"><?= htmlspecialchars($message) ?></p>

            <?php if ($is_success): ?>
                <a href="login.php" class="w-full py-3 mt-4 bg-gradient-to-r from-blue-600 to-purple-600 text-white font-bold rounded-full shadow-lg hover:from-blue-700 hover:to-purple-700 transition-all duration-300 transform hover:scale-105 inline-block">
                    Vai al Login
                </a>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>