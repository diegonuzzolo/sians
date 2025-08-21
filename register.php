<?php
// FILE: register.php (Versione Finale Completa)

session_start();

// Usa le dichiarazioni 'use' all'inizio del file per chiarezza
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Includi tutti i file necessari, l'autoloader di Composer è il più importante
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/csrf.php';

// Abilita error reporting per debug (da rimuovere in produzione)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Reindirizza se l'utente è già loggato
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

$error = null;
$success = null;

// --- Inizio Blocco Logica PHP ---
// Tutta la logica viene eseguita solo quando il form viene inviato
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_or_die();
    
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // 1. Validazione dell'input
    if (!$username || !$email || !$password) {
        $error = "Compila tutti i campi.";
    } elseif (strlen($password) < 8) {
        $error = "La password deve contenere almeno 8 caratteri.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Indirizzo email non valido.";
    } else {
        // 2. Controllo se l'utente esiste già
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetchColumn() > 0) {
            $error = "Username o email già registrati.";
        } else {
            // 3. Preparazione dei dati per il nuovo utente
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $verification_token = bin2hex(random_bytes(32));
            $token_expires_at = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');

            $pdo->beginTransaction();
            try {
                // 4. Inserimento dell'utente nel database
                $stmt = $pdo->prepare(
                    "INSERT INTO users (username, email, password_hash, email_verified, verification_token, token_expires_at) 
                     VALUES (?, ?, ?, 0, ?, ?)"
                );
                $stmt->execute([$username, $email, $password_hash, $verification_token, $token_expires_at]);

                // 5. Invio dell'email di verifica con PHPMailer
                $mail = new PHPMailer(true);
                
                // Impostazioni SMTP lette da config.php
                $mail->isSMTP();
                $mail->Host       = SMTP_HOST;
                $mail->SMTPAuth   = true;
                $mail->Username   = SMTP_USERNAME;
                $mail->Password   = SMTP_PASSWORD;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = SMTP_PORT;

                // Mittente e destinatario
                $mail->setFrom(SMTP_FROM_ADDRESS, SMTP_FROM_NAME);
                $mail->addAddress($email, $username);

                // Contenuto dell'email
                $verification_link = "https://sians.it/verify_email.php?token=" . $verification_token;
                $mail->isHTML(true);
                $mail->CharSet = 'UTF-8';
                $mail->Subject = 'Verifica il tuo account su Sians Hosting';
                $mail->Body    = "Ciao <b>{$username}</b>,<br><br>Grazie per esserti registrato! Clicca sul link qui sotto per attivare il tuo account:<br><br><a href='{$verification_link}' style='background-color:#2563eb;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;'>Attiva il mio Account</a><br><br>Il link scadrà tra un'ora.<br><br>Saluti,<br>Il Team di Sians Hosting";
                $mail->AltBody = "Ciao {$username},\n\nGrazie per esserti registrato! Copia e incolla questo link nel tuo browser per attivare il tuo account:\n{$verification_link}\n\nIl link scadrà tra un'ora.\n\nSaluti,\nIl Team di Sians Hosting";
                
                $mail->send();
                
                // Se l'email è stata inviata, conferma le modifiche al database
                $pdo->commit();
                $success = "Registrazione quasi completata! Controlla la tua email per il link di verifica.";
                $_POST = array(); // Pulisce i campi del form per sicurezza

            } catch (Exception $e) {
                // Se qualcosa va storto (inserimento DB o invio email), annulla tutto
                $pdo->rollBack();
                // Mostra un errore più dettagliato per il debug
                $error = "Errore durante la registrazione: " . $e->getMessage();
                // Per un debug ancora più approfondito, puoi loggare l'errore di PHPMailer
                // error_log("PHPMailer Error: " . $mail->ErrorInfo);
            }
        }
    }
}
// --- Fine Blocco Logica PHP ---

// Ora che tutta la logica è terminata, includiamo l'header che inizierà a stampare l'HTML
require 'includes/header.php';
?>

<main class="flex items-center justify-center min-h-screen p-4 bg-cover bg-center" style="background-image: url('/assets/images/minecraft-background.jpg');">
    <div class="w-full max-w-md bg-slate-800/70 rounded-3xl p-8 shadow-2xl backdrop-blur-xl border border-slate-700 animate-fade-in">
        
        <div class="text-center mb-8">
            <i class="bi bi-box-seam text-5xl text-cyan-400 mb-3"></i>
            <h2 class="text-3xl font-bold text-white">Crea il Tuo Account</h2>
            <p class="text-slate-400 mt-2">Unisciti a noi e lancia il tuo server in pochi minuti.</p>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-500/20 border border-red-500 text-red-300 text-sm py-2 px-4 rounded-lg mb-4 text-center animate-fade-in">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="bg-green-500/20 border border-green-500 text-green-300 text-sm py-2 px-4 rounded-lg mb-4 text-center animate-fade-in">
                <?= $success ?>
            </div>
        <?php endif; ?>

        <form method="post" class="space-y-6">
            <?= csrf_field() ?>
            <div>
                <label for="username" class="block text-slate-300 text-sm font-semibold mb-2">Username</label>
                <div class="relative">
                    <i class="bi bi-person absolute top-1/2 left-3 transform -translate-y-1/2 text-slate-400"></i>
                    <input name="username" id="username" class="w-full pl-10 p-3 rounded-lg bg-slate-700/50 text-white border border-slate-600 focus:ring-2 focus:ring-cyan-500 focus:border-transparent transition-all duration-200" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" placeholder="Il tuo nome giocatore">
                </div>
            </div>
            
            <div>
                <label for="email" class="block text-slate-300 text-sm font-semibold mb-2">Email</label>
                <div class="relative">
                    <i class="bi bi-envelope absolute top-1/2 left-3 transform -translate-y-1/2 text-slate-400"></i>
                    <input type="email" name="email" id="email" class="w-full pl-10 p-3 rounded-lg bg-slate-700/50 text-white border border-slate-600 focus:ring-2 focus:ring-cyan-500 focus:border-transparent transition-all duration-200" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="latua@email.com">
                </div>
            </div>
            
            <div>
                <label for="password" class="block text-slate-300 text-sm font-semibold mb-2">Password</label>
                <div class="relative">
                    <i class="bi bi-shield-lock absolute top-1/2 left-3 transform -translate-y-1/2 text-slate-400"></i>
                    <input type="password" name="password" id="password" class="w-full pl-10 p-3 rounded-lg bg-slate-700/50 text-white border border-slate-600 focus:ring-2 focus:ring-cyan-500 focus:border-transparent transition-all duration-200" required placeholder="Almeno 8 caratteri">
                </div>
            </div>
            
            <button type="submit" class="w-full py-3 mt-4 bg-gradient-to-r from-cyan-500 to-blue-600 text-white font-bold rounded-full shadow-lg hover:shadow-cyan-500/50 transition-all duration-300 transform hover:scale-105">
                Crea Account
            </button>
        </form>
        
        <div class="text-center mt-6 text-slate-400 text-sm">
            <p>Hai già un account? <a href="login.php" class="text-cyan-400 hover:text-cyan-300 font-semibold transition-colors duration-200">Accedi</a></p>
        </div>
    </div>
</main>
</body>
</html>