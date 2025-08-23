<?php
// Includi i file di configurazione e funzioni
// Assicurati che il percorso sia corretto rispetto alla posizione del file header
include __DIR__.'/../config/config.php';

// Avvia la sessione se non è già attiva
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Controlla se l'utente è loggato e se la password è scaduta
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT last_password_change FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $lastChange = $stmt->fetchColumn();

    if ($lastChange) {
        $daysSince = (new DateTime())->diff(new DateTime($lastChange))->days;
        if ($daysSince > 90) {
            header("Location: change_password.php?expired=1");
            exit;
        }
    }
}

// Imposta variabili per la visualizzazione nella navbar
$username_display = $_SESSION['username'] ?? 'Utente';
$user_logged_in = isset($_SESSION['user_id']);
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-6216576482936386"
     crossorigin="anonymous"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sians Hosting</title>
    <link rel="shortcut icon" href="assets/favicon.ico" type="image/x-icon">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"/>

    <style>
        /* Stili di base per il body per coerenza con il tema scuro */
        body {
            background-color: #0f172a; /* bg-slate-900 */
            color: #f1f5f9; /* text-slate-100 */
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-top: 4.5rem; /* Spazio per la navbar fissa, aggiusta se necessario */
        }
    </style>
</head>
<body class="min-h-screen flex flex-col">

<nav class="fixed top-0 left-0 right-0 z-50 bg-slate-900 shadow-lg">
    <div class="container mx-auto px-4">
        <div class="flex justify-between items-center py-4">
            <a class="text-white text-xl font-extrabold flex items-center gap-2" href="index.php">
                <i class="fa-brands fa-minecraft text-amber-400 text-2xl"></i>
                <span class="hidden sm:inline">Minecraft Bedrock Hosting</span>
                <span class="sm:hidden">Sians Hosting</span>
            </a>

            <div class="md:hidden">
                <button id="mobile-menu-button" class="text-white focus:outline-none">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7"/>
                    </svg>
                </button>
            </div>

            <div class="hidden md:flex items-center space-x-6">
                 <a href="dashboard.php" class="text-gray-300 hover:text-white flex items-center gap-2 transition duration-300">
                    <i class="fa-solid fa-gauge-high"></i> Dashboard
                </a>
                <a href="add_server.php" class="text-gray-300 hover:text-white flex items-center gap-2 transition duration-300">
                    <i class="fa-solid fa-plus-circle"></i> Acquista Server
                </a>
                <?php if ($user_logged_in): ?>
                    <div class="relative group">
                        <button class="text-gray-300 hover:text-white focus:outline-none flex items-center gap-2">
                            <i class="fa-solid fa-user-circle"></i> <?= htmlspecialchars($username_display) ?>
                            <svg class="h-4 w-4 transform transition-transform duration-200 group-hover:rotate-180" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                        </button>
                        <div class="absolute right-0 mt-2 w-48 bg-slate-800 rounded-lg shadow-lg py-2 opacity-0 group-hover:opacity-100 transform transition-all duration-300 scale-95 group-hover:scale-100 origin-top-right invisible group-hover:visible">
                            <a href="profile.php" class="block px-4 py-2 text-gray-300 hover:bg-slate-700 flex items-center gap-2"><i class="fa-solid fa-user-gear"></i> Profilo</a>
                            <div class="border-t border-gray-700 my-1"></div>
                            <a href="logout.php" class="block px-4 py-2 text-red-400 hover:bg-slate-700 flex items-center gap-2"><i class="fa-solid fa-sign-out-alt"></i> Logout</a>
                        </div>
                    </div>
                <?php else: ?>
                    <a href="login.php" class="text-gray-300 hover:text-white flex items-center gap-2 transition duration-300"><i class="fa-solid fa-right-to-bracket"></i> Accedi</a>
                    <a href="register.php" class="text-gray-300 hover:text-white flex items-center gap-2 transition duration-300"><i class="fa-solid fa-user-plus"></i> Registrati</a>
                <?php endif; ?>
            </div>
        </div>

        <div id="mobile-menu" class="hidden md:hidden pb-4">
             <a href="dashboard.php" class="block py-2 px-4 text-gray-300 hover:bg-slate-700 rounded flex items-center gap-2">
                <i class="fa-solid fa-gauge-high"></i> Dashboard
            </a>
            <a href="add_server.php" class="block py-2 px-4 text-gray-300 hover:bg-slate-700 rounded flex items-center gap-2">
                <i class="fa-solid fa-plus-circle"></i> Acquista Server
            </a>
            <div class="border-t border-gray-700 my-2"></div>

            <?php if ($user_logged_in): ?>
                <a href="profile.php" class="block py-2 px-4 text-gray-300 hover:bg-slate-700 rounded flex items-center gap-2"><i class="fa-solid fa-user-gear"></i> Profilo</a>
                <a href="logout.php" class="block py-2 px-4 text-red-400 hover:bg-slate-700 rounded flex items-center gap-2"><i class="fa-solid fa-sign-out-alt"></i> Logout</a>
            <?php else: ?>
                <a href="login.php" class="block py-2 px-4 text-gray-300 hover:bg-slate-700 rounded flex items-center gap-2"><i class="fa-solid fa-right-to-bracket"></i> Accedi</a>
                <a href="register.php" class="block py-2 px-4 text-gray-300 hover:bg-slate-700 rounded flex items-center gap-2"><i class="fa-solid fa-user-plus"></i> Registrati</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<script>
    // Seleziona il pulsante e il menu mobile
    const mobileMenuButton = document.getElementById('mobile-menu-button');
    const mobileMenu = document.getElementById('mobile-menu');

    // Aggiungi un evento 'click' al pulsante
    mobileMenuButton.addEventListener('click', () => {
        // Alterna la classe 'hidden' sul menu mobile per mostrarlo o nasconderlo
        mobileMenu.classList.toggle('hidden');
    });
</script>