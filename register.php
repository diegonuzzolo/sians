<?php
// FILE: register.php (Versione Aggiornata con Verifica Email)

session_start();
require 'config/config.php';
require 'includes/header.php';
require_once 'includes/csrf.php';
// Includi qui il tuo autoloader per PHPMailer se lo usi
// require 'vendor/autoload.php'; 

// Abilita error reporting (rimuovi in produzione)
ini_set('display_errors', 1);
error_reporting(E_ALL);

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

    // Validazione input
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
            // Genera hash della password e token di verifica
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $verification_token = bin2hex(random_bytes(32));
            $token_expires_at = (new DateTime('+1 hour'))->format('Y-m-d H:i:s'); // Token valido per 1 ora

            $pdo->beginTransaction();
            try {
                // Inserisci l'utente con stato non verificato
                $stmt = $pdo->prepare(
                    "INSERT INTO users (username, email, password_hash, email_verified, verification_token, token_expires_at) 
                     VALUES (?, ?, ?, 0, ?, ?)"
                );
                $stmt->execute([$username, $email, $password_hash, $verification_token, $token_expires_at]);

                // --- Invio Email di Verifica ---
                // NOTA: Questo usa la funzione mail() di PHP, che potrebbe non essere affidabile.
                // Si consiglia vivamente di usare una libreria come PHPMailer con un server SMTP.
                $verification_link = "https://sians.it/verify_email.php?token=" . $verification_token;
                $subject = "Verifica il tuo account su Sians Hosting";
                $message = "Ciao {$username},\n\n";
                $message .= "Grazie per esserti registrato! Per favore, clicca sul link qui sotto per attivare il tuo account:\n";
                $message .= $verification_link . "\n\n";
                $message .= "Il link scadrà tra un'ora.\n\nSaluti,\nIl Team di Sians Hosting";
                $headers = 'From: no-reply@sians.it' . "\r\n" .
                           'Reply-To: no-reply@sians.it' . "\r\n" .
                           'X-Mailer: PHP/' . phpversion();

                if (mail($email, $subject, $message, $headers)) {
                    $pdo->commit();
                    $success = "Registrazione quasi completata! Ti abbiamo inviato un'email per verificare il tuo account.";
                    $_POST = array(); // Pulisci i campi
                } else {
                    throw new Exception("Impossibile inviare l'email di verifica. Riprova più tardi.");
                }

            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Errore durante la registrazione: " . $e->getMessage();
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
    </head>
<body class="bg-gray-900 text-white font-sans antialiased">
    <main class="flex items-center justify-center min-h-screen p-4">
        </main>
</body>
</html>