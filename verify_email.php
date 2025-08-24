<?php
// FILE: verify_email.php (Versione Finale con Stile Aggiornato)

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
        <div id="google_translate_element">

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
                    </div>
                </main>

</body>
</html>